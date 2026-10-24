# Changelog

All notable changes to `webpatser/resonate-user-cap` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-10-24

### Added

- Redis Cluster support. Set `connection.cluster` (`RESONATE_USER_CAP_REDIS_CLUSTER_NODES`) to a comma-separated list of seed nodes and the plugin talks Redis Cluster through fledge-fiber's `createRedisClusterClient()`. Seeds given as `host:port`, and the masters discovered from them, take auth and TLS from the rest of the connection config (from `url` when set).
- A per-user node registry, `{prefix}:{{app}:{user}}:nodes`, under the same hash tag as the node sets. The cap script reads the registry and every registered node's set in one `EVAL`, and prunes nodes whose set has expired. Every node set it reads is declared in `KEYS`, so slot migrations redirect it rather than reading an already moved set as empty; a node that registers between the registry read and the script makes the check retry, and a registry that keeps changing fails like any other counter error (closed unless `fail_open`). `count()` reads the registry and the set sizes concurrently.

### Changed

- Require `webpatser/resonate` `^1.0` and `webpatser/fledge-fiber` `^13.38.0.3`.
- `add`, `remove` and the heartbeat `sync` each run as one script that also registers or unregisters the node.

### Removed

- `legacy_keys` (`RESONATE_USER_CAP_LEGACY_KEYS`) and the legacy `SCAN` fallback for the untagged keys releases before 0.7.2 wrote. Nothing in the plugin uses `SCAN` any more; it only walked one master on a Cluster.
- The `legacyKeys` argument of the `UserConnectionCounter` constructor.

### Breaking

- A mixed 0.7/1.0 rollout miscounts until every node runs 1.0: 1.0 nodes count only registered nodes and 0.7 nodes never register (under-count), while 0.7 nodes also count the registry key their `SCAN` matches (slight over-count). See [UPGRADE.md](UPGRADE.md).

## [0.7.2] - 2026-10-24

### Security

- Fail closed when the counter cannot answer. A Redis error in the cap check used to throw out of `onMessage`, which Resonate's plugin manager treats as a relay, so an unreachable Redis switched the cap off without a trace. The subscribe is now refused with the cap's `pusher:error` frame (`4301`), and each outage is logged once when it starts and once when it ends. `UserCapExceeded` is not dispatched for these refusals.

### Added

- `fail_open` (`RESONATE_USER_CAP_FAIL_OPEN`, default `false`): admit presence subscribes while the counter is unavailable instead. Admitted connections are tracked locally, so the first heartbeat after Redis recovers writes them into the count.
- `legacy_keys` (`RESONATE_USER_CAP_LEGACY_KEYS`, default `false`): also count the untagged keys older releases write, so upgraded nodes keep counting connections held by nodes not yet upgraded. It costs one extra `SCAN` of the whole database per presence subscribe, so enable it only for a rolling upgrade and turn it off once every node is upgraded and one `ttl` has passed. Without it, a user's connections on older nodes go uncounted until the rollout ends (older nodes never count the new keys either way).

### Changed

- Per-node keys now carry a Redis Cluster hash tag: `{prefix}:{{app}:{user}}:{node}` (was `{prefix}:{app}:{user}:{node}`). Every key the cap script reads for one user shares a slot, so the script no longer fails with `CROSSSLOT` on Cluster. `key_prefix` must not contain `{` or `}`. Keys in the old format are only read (see `legacy_keys`) and expire by TTL.

## [0.7.1] - 2026-10-22

### Changed

- Require `webpatser/fledge-fiber` `^13.38` (was `^13.29`).

## [0.7.0] - 2026-09-23

### Changed

- Versioning now tracks `webpatser/resonate`'s minor version: `0.7.x` targets resonate 0.7.
- Require `webpatser/resonate` `^0.7` (was `^0.4|^0.5|^0.6|^0.7`), dropping support for resonate 0.6 and earlier.

## [0.4.1] - 2026-09-23

### Changed

- Support `webpatser/resonate` v0.7.

## [0.4.0] - 2026-09-02

### Changed

- Require `webpatser/fledge-fiber` `^13.29` (was `^13.4`), and build the plugin's connection with `RedisConfig::fromParameters()` rather than a hand-assembled URI. TLS, unix sockets, ACL usernames, `read_timeout`, retry settings, client name and tcp keepalive reach the connection now; a password containing a reserved character used to fail authentication outright. A configured `url` still wins.
- `connection.scheme` (`RESONATE_USER_CAP_REDIS_SCHEME`, default `tcp`) selects the transport.

### Removed

- `UserConnectionCounter::refresh()`. The heartbeat has rebuilt each user's set through `sync()` since 0.3.0, which refreshes the TTL as part of the rebuild, so nothing had called `refresh()` since. A host that called it directly should call `sync()` with the sockets it still holds.

## [0.3.1] - 2026-08-02

### Fixed

- Allow `webpatser/resonate` v0.6. The constraint excluded it, so this package could not be installed alongside the current server release. Verified against v0.6.0.

## [0.3.0] - 2026-08-02

### Added

- `PresenceCapPlugin` implements `MessageInterceptor` alongside
  `ConnectionLifecycle`, `ServerPlugin` and `TickScheduler`.
- `UserConnectionCounter::sync()`: rewrite a node's set for one user to exactly
  the sockets given, reporting whether the user still has any.

### Changed

- Enforce the cap on the inbound `pusher:subscribe` message instead of after
  the subscription succeeds.
