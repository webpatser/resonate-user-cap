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
        'scheme' => env('RESONATE_USER_CAP_REDIS_SCHEME', env('REDIS_SCHEME', 'tcp')),
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
    | Each user's per-node connection set is stored under "{prefix}:{{app}:
    | {user}}:{node}". The inner braces are a Redis Cluster hash tag, so the
    | prefix must not contain "{" or "}". The TTL is refreshed on every
    | heartbeat so a dead node's entries expire on their own.
    |
    | legacy_keys: also count the untagged "{prefix}:{app}:{user}:{node}" keys
    |   that releases before the hash tag wrote, so upgraded nodes keep
    |   counting the connections older nodes hold during a rolling upgrade.
    |   Off by default: it costs one extra SCAN of the whole database per
    |   presence subscribe. Turn it on for the rollout and off again once
    |   every node is upgraded and one TTL has passed.
    |
    */

    'key_prefix' => env('RESONATE_USER_CAP_PREFIX', 'cap'),

    'legacy_keys' => (bool) env('RESONATE_USER_CAP_LEGACY_KEYS', false),

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

    /*
    |--------------------------------------------------------------------------
    | Counter failures
    |--------------------------------------------------------------------------
    |
    | What happens to a presence subscribe when the counter cannot answer
    | (Redis unreachable, a script error). false refuses it with the error
    | frame above; true admits it uncounted and lets the next heartbeat after
    | Redis recovers write it into the count. Each outage is logged once.
    |
    */

    'fail_open' => (bool) env('RESONATE_USER_CAP_FAIL_OPEN', false),

];
