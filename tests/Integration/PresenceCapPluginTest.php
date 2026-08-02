<?php

use Illuminate\Support\Facades\Event;
use Predis\Client;
use Webpatser\Resonate\Contracts\ApplicationProvider;
use Webpatser\Resonate\Plugins\MessageDisposition;
use Webpatser\Resonate\Plugins\PluginContext;
use Webpatser\Resonate\Protocols\Pusher\Contracts\ChannelManager;
use Webpatser\ResonateUserCap\Events\UserCapExceeded;
use Webpatser\ResonateUserCap\PresenceCapKeys;
use Webpatser\ResonateUserCap\PresenceCapPlugin;
use Webpatser\ResonateUserCap\Tests\Support\FakeConnection;

beforeEach(function () {
    if (! redisReachable()) {
        $this->markTestSkipped('Redis not reachable');
    }

    $this->redis = new Client(['host' => '127.0.0.1', 'port' => 6379, 'database' => 15]);

    foreach ($this->redis->keys('cap-test:*') as $key) {
        $this->redis->del($key);
    }
});

afterEach(function () {
    if (isset($this->redis)) {
        foreach ($this->redis->keys('cap-test:*') as $key) {
            $this->redis->del($key);
        }
    }
});

/**
 * Build the inbound pusher:subscribe message for a presence channel.
 *
 * Shaped the way the server hands it to an interceptor: the outer `data` is
 * already decoded, while `channel_data` is still the raw JSON string the
 * signature was computed over.
 *
 * @return array{event:string,data:array{channel:string,auth:string,channel_data:string}}
 */
function subscribeMessage(FakeConnection $connection, string $channel, string $userId, string $secret = 'app-secret'): array
{
    $data = json_encode(['user_id' => $userId], flags: JSON_THROW_ON_ERROR);

    return [
        'event' => 'pusher:subscribe',
        'data' => [
            'channel' => $channel,
            'auth' => presenceAuth($connection->id(), $channel, $data, $secret),
            'channel_data' => $data,
        ],
    ];
}

/**
 * Drive a presence subscribe through the full server flow.
 *
 * The plugin intercepts the inbound message first; only when it relays does
 * the subscription actually happen and the lifecycle hook fire. That ordering
 * is the point of the cap living in the interceptor, so the tests exercise it
 * rather than calling the hooks directly.
 */
function capSubscribe(PresenceCapPlugin $plugin, FakeConnection $connection, string $channelName, string $userId): MessageDisposition
{
    $message = subscribeMessage($connection, $channelName, $userId);

    $disposition = $plugin->onMessage($connection, $message);

    if ($disposition !== MessageDisposition::Relay) {
        return $disposition;
    }

    $app = app(ApplicationProvider::class)->findById('app-id');
    $channel = app(ChannelManager::class)->for($app)->findOrCreate($channelName);

    $channel->subscribe($connection, $message['data']['auth'], $message['data']['channel_data']);

    $plugin->onSubscribe($connection, $channel);

    return $disposition;
}

/**
 * This node's set key for a user, as the plugin writes it.
 */
function nodeKey(string $userId, string $appId = 'app-id'): string
{
    return (new PresenceCapKeys('cap-test'))->userKey($appId, $userId, PresenceCapKeys::nodeId());
}

it('counts a connection on its first presence subscription', function () {
    $app = app(ApplicationProvider::class)->findById('app-id');
    $context = new PluginContext(app(ChannelManager::class));

    $connection = new FakeConnection('sock-1', $app);
    $plugin = new PresenceCapPlugin;

    runLoop(function () use ($plugin, $context, $connection) {
        $plugin->boot($context);
        capSubscribe($plugin, $connection, 'presence-room-'.uniqid(), 'u-1');
    });

    expect($connection->terminated)->toBeFalse()
        ->and($connection->hasState('cap.user'))->toBeTrue()
        ->and($connection->state('cap.user'))->toBe('u-1')
        ->and($this->redis->keys('cap-test:app-id:u-1:*'))->not->toBe([]);
});

