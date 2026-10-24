<?php

use Fledge\Async\Redis\RedisConfig;
use Webpatser\ResonateUserCap\PresenceCapPlugin;

/**
 * The cluster seed configs the plugin builds from a connection config.
 *
 * @param  array<string, mixed>  $server
 * @return list<RedisConfig|string>
 */
function clusterConfigsFor(array $server): array
{
    return (fn (): array => $this->clusterConfigs($server))->call(new PresenceCapPlugin);
}

it('builds no seeds without a cluster setting', function () {
    expect(clusterConfigsFor(['host' => '127.0.0.1']))->toBe([])
        ->and(clusterConfigsFor(['cluster' => ' , ']))->toBe([]);
});

it('gives every host:port seed the auth, TLS and database 0 of the connection config', function () {
    $seeds = clusterConfigsFor([
        'scheme' => 'tls',
        'host' => 'ignored.example',
        'username' => 'cap',
        'password' => 'secret',
        'database' => '15',
        'cluster' => '10.0.0.1:7000, 10.0.0.2:7001,redis-3,[::1]:7002',
    ]);

    expect(array_map(fn (RedisConfig $seed): array => [
        $seed->getConnectUri(), $seed->getUsername(), $seed->getPassword(), $seed->usesTls(), $seed->getDatabase(),
    ], $seeds))->toBe([
        ['tcp://10.0.0.1:7000', 'cap', 'secret', true, 0],
        ['tcp://10.0.0.2:7001', 'cap', 'secret', true, 0],
        ['tcp://redis-3:6379', 'cap', 'secret', true, 0],
        ['tcp://[::1]:7002', 'cap', 'secret', true, 0],
    ]);
});

it('takes seed auth and TLS from the connection url when one is set', function () {
    [$seed] = clusterConfigsFor([
        'url' => 'rediss://cap:secret@ignored.example:6380/15',
        'cluster' => '10.0.0.1:7000',
    ]);

    expect($seed->getConnectUri())->toBe('tcp://10.0.0.1:7000')
        ->and($seed->getUsername())->toBe('cap')
        ->and($seed->getPassword())->toBe('secret')
        ->and($seed->usesTls())->toBeTrue()
        ->and($seed->getDatabase())->toBe(0);
});

it('passes a URI seed through untouched', function () {
    expect(clusterConfigsFor([
        'password' => 'secret',
        'cluster' => ['rediss://other:pass@10.0.0.1:7000'],
    ]))->toBe(['rediss://other:pass@10.0.0.1:7000']);
});
