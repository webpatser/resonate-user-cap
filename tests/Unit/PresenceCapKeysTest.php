<?php

use Webpatser\ResonateUserCap\PresenceCapKeys;

it('builds a per-node user key', function () {
    $keys = new PresenceCapKeys('cap');

    expect($keys->userKey('app-id', 'u-7', 'web-1-9001'))
        ->toBe('cap:app-id:u-7:web-1-9001');
});

it('builds a scan pattern that matches every node for one user', function () {
    $keys = new PresenceCapKeys('cap');

    $pattern = $keys->userScanPattern('app-id', 'u-7');

    expect($pattern)->toBe('cap:app-id:u-7:*')
        ->and(fnmatch($pattern, 'cap:app-id:u-7:web-1-9001'))->toBeTrue()
        ->and(fnmatch($pattern, 'cap:app-id:u-70:web-1-9001'))->toBeFalse();
});

it('honours a custom prefix', function () {
    $keys = new PresenceCapKeys('user-limit');

    expect($keys->userKey('a', 'u', 'n'))->toBe('user-limit:a:u:n');
});

it('encodes a colon in the user id so it cannot collide namespaces', function () {
    $keys = new PresenceCapKeys('cap');

    // A raw colon would forge the key "cap:app-id:evil:admin:node", colliding
    // with another user's namespace. It must be percent-encoded instead.
    $key = $keys->userKey('app-id', 'evil:admin', 'node-1');

    expect($key)->toBe('cap:app-id:evil%3Aadmin:node-1')
        ->and(substr_count($key, ':'))->toBe(3);

    // A user literally named "evil" on node "admin..." cannot collide with it.
    expect($keys->userKey('app-id', 'evil', 'admin'))
        ->not->toBe($key);
});

it('encodes glob metacharacters so a scan pattern cannot be broadened', function () {
    $keys = new PresenceCapKeys('cap');

    $pattern = $keys->userScanPattern('app-id', 'u*');

    // The injected "*" is encoded; only the trailing node wildcard remains, so
    // the pattern cannot match unrelated users like "u-7" or "u1".
    expect($pattern)->toBe('cap:app-id:u%2A:*')
        ->and(fnmatch($pattern, 'cap:app-id:u-7:node-1'))->toBeFalse()
        ->and(fnmatch($pattern, 'cap:app-id:u%2A:node-1'))->toBeTrue();
});

it('encodes every reserved character injectively', function () {
    $keys = new PresenceCapKeys('cap');

    expect($keys->userKey('app-id', '%:*?[]\\', 'n'))
        ->toBe('cap:app-id:%25%3A%2A%3F%5B%5D%5C:n');
});

it('generates a colon-free node id', function () {
    expect(PresenceCapKeys::nodeId())->not->toContain(':')
        ->and(PresenceCapKeys::nodeId())->toContain((string) getmypid());
});
