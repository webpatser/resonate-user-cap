# Changelog

All notable changes to `webpatser/resonate-user-cap` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/webpatser/resonate-user-cap/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/webpatser/resonate-user-cap/releases/tag/v0.1.0
