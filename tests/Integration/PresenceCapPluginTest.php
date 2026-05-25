<?php

use Illuminate\Support\Facades\Event;
use Predis\Client;
use Webpatser\Resonate\Contracts\ApplicationProvider;
use Webpatser\Resonate\Plugins\PluginContext;
use Webpatser\Resonate\Protocols\Pusher\Contracts\ChannelManager;
use Webpatser\ResonateUserCap\Events\UserCapExceeded;
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
 * Subscribe a fake connection to a presence channel with a valid auth token.
 */
function joinPresence(string $channelName, FakeConnection $connection, string $userId): object
{
    $app = app(ApplicationProvider::class)->findById('app-id');
    $data = json_encode(['user_id' => $userId]);

    $channel = app(ChannelManager::class)->for($app)->findOrCreate($channelName);
    $channel->subscribe($connection, presenceAuth($connection->id(), $channelName, $data), $data);

    return $channel;
}

it('counts a connection on its first presence subscription', function () {
    $app = app(ApplicationProvider::class)->findById('app-id');
    $context = new PluginContext(app(ChannelManager::class));

    $connection = new FakeConnection('sock-1', $app);
    $channel = joinPresence('presence-room-'.uniqid(), $connection, 'u-1');

    $plugin = new PresenceCapPlugin;

    runLoop(function () use ($plugin, $context, $channel, $connection) {
        $plugin->boot($context);
        $plugin->onSubscribe($connection, $channel);
    });

    expect($connection->terminated)->toBeFalse()
        ->and($connection->hasState('cap.user'))->toBeTrue()
        ->and($connection->state('cap.user'))->toBe('u-1')
        ->and($this->redis->keys('cap-test:app-id:u-1:*'))->not->toBe([]);
});

it('terminates the connection that would exceed the cap', function () {
    Event::fake([UserCapExceeded::class]);

    // Default cap in the test config is 2.
    $app = app(ApplicationProvider::class)->findById('app-id');
    $context = new PluginContext(app(ChannelManager::class));

    $first = new FakeConnection('sock-1', $app);
    $second = new FakeConnection('sock-2', $app);
    $third = new FakeConnection('sock-3', $app);

    $channelA = joinPresence('presence-a-'.uniqid(), $first, 'u-1');
    $channelB = joinPresence('presence-b-'.uniqid(), $second, 'u-1');
    $channelC = joinPresence('presence-c-'.uniqid(), $third, 'u-1');

    $plugin = new PresenceCapPlugin;

    runLoop(function () use ($plugin, $context, $first, $second, $third, $channelA, $channelB, $channelC) {
        $plugin->boot($context);
        $plugin->onSubscribe($first, $channelA);
        $plugin->onSubscribe($second, $channelB);
        $plugin->onSubscribe($third, $channelC);
    });

    expect($first->terminated)->toBeFalse()
        ->and($second->terminated)->toBeFalse()
        ->and($third->terminated)->toBeTrue();

    Event::assertDispatched(UserCapExceeded::class, function (UserCapExceeded $event) {
        return $event->appId === 'app-id' && $event->userId === 'u-1';
    });
    Event::assertDispatchedTimes(UserCapExceeded::class, 1);

    $error = json_decode($third->messages[0], associative: true);

    expect($error['event'])->toBe('pusher:error')
        ->and(json_decode($error['data'], associative: true))->toBe([
            'code' => 4301,
            'message' => 'Too many connections for this user',
        ]);
});

it('frees a slot when a counted connection closes', function () {
    $app = app(ApplicationProvider::class)->findById('app-id');
    $context = new PluginContext(app(ChannelManager::class));

    $first = new FakeConnection('sock-1', $app);
    $second = new FakeConnection('sock-2', $app);
    $third = new FakeConnection('sock-3', $app);

    $channelA = joinPresence('presence-a-'.uniqid(), $first, 'u-1');
    $channelB = joinPresence('presence-b-'.uniqid(), $second, 'u-1');
    $channelC = joinPresence('presence-c-'.uniqid(), $third, 'u-1');

    $plugin = new PresenceCapPlugin;

    runLoop(function () use ($plugin, $context, $first, $second, $third, $channelA, $channelB, $channelC) {
        $plugin->boot($context);
        $plugin->onSubscribe($first, $channelA);
        $plugin->onSubscribe($second, $channelB);

        // The first connection drops, freeing a slot for the third.
        $plugin->onClose($first);

        $plugin->onSubscribe($third, $channelC);
    });

    expect($first->terminated)->toBeFalse()
        ->and($third->terminated)->toBeFalse()
        ->and($third->state('cap.user'))->toBe('u-1');
});

it('does not count a connection without a presence identity', function () {
    $app = app(ApplicationProvider::class)->findById('app-id');
    $context = new PluginContext(app(ChannelManager::class));

    $connection = new FakeConnection('sock-1', $app);
    $channel = app(ChannelManager::class)->for($app)->findOrCreate('updates');
    $channel->subscribe($connection);

    $plugin = new PresenceCapPlugin;

    runLoop(function () use ($plugin, $context, $channel, $connection) {
        $plugin->boot($context);
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
    $channelA = joinPresence('presence-room-a-'.uniqid(), $connection, 'u-1');
    $channelB = joinPresence('presence-room-b-'.uniqid(), $connection, 'u-2');

    $plugin = new PresenceCapPlugin;

    runLoop(function () use ($plugin, $context, $channelA, $channelB, $connection) {
        $plugin->boot($context);
        $plugin->onSubscribe($connection, $channelA);
        $plugin->onSubscribe($connection, $channelB);
    });

    expect($connection->state('cap.user'))->toBe('u-1')
        ->and($this->redis->keys('cap-test:app-id:u-2:*'))->toBe([]);
});
