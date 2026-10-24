# Upgrade Guide

## Upgrading from 0.7 to 1.0

### Requirements

- `webpatser/resonate` `^1.0`
- `webpatser/fledge-fiber` `^13.38.0.3`

### Node registry replaces SCAN

1.0 no longer discovers the per-node sets with `SCAN`. Each node now registers itself in a per-user registry set, `{prefix}:{{app}:{user}}:nodes`, and the count reads only the registered nodes' sets. The per-node set keys themselves are unchanged.

0.7 nodes never write the registry, so **a mixed 0.7/1.0 rollout under-counts**: a 1.0 node does not see the connections a 0.7 node holds, and a 0.7 node does not see the connections a 1.0 node holds, until every node runs 1.0. Users can briefly exceed the cap during the rollout; the count is exact again as soon as the last 0.7 node is gone. If that window matters, upgrade every node at once rather than one at a time.

There is nothing to migrate: 1.0 nodes register on their first subscribe or heartbeat, and the sets 0.7 nodes left behind expire by `ttl`.

### `legacy_keys` is removed

`legacy_keys` (`RESONATE_USER_CAP_LEGACY_KEYS`) is gone, together with the `SCAN` fallback for the untagged `{prefix}:{app}:{user}:{node}` keys releases before 0.7.2 wrote. Remove the option from a published `config/resonate-user-cap.php` and the variable from your environment. Coming from a release before 0.7.2, upgrade through 0.7.2 first.

If you construct `UserConnectionCounter` yourself, drop the fifth `legacyKeys` argument.

### Redis Cluster

1.0 runs on Redis Cluster. Set `RESONATE_USER_CAP_REDIS_CLUSTER_NODES` to a comma-separated list of seed nodes (`host:port` or URIs), or `connection.cluster` in a published config:

```php
'connection' => [
    // ...
    'cluster' => env('RESONATE_USER_CAP_REDIS_CLUSTER_NODES'),
],
```

Seeds take username, password and TLS from the rest of the connection config. Leave it empty to keep using a single Redis server.
