<?php

use Fledge\Async\Redis\Cluster\SlotHasher;
use Webpatser\ResonateUserCap\PresenceCapKeys;

it('builds a per-node user key', function () {
    $keys = new PresenceCapKeys('cap');

    expect($keys->userKey('app-id', 'u-7', 'web-1-9001'))
        ->toBe('cap:{app-id:u-7}:web-1-9001');
});

it('builds the node registry key for one user', function () {
    $keys = new PresenceCapKeys('cap');

    expect($keys->registryKey('app-id', 'u-7'))->toBe('cap:{app-id:u-7}:nodes')
        ->and($keys->userKeyPrefix('app-id', 'u-7').'web-1-9001')
        ->toBe($keys->userKey('app-id', 'u-7', 'web-1-9001'));
});

it('hashes every node key and the registry of one user to the same cluster slot', function () {
    $keys = new PresenceCapKeys('cap');

    // The cap script reads all of these in one EVAL; on Redis Cluster that
    // only works when they share a slot. Untagged keys spread across slots.
    $slots = array_map(
        fn (string $node) => SlotHasher::slotFor($keys->userKey('app-id', 'u-7', $node)),
        ['web-1-9001', 'web-2-17', 'worker-3-4242', 'node'],
    );

    $slots[] = SlotHasher::slotFor($keys->registryKey('app-id', 'u-7'));

    expect(array_unique($slots))->toHaveCount(1)
        ->and($slots[0])->toBe(SlotHasher::slotFor('{app-id:u-7}'));
});

it('keeps the hash tag stable when a user id carries a brace', function () {
    $keys = new PresenceCapKeys('cap');

    expect(SlotHasher::slotFor($keys->userKey('app-id', 'u}7', 'web-1')))
        ->toBe(SlotHasher::slotFor($keys->userKey('app-id', 'u}7', 'web-2')));
});

it('builds the legacy scan pattern for keys older releases wrote', function () {
    $keys = new PresenceCapKeys('cap');

    $pattern = $keys->legacyUserScanPattern('app-id', 'u-7');

    expect($pattern)->toBe('cap:app-id:u-7:*')
        ->and(fnmatch($pattern, 'cap:app-id:u-7:web-1-9001'))->toBeTrue()
        ->and(fnmatch($pattern, $keys->userKey('app-id', 'u-7', 'web-1-9001')))->toBeFalse()
        ->and($keys->legacyUserScanPattern('app-id', 'u*'))->toBe('cap:app-id:u%2A:*');
});

it('honours a custom prefix', function () {
    $keys = new PresenceCapKeys('user-limit');

    expect($keys->userKey('a', 'u', 'n'))->toBe('user-limit:{a:u}:n');
});

it('encodes a colon in the user id so it cannot collide namespaces', function () {
    $keys = new PresenceCapKeys('cap');

    // A raw colon would forge the key "cap:{app-id:evil:admin}:node",
    // colliding with another user's namespace. It must be percent-encoded.
    $key = $keys->userKey('app-id', 'evil:admin', 'node-1');

    expect($key)->toBe('cap:{app-id:evil%3Aadmin}:node-1')
        ->and(substr_count($key, ':'))->toBe(3);

    // A user literally named "evil" on node "admin..." cannot collide with it.
    expect($keys->userKey('app-id', 'evil', 'admin'))
        ->not->toBe($key);
});

it('encodes glob metacharacters in the user id', function () {
    $keys = new PresenceCapKeys('cap');

    expect($keys->registryKey('app-id', 'u*'))->toBe('cap:{app-id:u%2A}:nodes')
        ->and($keys->userKey('app-id', 'u*', 'node-1'))->toBe('cap:{app-id:u%2A}:node-1');
});

it('encodes every reserved character injectively', function () {
    $keys = new PresenceCapKeys('cap');

    expect($keys->userKey('app-id', '%:*?[]\\', 'n'))
        ->toBe('cap:{app-id:%25%3A%2A%3F%5B%5D%5C}:n');
});

it('generates a colon-free node id', function () {
    expect(PresenceCapKeys::nodeId())->not->toContain(':')
        ->and(PresenceCapKeys::nodeId())->toContain((string) getmypid());
});
