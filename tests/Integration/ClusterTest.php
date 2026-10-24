<?php

use Fledge\Async\Redis\Cluster\SlotHasher;
use Fledge\Async\Redis\RedisClient;
use Webpatser\Resonate\Contracts\ApplicationProvider;
use Webpatser\Resonate\Plugins\MessageDisposition;
use Webpatser\Resonate\Plugins\PluginContext;
use Webpatser\Resonate\Protocols\Pusher\Contracts\ChannelManager;
use Webpatser\ResonateUserCap\PresenceCapKeys;
use Webpatser\ResonateUserCap\PresenceCapPlugin;
use Webpatser\ResonateUserCap\Tests\Support\FakeConnection;
use Webpatser\ResonateUserCap\UserConnectionCounter;

use function Fledge\Async\Redis\createRedisClusterClient;

/*
 * Runs against a real Redis Cluster, only when one is configured:
 *
 *   RESONATE_USER_CAP_TEST_CLUSTER_NODES=127.0.0.1:17000,127.0.0.1:17001,127.0.0.1:17002
 *
 * e.g. `docker run -d -e IP=0.0.0.0 -e INITIAL_PORT=17000 -e MASTERS=3
 * -e SLAVES_PER_MASTER=1 -p 17000-17005:17000-17005 grokzen/redis-cluster:7.0.10`.
 */
beforeEach(function () {
    $nodes = (string) getenv('RESONATE_USER_CAP_TEST_CLUSTER_NODES');

    if ($nodes === '') {
        $this->markTestSkipped('RESONATE_USER_CAP_TEST_CLUSTER_NODES not set');
    }

    $this->seeds = array_map(trim(...), explode(',', $nodes));
    $this->prefix = 'cap-cluster-'.bin2hex(random_bytes(4));
});

/**
 * A counter for one Resonate node, on its own cluster client.
 */
function clusterCounter(array $seeds, string $prefix, string $node): UserConnectionCounter
{
    return new UserConnectionCounter(createRedisClusterClient($seeds), new PresenceCapKeys($prefix), $node, 90);
}

/**
 * Delete a user's registry and every registered node set.
 */
function forgetClusterUser(RedisClient $redis, PresenceCapKeys $keys, string $userId): void
{
    $registry = $keys->registryKey('app-id', $userId);

    foreach ($redis->getSet($registry)->getAll() as $node) {
        $redis->delete($keys->userKey('app-id', $userId, $node));
    }

    $redis->delete($registry);
}

/**
 * User ids whose keys hash to at least one slot on every master.
 *
 * @return list<string>
 */
function usersAcrossMasters(): array
{
    // 0-5460, 5461-10922 and 10923-16383 are the three masters' slot ranges.
    $found = [];

    for ($i = 0; count($found) < 3 && $i < 1000; $i++) {
        $range = intdiv(SlotHasher::slotFor('{app-id:u-'.$i.'}'), 5461);
        $found[$range] ??= 'u-'.$i;
    }

    return array_values($found);
}

it('talks to a cluster of three masters', function () {
    $masters = 0;

    runLoop(function () use (&$masters) {
        $nodes = (string) createRedisClusterClient($this->seeds)->execute('cluster', 'nodes');

        foreach (explode("\n", trim($nodes)) as $line) {
            $flags = explode(' ', $line)[2] ?? '';

            if (str_contains($flags, 'master') && ! str_contains($flags, 'fail')) {
                $masters++;
            }
        }
    });

    expect($masters)->toBe(3);
});

it('enforces the cap across Resonate nodes for users on every master', function () {
    $results = [];

    runLoop(function () use (&$results) {
        $a = clusterCounter($this->seeds, $this->prefix, 'node-a');
        $b = clusterCounter($this->seeds, $this->prefix, 'node-b');
        $c = clusterCounter($this->seeds, $this->prefix, 'node-c');

        foreach (usersAcrossMasters() as $user) {
            $results[$user] = [
                $a->tryAdd('app-id', $user, 'sock-1', 2),
                $b->tryAdd('app-id', $user, 'sock-2', 2),
                $c->tryAdd('app-id', $user, 'sock-3', 2),
                $c->count('app-id', $user),
            ];

            forgetClusterUser(createRedisClusterClient($this->seeds), new PresenceCapKeys($this->prefix), $user);
        }
    });

    expect($results)->toHaveCount(3);

    foreach ($results as $result) {
        expect($result)->toBe([true, true, false, 2]);
    }
});

it('prunes a dead node from the registry', function () {
    $allowed = $count = $registry = null;

    runLoop(function () use (&$allowed, &$count, &$registry) {
        $redis = createRedisClusterClient($this->seeds);
        $keys = new PresenceCapKeys($this->prefix);

        $dead = clusterCounter($this->seeds, $this->prefix, 'node-dead');
        $dead->add('app-id', 'u-1', 'sock-1');
        $dead->add('app-id', 'u-1', 'sock-2');

        // The dead node never cleans up; its set simply runs out of TTL.
        $redis->delete($keys->userKey('app-id', 'u-1', 'node-dead'));

        $live = clusterCounter($this->seeds, $this->prefix, 'node-a');
        $allowed = $live->tryAdd('app-id', 'u-1', 'sock-3', 1);
        $count = $live->count('app-id', 'u-1');
        $registry = $redis->getSet($keys->registryKey('app-id', 'u-1'))->getAll();

        forgetClusterUser($redis, $keys, 'u-1');
    });

    expect($allowed)->toBeTrue()
        ->and($count)->toBe(1)
        ->and($registry)->toBe(['node-a']);
});

it('boots the plugin on the cluster seeds from the connection config', function () {
    config()->set('resonate-user-cap.connection.cluster', implode(',', $this->seeds));
    config()->set('resonate-user-cap.connection.database', '15');
    config()->set('resonate-user-cap.key_prefix', $this->prefix);
    config()->set('resonate-user-cap.default', 1);

    $app = app(ApplicationProvider::class)->findById('app-id');
    $context = new PluginContext(app(ChannelManager::class));

    $first = new FakeConnection('sock-1', $app);
    $second = new FakeConnection('sock-2', $app);
    $plugin = new PresenceCapPlugin;
    $dispositions = [];

    runLoop(function () use ($plugin, $context, $first, $second, &$dispositions) {
        $plugin->boot($context);

        foreach ([$first, $second] as $connection) {
            $data = json_encode(['user_id' => 'u-1'], flags: JSON_THROW_ON_ERROR);
            $channel = 'presence-room';

            $dispositions[] = $plugin->onMessage($connection, [
                'event' => 'pusher:subscribe',
                'data' => [
                    'channel' => $channel,
                    'auth' => presenceAuth($connection->id(), $channel, $data),
                    'channel_data' => $data,
                ],
            ]);
        }

        forgetClusterUser(createRedisClusterClient($this->seeds), new PresenceCapKeys($this->prefix), 'u-1');
    });

    expect($dispositions)->toBe([MessageDisposition::Relay, MessageDisposition::Rejected])
        ->and($second->terminated)->toBeTrue();
});
