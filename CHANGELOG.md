# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed
* Keep base64url padding when encoding object store names and digests, matching the reference implementation (nats.go `base64.URLEncoding`). Objects written by previous versions use unpadded meta subjects and must be re-put to be visible to the fixed client and to other NATS clients.

## [0.4.1] 2026-04-21

### Added
* Allow set client name from config

### Changed
* Extend Traversable in Iterator
* Use `thesis/package-version` for SocketConnection version
* Use `Protocol\Err::toException()` in `SocketConnection::execute()`
* Error handling in `SocketConnection::run()`
* Simplify ping pong handler

### Fixed
* Fix graceful shutdown
* Fix get unknown counter
* Fix subscriptions leaks
* Cleanup pending requests, subscriptions, fix headers

## [0.4.0] 2025-11-15

### Added
* Implement Consumer Push API (see [PR](https://github.com/thesis-php/nats/pull/40) for details).

### Changed
* Improve headers parsing.
* Refactor nats subscription (see [PR](https://github.com/thesis-php/nats/pull/36) for details).
* Refactor JetStream Consumer API (see [PR](https://github.com/thesis-php/nats/pull/37) for details).
* Made the Delivery object lightweight (see [PR](https://github.com/thesis-php/nats/pull/39) for details).
* Refactor pull consumers API (see [PR](https://github.com/thesis-php/nats/pull/41) for details).

### Fixed

* Fix empty payload serialization.

### Deprecated

* Deprecate `Config::$version`, will be removed in `0.5.0`.

## [0.3.0] 2025-10-23

### Added

* JWT Authentication.
* Nats Service Api (see [ADR-32](https://github.com/nats-io/nats-architecture-and-design/blob/main/adr/ADR-32.md)).

### Fixed

* Fix heartbeats behaviour in `pull` consumers.

## [0.2.0] 2025-10-21

### Added

* JetStream Distributed Counter CRDT (see [ADR-49](https://github.com/nats-io/nats-architecture-and-design/blob/main/adr/ADR-49.md)).
* JetStream Batch Publishing (see [ADR-50](https://github.com/nats-io/nats-architecture-and-design/blob/main/adr/ADR-50.md)).
* JetStream Message Scheduler (see [ADR-51](https://github.com/nats-io/nats-architecture-and-design/blob/main/adr/ADR-51.md)).

### Fixed

* Fix `ObjectStore::delete`.
* Fix iterator dispose in `ObjectStore::get()`.
* Fix `Consumer::unsubscribeAll`.
