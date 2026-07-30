# Changelog

All notable changes to `webpatser/resonate-user-cap` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
