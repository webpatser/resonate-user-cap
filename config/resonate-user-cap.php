<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Redis connection
    |--------------------------------------------------------------------------
    |
    | The plugin maintains a per-node Redis set of socket ids per user so the
    | cluster-wide count is correct and self-healing. Every node must point
    | at the same Redis server and database.
    |
    */

    'connection' => [
        'url' => env('RESONATE_USER_CAP_REDIS_URL', env('REDIS_URL')),
        'host' => env('RESONATE_USER_CAP_REDIS_HOST', env('REDIS_HOST', '127.0.0.1')),
        'port' => env('RESONATE_USER_CAP_REDIS_PORT', env('REDIS_PORT', '6379')),
        'username' => env('RESONATE_USER_CAP_REDIS_USERNAME', env('REDIS_USERNAME')),
        'password' => env('RESONATE_USER_CAP_REDIS_PASSWORD', env('REDIS_PASSWORD')),
        'database' => env('RESONATE_USER_CAP_REDIS_DB', env('REDIS_DB', '0')),
        'timeout' => env('RESONATE_USER_CAP_REDIS_TIMEOUT', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Key prefix and TTL
    |--------------------------------------------------------------------------
    |
    | Each user's per-node connection set is stored under "{prefix}:{app}:
    | {user}:{node}". The TTL is refreshed on every heartbeat so a dead
    | node's entries expire on their own.
    |
    */

    'key_prefix' => env('RESONATE_USER_CAP_PREFIX', 'cap'),

    'ttl' => (int) env('RESONATE_USER_CAP_TTL', 90),

    'heartbeat_interval' => (float) env('RESONATE_USER_CAP_HEARTBEAT', 30.0),

    /*
    |--------------------------------------------------------------------------
    | Caps
    |--------------------------------------------------------------------------
    |
    | default: the cluster-wide connection cap per user, in connections.
    |   0 disables the cap (the plugin still records identity, but never
    |   terminates).
    | per_app: per-app overrides, keyed by app id.
    |
    */

    'default' => (int) env('RESONATE_USER_CAP_DEFAULT', 5),

    'per_app' => [
        // 'app-id' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Termination payload
    |--------------------------------------------------------------------------
    |
    | The pusher:error event sent to the client just before the connection is
    | closed. Codes in the 4000 range are reserved by the Pusher protocol for
    | server-initiated closes.
    |
    */

    'error_code' => (int) env('RESONATE_USER_CAP_ERROR_CODE', 4301),

    'error_message' => env('RESONATE_USER_CAP_ERROR_MESSAGE', 'Too many connections for this user'),

];
