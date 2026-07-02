# Changelog

All notable changes to `webpatser/resonate-user-cap` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