- Verify the presence auth signature before trusting the `user_id` in
  `channel_data`; a subscribe that fails the check is relayed untouched and
  never counted.

### Fixed

- Rebuild each tracked user's per-node set from live connections on every
  heartbeat, so a lost decrement no longer holds a cap slot until the node
  restarts.
- Remove a socket with a single `SREM`. The previous `SREM`, `SCARD`, `DEL`
  sequence erased any add that landed between the size check and the delete.

### Upgrading

Registration is unchanged. Three behaviour changes matter.

**Over-cap connections are refused before the subscription forms.** Enforcement
moved from a connection-lifecycle hook to a `MessageInterceptor` on
`pusher:subscribe`. A refused connection now receives the `pusher:error` frame
and nothing else: no `subscription_succeeded`, no presence member list, and no
`member_added` broadcast to the other members. Resonate has not verified the
presence auth at that point, so the plugin verifies the HMAC itself before
trusting the identity in `channel_data`; an unverifiable subscribe is relayed
untouched and never counted. A host application that called `onSubscribe()`
directly to apply the cap must call `onMessage()` instead.

**The heartbeat repairs the count.** The reconcile pass rebuilds each user's
set from the connections the node holds instead of refreshing the TTL of
whatever Redis has. Ghost entries left behind by a decrement that never landed
are dropped on the next beat rather than consuming a cap slot until restart.
`refresh()` is kept for callers that only want to extend a TTL; the heartbeat
uses `sync()`.

**One residual over-count.** A connection is counted one step before its
subscribe completes. If Resonate then refuses that subscribe for its own
reasons (a subscription limit, say), the socket stays counted until it closes.
That errs towards over-counting, the safe direction for a cap.

## [0.2.3] - 2026-07-30

### Changed

- Widen the `webpatser/resonate` constraint to `^0.4|^0.5`. Composer treats a
  `^0.4` caret on a 0.x package as `>=0.4 <0.5`, so this package could not be
  installed next to a server running Resonate v0.5 even though the suite passes
  against it. Both major lines are now accepted.

## [0.2.2] - 2026-07-30

### Security

- `PresenceCapKeys::encodeIdentity()` now throws instead of returning an
  unsanitised identity. `preg_replace_callback()` returns null on a PCRE error,
  which would have handed back the raw user id as the key segment; a cap key is
  never built from an unneutralised identity now.

### Changed

- CI runs the suite against a `redis:7` service container on
  `127.0.0.1:6379`. The integration tests self-skip when Redis does not answer,
  so the pipeline had never actually run them.
- CI gates on Laravel Pint and on PHPStan at level 8 (larastan, no baseline and
  no ignores), with matching `lint` and `analyse` composer scripts.
  `testbench.yaml` keeps the Fledge Fiber providers out of package discovery so
  larastan can boot a stock Laravel application for analysis.

## [0.2.1] - 2026-07-02

### Security
- Make the per-user connection cap atomic and sanitize the `user_id` key segment to prevent key injection and race conditions.

## [0.2.0] - 2026-05-25

### Added

- `Events\UserCapExceeded`: a Laravel event dispatched from
  `PresenceCapPlugin::onSubscribe` every time a new presence subscription
  would push a user past the configured cluster-wide cap, after the
  connection has been terminated. Carries the `appId` and `userId` so a
  metrics consumer can bucket cleanly. Used by `webpatser/resonate-pulse v0.2`.

## [0.1.0] - 2026-05-25

Initial release.

### Added

- `PresenceCapPlugin`: a Resonate server plugin that caps the cluster-wide
  connection count per presence `user_id`. Identifies a connection by its
  first presence subscription and terminates over-cap connections with a
  Pusher `pusher:error` frame.
- `UserConnectionCounter`: cluster-wide count backed by per-node Redis sets,
  with the same self-healing TTL pattern as `webpatser/resonate-roster`.
- `PresenceCapKeys`: shared key schema, colon-free node id.
- Default cap and per-app overrides; configurable error code and message.
- `UserCapServiceProvider`: merges config and publishes it via
  `vendor:publish --tag=resonate-user-cap-config`.

[1.0.0]: https://github.com/webpatser/resonate-user-cap/compare/v0.7.2...v1.0.0
[0.7.2]: https://github.com/webpatser/resonate-user-cap/compare/v0.7.1...v0.7.2
[0.7.1]: https://github.com/webpatser/resonate-user-cap/compare/v0.7.0...v0.7.1
[0.7.0]: https://github.com/webpatser/resonate-user-cap/compare/v0.4.1...v0.7.0
[0.4.1]: https://github.com/webpatser/resonate-user-cap/compare/v0.4.0...v0.4.1
[0.4.0]: https://github.com/webpatser/resonate-user-cap/compare/v0.3.1...v0.4.0
[0.3.1]: https://github.com/webpatser/resonate-user-cap/compare/v0.3.0...v0.3.1
[0.3.0]: https://github.com/webpatser/resonate-user-cap/compare/v0.2.3...v0.3.0
[0.2.3]: https://github.com/webpatser/resonate-user-cap/compare/v0.2.2...v0.2.3
[0.2.2]: https://github.com/webpatser/resonate-user-cap/compare/v0.2.1...v0.2.2
[0.2.1]: https://github.com/webpatser/resonate-user-cap/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/webpatser/resonate-user-cap/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/webpatser/resonate-user-cap/releases/tag/v0.1.0
