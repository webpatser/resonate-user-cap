<?php

use Fledge\Async\Redis\RedisConfig;
use Fledge\Async\Stream\DnsSocketConnector;
use Fledge\Async\Stream\RetrySocketConnector;
use Illuminate\Support\Facades\Event;
use Predis\Client;
use Webpatser\Resonate\Contracts\ApplicationProvider;
use Webpatser\Resonate\Contracts\Logger;
use Webpatser\Resonate\Loggers\Log;
use Webpatser\Resonate\Plugins\MessageDisposition;
use Webpatser\Resonate\Plugins\PluginContext;
use Webpatser\Resonate\Protocols\Pusher\Contracts\ChannelManager;
use Webpatser\ResonateUserCap\Events\UserCapExceeded;
use Webpatser\ResonateUserCap\PresenceCapKeys;
use Webpatser\ResonateUserCap\PresenceCapPlugin;
use Webpatser\ResonateUserCap\Tests\Support\FakeConnection;
use Webpatser\ResonateUserCap\Tests\Support\FakeLogger;
use Webpatser\ResonateUserCap\UserConnectionCounter;

use function Fledge\Async\Redis\createRedisClient;
use function Fledge\Async\Stream\socketConnector;

/*
 * The counter failing must not switch the cap off. These tests point the
 * plugin at a port nothing listens on, so they need no Redis of their own.
 */

beforeEach(function () {
    $this->logger = new FakeLogger;

    useResonateLogger($this->logger);

    // Nothing listens on port 1, so every counter call fails to connect.
    config()->set('resonate-user-cap.connection.port', '1');

    // The default connector retries a refused connect with 2s and 4s
    // backoffs; a single attempt keeps each simulated outage instant.
    socketConnector(new DnsSocketConnector);
});

afterEach(function () {
    useResonateLogger(null);

    socketConnector(new RetrySocketConnector(new DnsSocketConnector));
});

/**
 * Swap the logger behind Resonate's static Log proxy.
 */
function useResonateLogger(?Logger $logger): void
{
    (new ReflectionProperty(Log::class, 'logger'))->setValue(null, $logger);
}

/**
 * A validly signed presence subscribe for a connection.
 *
 * @return array{event:string,data:array{channel:string,auth:string,channel_data:string}}
 */
function signedPresenceSubscribe(FakeConnection $connection, string $userId, string $channel = 'presence-room'): array
{
    $data = json_encode(['user_id' => $userId], flags: JSON_THROW_ON_ERROR);

    return [
        'event' => 'pusher:subscribe',
        'data' => [
            'channel' => $channel,
            'auth' => presenceAuth($connection->id(), $channel, $data),
            'channel_data' => $data,
        ],
    ];
}

/**
 * A counter on the given Redis port, keyed the way the plugin keys it.
 */
function counterOnPort(int $port): UserConnectionCounter
{
    return new UserConnectionCounter(
        createRedisClient(RedisConfig::fromUri("redis://127.0.0.1:{$port}/15", 1)),
        new PresenceCapKeys('cap-test'),
        PresenceCapKeys::nodeId(),
        90,
    );
}

/**
 * Replace the plugin's counter, to move it in and out of an outage.
 */
function swapCounter(PresenceCapPlugin $plugin, UserConnectionCounter $counter): void
{
    (new ReflectionProperty(PresenceCapPlugin::class, 'counter'))->setValue($plugin, $counter);
}

it('refuses a presence subscribe the counter cannot answer', function () {
    Event::fake([UserCapExceeded::class]);

    $app = app(ApplicationProvider::class)->findById('app-id');
    $context = new PluginContext(app(ChannelManager::class));

    $connection = new FakeConnection('sock-1', $app);
    $plugin = new PresenceCapPlugin;
    $disposition = null;

    runLoop(function () use ($plugin, $context, $connection, &$disposition) {
        $plugin->boot($context);

        $disposition = $plugin->onMessage($connection, signedPresenceSubscribe($connection, 'u-1'));
    });

    expect($disposition)->toBe(MessageDisposition::Rejected)
        ->and($connection->terminated)->toBeTrue()
        ->and($connection->hasState('cap.user'))->toBeFalse()
        ->and($connection->messages)->toHaveCount(1);

    $error = json_decode($connection->messages[0], associative: true);

    expect($error['event'])->toBe('pusher:error')
        ->and(json_decode($error['data'], associative: true))->toBe([
            'code' => 4301,
            'message' => 'Too many connections for this user',
        ]);

    // An outage is not a user over their cap; metrics must not count it as one.
    Event::assertNotDispatched(UserCapExceeded::class);
});

