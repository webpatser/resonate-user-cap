# Changelog

All notable changes to `webpatser/resonate-user-cap` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

[Unreleased]: https://github.com/webpatser/resonate-user-cap/compare/v0.3.0...HEAD
[0.3.0]: https://github.com/webpatser/resonate-user-cap/compare/v0.2.3...v0.3.0
[0.2.3]: https://github.com/webpatser/resonate-user-cap/compare/v0.2.2...v0.2.3
[0.2.2]: https://github.com/webpatser/resonate-user-cap/compare/v0.2.1...v0.2.2
[0.2.1]: https://github.com/webpatser/resonate-user-cap/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/webpatser/resonate-user-cap/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/webpatser/resonate-user-cap/releases/tag/v0.1.0
