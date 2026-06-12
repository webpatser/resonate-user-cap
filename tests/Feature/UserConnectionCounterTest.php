<?php

use Fledge\Async\Redis\RedisConfig;
use Predis\Client;
use Webpatser\ResonateUserCap\PresenceCapKeys;
use Webpatser\ResonateUserCap\UserConnectionCounter;

use function Fledge\Async\Redis\createRedisClient;

beforeEach(function () {
    if (! redisReachable()) {
        $this->markTestSkipped('Redis not reachable');
    }

    $this->redis = new Client(['host' => '127.0.0.1', 'port' => 6379, 'database' => 15]);

    flushCounterKeys($this->redis);
});

afterEach(function () {
    if (isset($this->redis)) {
        flushCounterKeys($this->redis);
    }
});

function flushCounterKeys(Client $redis): void
{
    foreach ($redis->keys('cap-test:*') as $key) {
        $redis->del($key);
    }
}

function makeCounter(string $node = 'node-test'): UserConnectionCounter
{
    return new UserConnectionCounter(
        createRedisClient(RedisConfig::fromUri('redis://127.0.0.1:6379/15')),
        new PresenceCapKeys('cap-test'),
        $node,
        90,
    );
}

it('counts a single node addition', function () {
    $count = null;

    runLoop(function () use (&$count) {
        $counter = makeCounter('node-a');
        $counter->add('app-id', 'u-1', 'sock-1');
        $counter->add('app-id', 'u-1', 'sock-2');

        $count = $counter->count('app-id', 'u-1');
    });

    expect($count)->toBe(2);
});

it('counts across nodes via the per-node set union', function () {
    $count = null;

    runLoop(function () use (&$count) {
        makeCounter('node-a')->add('app-id', 'u-1', 'sock-1');
        makeCounter('node-b')->add('app-id', 'u-1', 'sock-2');
        makeCounter('node-c')->add('app-id', 'u-1', 'sock-3');

        $count = makeCounter('node-a')->count('app-id', 'u-1');
    });

    expect($count)->toBe(3);
});

it('ignores a re-added socket and decrements cleanly', function () {
    $first = $second = $after = null;

    runLoop(function () use (&$first, &$second, &$after) {
        $counter = makeCounter('node-a');

        $first = $counter->add('app-id', 'u-1', 'sock-1');
        $second = $counter->add('app-id', 'u-1', 'sock-1');

        $counter->remove('app-id', 'u-1', 'sock-1');

        $after = $counter->count('app-id', 'u-1');
    });

    expect($first)->toBeTrue()
        ->and($second)->toBeFalse()
        ->and($after)->toBe(0);
});

it('keys counts independently by application and user', function () {
    $counts = [];

    runLoop(function () use (&$counts) {
        $counter = makeCounter('node-a');
        $counter->add('app-id', 'u-1', 'sock-1');
        $counter->add('other-app', 'u-1', 'sock-2');
        $counter->add('app-id', 'u-2', 'sock-3');

        $counts = [
            'app-id/u-1' => $counter->count('app-id', 'u-1'),
            'other-app/u-1' => $counter->count('other-app', 'u-1'),
            'app-id/u-2' => $counter->count('app-id', 'u-2'),
        ];
    });

    expect($counts)->toBe([
        'app-id/u-1' => 1,
        'other-app/u-1' => 1,
        'app-id/u-2' => 1,
    ]);
});

it('never lets concurrent adds for one user exceed the cap', function () {
    $cap = 5;
    $attempts = 50;
    $allowed = [];
    $count = null;

    runLoop(function () use ($cap, $attempts, &$allowed, &$count) {
        $counter = makeCounter('node-a');

        // Fire every add concurrently so they all race the same cap check; the
        // pre-fix check-then-add would let far more than $cap through.
        $closures = [];

        for ($i = 0; $i < $attempts; $i++) {
            $closures[$i] = fn () => $counter->tryAdd('app-id', 'u-1', 'sock-'.$i, $cap);
        }

        $allowed = \Fledge\Async\disperse($closures);
        $count = $counter->count('app-id', 'u-1');
    });

    expect(array_sum(array_map('intval', $allowed)))->toBe($cap)
        ->and($count)->toBe($cap);
});

it('counts a re-added socket idempotently against the cap', function () {
    $results = [];
    $count = null;

    runLoop(function () use (&$results, &$count) {
        $counter = makeCounter('node-a');

        // The same socket re-subscribing must not consume extra cap slots.
        $results[] = $counter->tryAdd('app-id', 'u-1', 'sock-1', 1);
        $results[] = $counter->tryAdd('app-id', 'u-1', 'sock-1', 1);
        $results[] = $counter->tryAdd('app-id', 'u-1', 'sock-2', 1);

        $count = $counter->count('app-id', 'u-1');
    });

    expect($results)->toBe([true, true, false])
        ->and($count)->toBe(1);
});

it('refresh returns false and deletes the key when the set is empty', function () {
    $result = null;

    runLoop(function () use (&$result) {
        $counter = makeCounter('node-a');
        $result = $counter->refresh('app-id', 'u-unknown');
    });

    expect($result)->toBeFalse()
        ->and($this->redis->exists('cap-test:app-id:u-unknown:node-a'))->toBe(0);
});