it('logs a counter outage once, not once per subscribe', function () {
    $app = app(ApplicationProvider::class)->findById('app-id');
    $context = new PluginContext(app(ChannelManager::class));

    $first = new FakeConnection('sock-1', $app);
    $second = new FakeConnection('sock-2', $app);
    $plugin = new PresenceCapPlugin;

    runLoop(function () use ($plugin, $context, $first, $second) {
        $plugin->boot($context);

        $plugin->onMessage($first, signedPresenceSubscribe($first, 'u-1'));
        $plugin->onMessage($second, signedPresenceSubscribe($second, 'u-2'));
    });

    expect($first->terminated)->toBeTrue()
        ->and($second->terminated)->toBeTrue()
        ->and($this->logger->errors)->toHaveCount(1)
        ->and($this->logger->errors[0])->toContain('refusing presence subscribes');
});

it('admits and tracks a subscribe the counter cannot answer when fail_open is set', function () {
    config()->set('resonate-user-cap.fail_open', true);

    $app = app(ApplicationProvider::class)->findById('app-id');
    $context = new PluginContext(app(ChannelManager::class));

    $connection = new FakeConnection('sock-1', $app);
    $plugin = new PresenceCapPlugin;
    $disposition = null;

    runLoop(function () use ($plugin, $context, $connection, &$disposition) {
        $plugin->boot($context);

        $disposition = $plugin->onMessage($connection, signedPresenceSubscribe($connection, 'u-1'));
    });

    // Tracked, so the first heartbeat after Redis recovers writes it in.
    expect($disposition)->toBe(MessageDisposition::Relay)
        ->and($connection->terminated)->toBeFalse()
        ->and($connection->state('cap.user'))->toBe('u-1')
        ->and($this->logger->errors)->toHaveCount(1)
        ->and($this->logger->errors[0])->toContain('admitting uncounted');
});

it('logs each outage once and enforces the cap again in between', function () {
    if (! redisReachable()) {
        $this->markTestSkipped('Redis not reachable');
    }

    $redis = new Client(['host' => '127.0.0.1', 'port' => 6379, 'database' => 15]);

    foreach ($redis->keys('cap-test:*') as $key) {
        $redis->del($key);
    }

    $app = app(ApplicationProvider::class)->findById('app-id');
    $context = new PluginContext(app(ChannelManager::class));

    $during = new FakeConnection('sock-1', $app);
    $after = new FakeConnection('sock-2', $app);
    $again = new FakeConnection('sock-3', $app);
    $plugin = new PresenceCapPlugin;
    $dispositions = [];

    runLoop(function () use ($plugin, $context, $during, $after, $again, &$dispositions) {
        $plugin->boot($context);

        $dispositions[] = $plugin->onMessage($during, signedPresenceSubscribe($during, 'u-1'));

        swapCounter($plugin, counterOnPort(6379));
        $dispositions[] = $plugin->onMessage($after, signedPresenceSubscribe($after, 'u-1'));

        swapCounter($plugin, counterOnPort(1));
        $dispositions[] = $plugin->onMessage($again, signedPresenceSubscribe($again, 'u-1'));
    });

    foreach ($redis->keys('cap-test:*') as $key) {
        $redis->del($key);
    }

    expect($dispositions)->toBe([
        MessageDisposition::Rejected,
        MessageDisposition::Relay,
        MessageDisposition::Rejected,
    ])
        ->and($after->state('cap.user'))->toBe('u-1')
        ->and($this->logger->errors)->toHaveCount(2)
        ->and($this->logger->infos)->toHaveCount(1)
        ->and($this->logger->infos[0])->toContain('Redis answers again');
});
