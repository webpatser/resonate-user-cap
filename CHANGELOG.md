# Changelog

All notable changes to `webpatser/resonate-user-cap` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- The heartbeat now rebuilds each tracked user's per-node set from the
  connections the node actually holds, instead of only refreshing the TTL of a
  non-empty set. A decrement that never landed (Resonate swallows anything a
  plugin throws out of `onClose`) left a stale socket id behind whose TTL every
  beat then renewed, so a user capped at 5 stayed capped at 4 until the node
  restarted. Stale ids are dropped on the next beat now, and a user with
  nothing live has its key removed.
- `UserConnectionCounter::remove()` issues a single `SREM` and no longer reads
  the size back to delete the key. The old `SREM`, `SCARD`, `DEL` sequence
  erased any add that landed between the size check and the delete, silently
  uncounting a live connection. Redis already drops a set with its last member,
  so the delete was never needed.

### Changed

- **Behavioural:** the cap is enforced on the inbound `pusher:subscribe`
  message instead of after the subscription succeeds. An over-cap connection
  had already joined the presence channel and been handed the member list
  before it was terminated, so a capped user could reconnect in a loop to
  snapshot who was online, and the other members saw it arrive. It is now
  refused before the subscription exists: the client gets the `pusher:error`
  frame and nothing else.
- Because Resonate has not verified the presence auth at that point, the plugin
  verifies the signature itself before trusting the `user_id` in
  `channel_data`. A subscribe with an invalid signature is relayed untouched
  (Resonate rejects it as it always has) and is never counted, so an
  unauthenticated client cannot consume another user's cap slots.

### Added

- **API:** `PresenceCapPlugin` now implements `MessageInterceptor` in addition
  to `ConnectionLifecycle`, `ServerPlugin` and `TickScheduler`. Registering the
  plugin is unchanged; a host application that called `onSubscribe()` directly
  to apply the cap must call `onMessage()` instead.
- **API:** `UserConnectionCounter::sync()`, which rewrites a node's set for one
  user to exactly the sockets it is given and reports whether the user still
  has any. `refresh()` is kept for callers that only want to extend the TTL,
  but the heartbeat no longer uses it.

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

[Unreleased]: https://github.com/webpatser/resonate-user-cap/compare/v0.2.0...HEAD
[0.2.1]: https://github.com/webpatser/resonate-user-cap/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/webpatser/resonate-user-cap/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/webpatser/resonate-user-cap/releases/tag/v0.1.0
