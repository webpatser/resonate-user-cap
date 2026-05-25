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

it('generates a colon-free node id', function () {
    expect(PresenceCapKeys::nodeId())->not->toContain(':')
        ->and(PresenceCapKeys::nodeId())->toContain((string) getmypid());
});
