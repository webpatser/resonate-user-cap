<?php

namespace Webpatser\ResonateUserCap;

use Fledge\Async\Redis\RedisConfig;
use Webpatser\Resonate\Contracts\Connection;
use Webpatser\Resonate\Plugins\Contracts\ConnectionLifecycle;
use Webpatser\Resonate\Plugins\Contracts\ServerPlugin;
use Webpatser\Resonate\Plugins\Contracts\TickScheduler;
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
 * Resonate's built-in `max_connections` caps per *app*; this plugin caps
 * per *user*, with optional per-app overrides.
 */
class PresenceCapPlugin implements ConnectionLifecycle, ServerPlugin, TickScheduler
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
     * Identified users this node has counted: appId => set<userId>.
     *
     * @var array<string, array<string, true>>
     */
    protected array $tracked = [];

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
     * Handle a connection opening. Identity is not known yet, so do nothing.
     */
    public function onOpen(Connection $connection): void
    {
        //
    }

    /**
     * Identify a connection by its first presence subscription, and cap it.
     */
    public function onSubscribe(Connection $connection, Channel $channel): void
    {
        if ($this->counter === null || ! $this->isPresenceChannel($channel)) {
            return;
        }

        // Once a connection is identified, every later presence subscription
        // is just additional channels for the same user, not a new identity.
        if ($connection->hasState('cap.user')) {
            return;
        }

        $userId = $this->presenceUserId($connection, $channel);

        if ($userId === '') {
            return;
        }

        $appId = $connection->app()->id();
        $cap = $this->capFor($appId);

        // Count and add are one atomic Redis step: concurrent subscribes for
        // the same user can no longer all clear the check before any add lands.
        if (! $this->counter->tryAdd($appId, $userId, $connection->id(), $cap)) {
            $this->context->terminate($connection, 'pusher:error', [
                'code' => $this->errorCode,
                'message' => $this->errorMessage,
            ]);

            UserCapExceeded::dispatch($appId, $userId);

            return;
        }

        $connection->setState('cap.app', $appId);
        $connection->setState('cap.user', $userId);

        $this->tracked[$appId][$userId] = true;
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

        $this->counter->remove(
            (string) $connection->state('cap.app'),
            (string) $connection->state('cap.user'),
            $connection->id(),
        );

        $connection->forgetState('cap.app');
        $connection->forgetState('cap.user');
    }

    /**
     * Register the heartbeat tick that refreshes per-node TTLs.
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
     * Refresh every tracked user's key on this node; forget empty users.
     */
    protected function reconcile(): void
    {
        if ($this->counter === null) {
            return;
        }

        foreach ($this->tracked as $appId => $users) {
            foreach (array_keys($users) as $userId) {
                if (! $this->counter->refresh($appId, $userId)) {
                    unset($this->tracked[$appId][$userId]);
                }
            }

            if ($this->tracked[$appId] === []) {
                unset($this->tracked[$appId]);
            }
        }
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
     * The presence user id for a connection on a channel it just joined.
     */
    protected function presenceUserId(Connection $connection, Channel $channel): string
    {
        $member = $channel->connections()[$connection->id()] ?? null;

        return (string) ($member?->data('user_id') ?? '');
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