it('rejects an over-cap subscribe before the subscription is established', function () {
    Event::fake([UserCapExceeded::class]);

    // Default cap in the test config is 2.
    $app = app(ApplicationProvider::class)->findById('app-id');
    $context = new PluginContext(app(ChannelManager::class));

    $first = new FakeConnection('sock-1', $app);
    $second = new FakeConnection('sock-2', $app);
    $third = new FakeConnection('sock-3', $app);

    $room = 'presence-room-'.uniqid();
    $disposition = null;

    $plugin = new PresenceCapPlugin;

    runLoop(function () use ($plugin, $context, $first, $second, $third, $room, &$disposition) {
        $plugin->boot($context);
        capSubscribe($plugin, $first, $room, 'u-1');
        capSubscribe($plugin, $second, $room, 'u-1');
        $disposition = capSubscribe($plugin, $third, $room, 'u-1');
    });

    $members = app(ChannelManager::class)->for($app)->find($room)?->connections() ?? [];

    // Consumed, so the subscribe never routed: the connection never joined the
    // channel, so it was never sent the presence member list and the members
    // were never told it arrived. The only frame it got is the error.
    expect($disposition)->toBe(MessageDisposition::Rejected)
        ->and($third->terminated)->toBeTrue()
        ->and($first->terminated)->toBeFalse()
        ->and($second->terminated)->toBeFalse()
        ->and(array_keys($members))->toBe(['sock-1', 'sock-2'])
        ->and($third->hasState('cap.user'))->toBeFalse();

    $events = array_map(
        fn (string $message) => json_decode($message, associative: true)['event'],
        $third->messages,
    );

    expect($events)->toBe(['pusher:error']);

    $error = json_decode($third->messages[0], associative: true);

    expect(json_decode($error['data'], associative: true))->toBe([
        'code' => 4301,
        'message' => 'Too many connections for this user',
    ]);

    Event::assertDispatchedTimes(UserCapExceeded::class, 1);
    Event::assertDispatched(UserCapExceeded::class, function (UserCapExceeded $event) {
        return $event->appId === 'app-id' && $event->userId === 'u-1';
    });
});

it('never counts a subscribe whose auth signature does not verify', function () {
    $app = app(ApplicationProvider::class)->findById('app-id');
    $context = new PluginContext(app(ChannelManager::class));

    $connection = new FakeConnection('sock-1', $app);
    $plugin = new PresenceCapPlugin;
    $disposition = null;

    runLoop(function () use ($plugin, $context, $connection, &$disposition) {
        $plugin->boot($context);

        // A client claiming someone else's user_id without the secret must not
        // be able to burn that user's cap slots.
        $disposition = $plugin->onMessage(
            $connection,
            subscribeMessage($connection, 'presence-room', 'victim', 'wrong-secret'),
        );
    });

    expect($disposition)->toBe(MessageDisposition::Relay)
        ->and($connection->terminated)->toBeFalse()
        ->and($connection->hasState('cap.user'))->toBeFalse()
        ->and($this->redis->keys('cap-test:*'))->toBe([]);
});

it('relays messages it does not own', function () {
    $app = app(ApplicationProvider::class)->findById('app-id');
    $context = new PluginContext(app(ChannelManager::class));

    $connection = new FakeConnection('sock-1', $app);
    $plugin = new PresenceCapPlugin;
    $dispositions = [];

    runLoop(function () use ($plugin, $context, $connection, &$dispositions) {
        $plugin->boot($context);

        $dispositions['ping'] = $plugin->onMessage($connection, ['event' => 'pusher:ping']);
        $dispositions['client'] = $plugin->onMessage($connection, [
            'event' => 'client-typing',
            'data' => ['channel' => 'presence-room'],
        ]);
        $dispositions['public'] = $plugin->onMessage($connection, [
            'event' => 'pusher:subscribe',
            'data' => ['channel' => 'updates'],
        ]);
        $dispositions['malformed'] = $plugin->onMessage($connection, [
            'event' => 'pusher:subscribe',
            'data' => 'not-an-array',
        ]);
    });

    expect($dispositions)->toBe([
        'ping' => MessageDisposition::Relay,
        'client' => MessageDisposition::Relay,
        'public' => MessageDisposition::Relay,
        'malformed' => MessageDisposition::Relay,
    ])->and($this->redis->keys('cap-test:*'))->toBe([]);
});

it('frees a slot when a counted connection closes', function () {
    $app = app(ApplicationProvider::class)->findById('app-id');
    $context = new PluginContext(app(ChannelManager::class));

    $first = new FakeConnection('sock-1', $app);
    $second = new FakeConnection('sock-2', $app);
    $third = new FakeConnection('sock-3', $app);

    $plugin = new PresenceCapPlugin;

    runLoop(function () use ($plugin, $context, $first, $second, $third) {
        $plugin->boot($context);
        capSubscribe($plugin, $first, 'presence-a-'.uniqid(), 'u-1');
        capSubscribe($plugin, $second, 'presence-b-'.uniqid(), 'u-1');

        // The first connection drops, freeing a slot for the third.
        $plugin->onClose($first);

        capSubscribe($plugin, $third, 'presence-c-'.uniqid(), 'u-1');
    });

    expect($first->terminated)->toBeFalse()
        ->and($third->terminated)->toBeFalse()
        ->and($third->state('cap.user'))->toBe('u-1');
});

it('does not count a connection without a presence identity', function () {
    $app = app(ApplicationProvider::class)->findById('app-id');
    $context = new PluginContext(app(ChannelManager::class));

    $connection = new FakeConnection('sock-1', $app);
    $plugin = new PresenceCapPlugin;

    runLoop(function () use ($plugin, $context, $connection, $app) {
        $plugin->boot($context);

        $channel = app(ChannelManager::class)->for($app)->findOrCreate('updates');
        $channel->subscribe($connection);
        $plugin->onSubscribe($connection, $channel);
    });

    expect($connection->terminated)->toBeFalse()
        ->and($connection->hasState('cap.user'))->toBeFalse()
        ->and($this->redis->keys('cap-test:*'))->toBe([]);
});

