# Resonate User Cap

A [Resonate](https://github.com/webpatser/resonate) plugin that caps the cluster-wide connection count per user, so one user cannot open hundreds of tabs or run a runaway client against your socket server.

Resonate ships an app-level `max_connections`, which caps the total. This caps per *user*.

## How it works

### Identity from the first presence subscribe

A WebSocket connection is anonymous until something tells the server who it belongs to. Pusher presence channels carry the user identity in their `channel_data`, so this plugin takes a connection's `user_id` from the first presence subscribe whose signature it can verify. From then on, the connection counts against that user.

Connections that never subscribe to a presence channel are never counted and never capped.

### Cluster-wide count, self-healing

For each `(app_id, user_id)`, the plugin keeps a per-node Redis set of the socket ids it currently holds:

```
{prefix}:{{app}:{user}}:{node}    SET<socket_id>    (TTL refreshed by heartbeat)
```

A user's cluster-wide count is the union of every node's set: `SCAN {prefix}:{{app}:{user}}:*` then `SCARD` each. The inner braces are a Redis Cluster hash tag, so every node's set for one user lives in the same slot and the atomic cap script can read them all in one `EVAL`. This is the same self-healing pattern as `webpatser/resonate-roster`: a dead node's set expires on its own, and a live node never holds a dead node's count open.

### Rejected before the subscription happens

The cap is applied to the inbound `pusher:subscribe` message, before Resonate establishes the subscription. If accepting the connection would meet or exceed the cap, the plugin sends a Pusher error frame, closes the connection, and consumes the message:

```json
{"event": "pusher:error", "data": {"code": 4301, "message": "Too many connections for this user"}}
```

Nothing else reaches the client: it never joins the channel, never receives `subscription_succeeded`, and never sees the presence member list. The other members are not told it arrived either.

Because Resonate has not verified the presence auth yet at that point, the plugin verifies the signature itself before it trusts the `user_id` in `channel_data`. A subscribe whose signature does not check out is passed through untouched (Resonate rejects it as usual) and is never counted, so nobody can burn another user's cap slots by claiming their identity.

Otherwise the socket is added to this node's set and the identity is remembered in connection state, so `onClose` can decrement cleanly when the connection drops.

A check-then-add against the union can race two nodes into a one-over overshoot under heavy concurrent connect bursts; the next check immediately corrects it. An under-cap is not possible.

### Ghost entries heal themselves

Incremental edits can go missing. Resonate swallows anything a plugin throws out of `onClose`, so a Redis hiccup during a decrement leaves a socket id in the set with nothing behind it. One lost decrement used to cap a user at 4 of their 5 slots until the node restarted.

The heartbeat rebuilds each tracked user's set from the connections the node actually holds, so a stale id is dropped on the next beat rather than having its TTL refreshed forever.

## Installation

```bash
composer require webpatser/resonate-user-cap
```

Publish the config to change defaults:

```bash
php artisan vendor:publish --tag=resonate-user-cap-config
```

## Registering the plugin

```php
// config/reverb.php
'servers' => [
    'reverb' => [
        // ...
        'plugins' => [
            \Webpatser\ResonateUserCap\PresenceCapPlugin::class,
        ],
    ],
],
```

Restart Resonate (`php artisan resonate:start`, or `resonate:reload` for a zero-downtime swap).

## Configuration

```php
// config/resonate-user-cap.php
return [
    'connection' => [ /* Redis */ ],
    'key_prefix' => 'cap',
    'legacy_keys' => false,
    'ttl' => 90,
    'heartbeat_interval' => 30.0,

    'default' => 5,            // 0 disables capping
    'per_app' => [
        // 'app-id' => 3,
    ],

    'error_code' => 4301,
    'error_message' => 'Too many connections for this user',

    'fail_open' => false,
];
```

| Key | Default | Purpose |
|-----|---------|---------|
| `connection` | `REDIS_*` env | Redis server; every node must point at the same server and database. Takes Laravel's connection keys, `scheme` (`tcp`, `tls`/`rediss`, `unix`), `read_timeout` and the retry keys included. |
| `key_prefix` | `cap` | Namespace for the per-user sets. Must not contain `{` or `}`. |
| `legacy_keys` | `false` | Also count the untagged `{prefix}:{app}:{user}:{node}` keys releases before the hash tag wrote, so upgraded nodes keep counting older nodes' connections during a rolling upgrade. Costs one extra `SCAN` of the whole database per presence subscribe, so enable it only for the rollout and turn it off once every node is upgraded and one `ttl` has passed. |
| `ttl` | `90` | Seconds each node's set lives; refreshed on every heartbeat. |
| `heartbeat_interval` | `30.0` | Seconds between heartbeat ticks. |
| `default` | `5` | Default cluster-wide cap per user. `0` disables capping. |
| `per_app` | `[]` | Per-app overrides keyed by app id. |
| `error_code` / `error_message` | `4301` / text | The `pusher:error` payload sent before close. |
| `fail_open` | `false` | When the counter cannot answer (Redis down), `false` refuses the presence subscribe with the error payload above; `true` admits it uncounted and lets the next heartbeat after recovery count it. Each outage is logged once. |

## Notes and caveats

- **Presence is the identity source.** A connection that subscribes only to public or private channels has no `user_id`, so this plugin cannot and does not cap it. Pair with a custom auth plugin if you need to cap unauthenticated connections too.
- **Reject-new, not kick-oldest.** When a user is at the cap, the *new* connection is terminated. The existing ones are untouched.
- **Eventually consistent.** Concurrent connect bursts from one user across nodes may temporarily overshoot the cap by one; the next subscribe corrects it.
- **One identity per connection.** A connection's `user_id` is taken from its *first* verified presence subscribe. Later presence subscribes with a different `user_id` are ignored for capping.
- **Counted one step early.** A connection is counted when its subscribe is accepted by the cap, not when the subscription completes. If Resonate then refuses that subscribe for its own reasons (a subscription limit, say), the socket stays counted until it closes. Over-counting is the safe direction for a cap.
- **Fails closed.** If Redis cannot be reached, a new presence subscribe is refused with the cap's error frame rather than admitted uncounted. Set `fail_open` to prefer availability over the cap during an outage.
- **Self-healing count.** The heartbeat (`heartbeat_interval`, default 30s) rewrites each tracked user's set from the live connections, so a lost decrement costs a slot for at most one beat rather than until the next restart.

## Requirements

- PHP 8.5+
- Resonate 0.7+
- A Redis server reachable from every Resonate node

## Testing

```bash
composer test
```

Tests that touch Redis expect a server on `127.0.0.1:6379` and use database 15; they skip cleanly when no Redis is reachable.

## License

MIT. See [LICENSE](LICENSE).
