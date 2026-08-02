<?php

namespace Webpatser\ResonateUserCap;

use Fledge\Async\Redis\RedisConfig;
use Illuminate\Support\Str;
use JsonException;
use Webpatser\Resonate\Contracts\Connection;
use Webpatser\Resonate\Plugins\Contracts\ConnectionLifecycle;
use Webpatser\Resonate\Plugins\Contracts\MessageInterceptor;
use Webpatser\Resonate\Plugins\Contracts\ServerPlugin;
use Webpatser\Resonate\Plugins\Contracts\TickScheduler;
use Webpatser\Resonate\Plugins\MessageDisposition;
use Webpatser\Resonate\Plugins\PluginContext;
use Webpatser\Resonate\Protocols\Pusher\Channels\Channel;
use Webpatser\ResonateUserCap\Events\UserCapExceeded;

use function Fledge\Async\Redis\createRedisClient;

/**
 * Caps the cluster-wide connection count per presence user_id.
 *
 * Identity comes from the first presence subscription a connection makes,
 * because that is the first signal carrying the `user_id`. Once a connection
 * is identified, it is counted in a {@see UserConnectionCounter}; if adding
 * it would exceed the cap, the connection is terminated with a Pusher error
 * frame instead. Connections that never subscribe to a presence channel are
 * never counted and never capped.
 *
 * The cap is applied on the inbound `pusher:subscribe` message, before the
 * subscription is established. Enforcing it afterwards (from `onSubscribe`)
 * meant an over-cap connection had already joined the channel and been sent
 * `subscription_succeeded` with the full presence member list before it was
 * closed, so a capped user could reconnect in a loop to snapshot who is
 * online. Nothing reaches the client now.
 *
 * Resonate's built-in `max_connections` caps per *app*; this plugin caps
 * per *user*, with optional per-app overrides.
 */
class PresenceCapPlugin implements ConnectionLifecycle, MessageInterceptor, ServerPlugin, TickScheduler
{
    /**
     * The server API surface handed in at boot.
     */
    protected PluginContext $context;

    /**
     * The cluster-wide counter.
     */
    protected ?UserConnectionCounter $counter = null;

    /**
     * Default cap, applied when no per-app override is set. 0 disables capping.
     */
    protected int $defaultCap;

    /**
     * Per-app cap overrides, keyed by app id.
     *
     * @var array<string, int>
     */
    protected array $perApp;

    /**
     * Seconds between heartbeat ticks.
     */
    protected float $heartbeat;

    /**
     * Pusher error code sent before terminating an over-cap connection.
     */
    protected int $errorCode;

    /**
     * Pusher error message sent before terminating an over-cap connection.
     */
    protected string $errorMessage;

    /**
     * The connections this node has counted: appId => userId => socketId => connection.
     *
     * This is what the node believes it holds, and the heartbeat writes it
     * into Redis verbatim. Entries are dropped in {@see onClose()} before the
     * Redis decrement is attempted, so a decrement that fails cannot leave
     * this registry claiming a socket that is gone.
     *
     * @var array<string, array<string, array<string, Connection>>>
     */
    protected array $tracked = [];

    /**
     * Presence channels holding counted connections: appId => channel => true.
     *
     * {@see PluginContext} has no "every connection on this node" lookup, so
     * the heartbeat reads the live membership of these channels to cross-check
     * the registry above.
     *
     * @var array<string, array<string, true>>
     */
    protected array $presenceChannels = [];

    /**
     * Boot the plugin: open the Redis client and build the counter.
     */
    public function boot(PluginContext $context): void
    {
        $this->context = $context;

        $config = config('resonate-user-cap', []);

        $this->defaultCap = (int) ($config['default'] ?? 5);
        $this->perApp = $config['per_app'] ?? [];
        $this->heartbeat = (float) ($config['heartbeat_interval'] ?? 30.0);
        $this->errorCode = (int) ($config['error_code'] ?? 4301);
        $this->errorMessage = (string) ($config['error_message'] ?? 'Too many connections for this user');

        $this->counter = new UserConnectionCounter(
            createRedisClient($this->makeConfig($config['connection'] ?? [])),
            new PresenceCapKeys($config['key_prefix'] ?? 'cap'),
            PresenceCapKeys::nodeId(),
            (int) ($config['ttl'] ?? 90),
        );
    }