it('keeps the identity from the first presence sub on later presence subs', function () {
    $app = app(ApplicationProvider::class)->findById('app-id');
    $context = new PluginContext(app(ChannelManager::class));

    $connection = new FakeConnection('sock-1', $app);
    $plugin = new PresenceCapPlugin;

    runLoop(function () use ($plugin, $context, $connection) {
        $plugin->boot($context);
        capSubscribe($plugin, $connection, 'presence-room-a-'.uniqid(), 'u-1');
        capSubscribe($plugin, $connection, 'presence-room-b-'.uniqid(), 'u-2');
    });

    expect($connection->state('cap.user'))->toBe('u-1')
        ->and($this->redis->keys('cap-test:app-id:u-2:*'))->toBe([]);
});

it('reclaims a cap slot from a stale socket id on reconcile', function () {
    // Default cap in the test config is 2.
    $app = app(ApplicationProvider::class)->findById('app-id');
    $context = new PluginContext(app(ChannelManager::class));

    $live = new FakeConnection('sock-1', $app);
    $blocked = new FakeConnection('sock-2', $app);
    $afterReconcile = new FakeConnection('sock-3', $app);

    $room = 'presence-room-'.uniqid();
    $plugin = new PresenceCapPlugin;
    $redis = $this->redis;

    runLoop(function () use ($plugin, $context, $live, $blocked, $afterReconcile, $room, $redis) {
        $plugin->boot($context);

        capSubscribe($plugin, $live, $room, 'u-1');

        // A decrement that never landed (the plugin manager swallows anything
        // thrown out of onClose) leaves behind a socket id no connection owns.
        $redis->sadd(nodeKey('u-1'), ['ghost-sock']);

        // The ghost is holding the second slot hostage.
        capSubscribe($plugin, $blocked, $room, 'u-1');

        $plugin->ticks()[0]['callback']();

        capSubscribe($plugin, $afterReconcile, $room, 'u-1');
    });

    expect($blocked->terminated)->toBeTrue()
        ->and($afterReconcile->terminated)->toBeFalse()
        ->and($afterReconcile->state('cap.user'))->toBe('u-1');

    $members = $this->redis->smembers(nodeKey('u-1'));
    sort($members);

    expect($members)->toBe(['sock-1', 'sock-3']);
});

it('drops a user key entirely once its last connection is gone', function () {
    $app = app(ApplicationProvider::class)->findById('app-id');
    $context = new PluginContext(app(ChannelManager::class));

    $connection = new FakeConnection('sock-1', $app);
    $room = 'presence-room-'.uniqid();
    $plugin = new PresenceCapPlugin;
    $redis = $this->redis;

    runLoop(function () use ($plugin, $context, $connection, $room, $redis, $app) {
        $plugin->boot($context);

        capSubscribe($plugin, $connection, $room, 'u-1');

        $redis->sadd(nodeKey('u-1'), ['ghost-sock']);

        // The connection closes for real: Resonate strips it from every channel
        // before the plugin is notified.
        app(ChannelManager::class)->for($app)->unsubscribeFromAll($connection);
        $plugin->onClose($connection);

        $plugin->ticks()[0]['callback']();
    });

    expect($this->redis->exists(nodeKey('u-1')))->toBe(0);
});

it('keeps a live connection counted across a reconcile', function () {
    $app = app(ApplicationProvider::class)->findById('app-id');
    $context = new PluginContext(app(ChannelManager::class));

    $connection = new FakeConnection('sock-1', $app);
    $room = 'presence-room-'.uniqid();
    $plugin = new PresenceCapPlugin;

    runLoop(function () use ($plugin, $context, $connection, $room) {
        $plugin->boot($context);
        capSubscribe($plugin, $connection, $room, 'u-1');
        $plugin->ticks()[0]['callback']();
    });

    expect($this->redis->smembers(nodeKey('u-1')))->toBe(['sock-1'])
        ->and($this->redis->ttl(nodeKey('u-1')))->toBeGreaterThan(0);
});

it('restores a counted socket that is live in a presence channel', function () {
    $app = app(ApplicationProvider::class)->findById('app-id');
    $context = new PluginContext(app(ChannelManager::class));

    $connection = new FakeConnection('sock-1', $app);
    $room = 'presence-room-'.uniqid();
    $plugin = new PresenceCapPlugin;
    $redis = $this->redis;

    runLoop(function () use ($plugin, $context, $connection, $room, $redis) {
        $plugin->boot($context);
        capSubscribe($plugin, $connection, $room, 'u-1');

        // Something removed the socket behind the plugin's back.
        $redis->srem(nodeKey('u-1'), 'sock-1');

        $plugin->ticks()[0]['callback']();
    });

    expect($this->redis->smembers(nodeKey('u-1')))->toBe(['sock-1']);
});
