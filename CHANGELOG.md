# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.2.0] 2025-10-21

## Added

* JetStream Distributed Counter CRDT (see [ADR-49](https://github.com/nats-io/nats-architecture-and-design/blob/main/adr/ADR-49.md)).
* JetStream Batch Publishing (see [ADR-50](https://github.com/nats-io/nats-architecture-and-design/blob/main/adr/ADR-50.md)).
* JetStream Message Scheduler (see [ADR-51](https://github.com/nats-io/nats-architecture-and-design/blob/main/adr/ADR-51.md)).

### Fixed

* Fix `ObjectStore::delete`.
* Fix iterator dispose in `ObjectStore::get()`.
* Fix `Consumer::unsubscribeAll`.