    /**
     * Apply the cap to an inbound presence subscribe, before it is honoured.
     *
     * The signature is verified here rather than trusting the `channel_data`
     * as sent: at this point Resonate has not yet checked the presence auth,
     * and counting an unverified `user_id` would let any client burn another
     * user's cap slots just by claiming their identity. An invalid signature
     * is relayed untouched so the normal subscribe path rejects it exactly as
     * it always has.
     *
     * A refused subscribe is answered with the Pusher error frame, closed and
     * consumed, so the subscription is never established.
     *
     * A connection is counted here, one step before the subscription itself
     * succeeds. If Resonate then refuses the subscribe for its own reasons (a
     * subscription limit, say), the socket stays counted until it closes. That
     * errs towards over-counting the user who owns the socket, which is the
     * safe direction for a cap.
     *
     * @param  array{event:string,channel?:string,data?:mixed}  $event
     */
    public function onMessage(Connection $from, array $event): MessageDisposition
    {
        if ($this->counter === null || $event['event'] !== 'pusher:subscribe') {
            return MessageDisposition::Relay;
        }

        $payload = $event['data'] ?? null;

        if (! is_array($payload)) {
            return MessageDisposition::Relay;
        }

        $channel = $payload['channel'] ?? null;
        $auth = $payload['auth'] ?? null;
        $channelData = $payload['channel_data'] ?? null;

        // Only a signed presence subscribe carries an identity to cap. Anything
        // else (a public or private channel, a malformed payload) is not ours,
        // so it routes normally and Resonate validates it as it always has.
        if (! is_string($channel) || ! str_starts_with($channel, 'presence-')) {
            return MessageDisposition::Relay;
        }

        if (! is_string($auth) || ! is_string($channelData)) {
            return MessageDisposition::Relay;
        }

        if (! $this->signatureIsValid($from, $channel, $auth, $channelData)) {
            return MessageDisposition::Relay;
        }

        // Once a connection is identified, every later presence subscription
        // is just additional channels for the same user, not a new identity.
        if ($from->hasState('cap.user')) {
            return MessageDisposition::Relay;
        }

        $userId = $this->userIdFrom($channelData);

        if ($userId === '') {
            return MessageDisposition::Relay;
        }

        $appId = $from->app()->id();

        // Count and add are one atomic Redis step: concurrent subscribes for
        // the same user can no longer all clear the check before any add lands.
        if (! $this->counter->tryAdd($appId, $userId, $from->id(), $this->capFor($appId))) {
            $this->context->terminate($from, 'pusher:error', [
                'code' => $this->errorCode,
                'message' => $this->errorMessage,
            ]);

            UserCapExceeded::dispatch($appId, $userId);

            return MessageDisposition::Rejected;
        }

        $from->setState('cap.app', $appId);
        $from->setState('cap.user', $userId);

        $this->tracked[$appId][$userId][$from->id()] = $from;

        return MessageDisposition::Relay;
    }

    /**
     * Handle a connection opening. Identity is not known yet, so do nothing.
     */
    public function onOpen(Connection $connection): void
    {
        //
    }

    /**
     * Remember which presence channels hold counted connections.
     *
     * The cap itself is applied in {@see onMessage()}, before the subscription
     * exists. All this hook does is give the heartbeat a channel list to read
     * live membership from.
     */
    public function onSubscribe(Connection $connection, Channel $channel): void
    {
        if (! $this->isPresenceChannel($channel) || ! $connection->hasState('cap.user')) {
            return;
        }

        $this->presenceChannels[(string) $connection->state('cap.app')][$channel->name()] = true;
    }

    /**
     * The cap is per connection, not per channel, so leaving a single channel
     * does not change the count. Nothing to do here.
     */
    public function onUnsubscribe(Connection $connection, Channel $channel): void
    {
        //
    }

    /**
     * Decrement the user's count when an identified connection closes.
     */
    public function onClose(Connection $connection): void
    {
        if ($this->counter === null || ! $connection->hasState('cap.user')) {
            return;
        }

        $appId = (string) $connection->state('cap.app');
        $userId = (string) $connection->state('cap.user');

        // Forget the connection locally first. The plugin manager swallows an
        // exception thrown out of onClose, so a Redis decrement that fails must
        // not take the registry update down with it: the registry is what the
        // next heartbeat writes into Redis, and that is what repairs the count.
        unset($this->tracked[$appId][$userId][$connection->id()]);

        $connection->forgetState('cap.app');
        $connection->forgetState('cap.user');

        $this->counter->remove($appId, $userId, $connection->id());
    }

    /**
     * Register the heartbeat tick that reconciles this node's state.
     *
     * @return array<int, array{interval: float, callback: callable():void}>
     */
    public function ticks(): array
    {
        return [
            [
                'interval' => $this->heartbeat,
                'callback' => fn () => $this->reconcile(),
            ],
        ];
    }

    /**
     * Rebuild every tracked user's set from the connections this node holds.
     *
     * This is the authoritative pass, the same shape as the roster plugin's
     * heartbeat. It used to only refresh the TTL of a non-empty set, which
     * kept a stale socket id alive forever whenever a decrement was lost: a
     * user capped at 5 stayed capped at 4 until the node restarted. Now
     * anything Redis holds that this node no longer holds is removed, anything
     * live that is missing is added back, and users with nothing left are
     * dropped from Redis and from the registry.
     */
    protected function reconcile(): void
    {
        if ($this->counter === null) {
            return;
        }

        foreach ($this->tracked as $appId => $users) {
            $live = $this->liveSocketsByUser($appId);

            foreach ($users as $userId => $connections) {
                $sockets = array_values(array_unique([
                    ...array_map(strval(...), array_keys($connections)),
                    ...($live[$userId] ?? []),
                ]));

                if (! $this->counter->sync($appId, $userId, $sockets)) {
                    unset($this->tracked[$appId][$userId]);
                }
            }

            if ($this->tracked[$appId] === []) {
                unset($this->tracked[$appId], $this->presenceChannels[$appId]);
            }
        }
    }

    /**
     * The live presence sockets on this node for an app, grouped by user id.
     *
     * Read from the channel registry, so it reflects what Resonate itself
     * still has subscribed rather than what this plugin believes. The union of
     * this and the local registry is what gets written to Redis: the registry
     * keeps a connection counted while it holds no presence subscription
     * (identity survives an unsubscribe, as documented), and this side restores
     * anything the registry never learned about.
     *
     * @return array<string, list<string>>
     */
    protected function liveSocketsByUser(string $appId): array
    {
        $live = [];

        foreach (array_keys($this->presenceChannels[$appId] ?? []) as $name) {
            // PHP coerces numeric-string array keys to int, so the channel name
            // is restored to a string before it is looked up.
            $connections = $this->context->connectionsOn($appId, (string) $name);

            if ($connections === []) {
                unset($this->presenceChannels[$appId][$name]);

                continue;
            }

            foreach ($connections as $channelConnection) {
                $userId = (string) ($channelConnection->data('user_id') ?? '');

                if ($userId !== '') {
                    $live[$userId][] = $channelConnection->connection()->id();
                }
            }
        }

        return $live;
    }

    /**
     * Determine whether a subscribe carries a valid Pusher auth signature.
     *
     * Mirrors the check Resonate performs when the subscription is honoured,
     * so the two can never disagree about whether an identity was proven.
     */
    protected function signatureIsValid(Connection $connection, string $channel, string $auth, string $channelData): bool
    {
        return hash_equals(
            hash_hmac(
                'sha256',
                "{$connection->id()}:{$channel}:{$channelData}",
                $connection->app()->secret(),
            ),
            Str::after($auth, ':'),
        );
    }

    /**
     * The presence user id carried by a signed `channel_data` payload.
     */
    protected function userIdFrom(string $channelData): string
    {
        try {
            $decoded = json_decode($channelData, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return '';
        }

        if (! is_array($decoded) || ! is_scalar($decoded['user_id'] ?? null)) {
            return '';
        }

        return (string) $decoded['user_id'];
    }

    /**
     * The cap that applies to an application.
     */
    protected function capFor(string $appId): int
    {
        return (int) ($this->perApp[$appId] ?? $this->defaultCap);
    }

    /**
     * Determine whether a channel is a presence channel.
     */
    protected function isPresenceChannel(Channel $channel): bool
    {
        return str_starts_with($channel->name(), 'presence-');
    }

    /**
     * Build the fledge-fiber Redis configuration from the connection config.
     *
     * @param  array<string, mixed>  $server
     */
    protected function makeConfig(array $server): RedisConfig
    {
        $timeout = (float) ($server['timeout'] ?? RedisConfig::DEFAULT_TIMEOUT);

        if (! empty($server['url'])) {
            return RedisConfig::fromUri($server['url'], $timeout);
        }

        $host = $server['host'] ?? '127.0.0.1';
        $port = $server['port'] ?? 6379;
        $database = $server['database'] ?? 0;

        $userInfo = '';

        if (! empty($server['password'])) {
            $userInfo = rawurlencode((string) ($server['username'] ?? ''))
                .':'.rawurlencode((string) $server['password']).'@';
        }

        return RedisConfig::fromUri(
            sprintf('redis://%s%s:%s/%s', $userInfo, $host, $port, $database),
            $timeout,
        );
    }
}
