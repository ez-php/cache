# Changelog

All notable changes to `ez-php/cache` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

---

## [v1.0.1] — 2026-03-25

### Changed
- Tightened all `ez-php/*` dependency constraints from `"*"` to `"^1.0"` for predictable resolution

---

## [v1.0.0] — 2026-03-24

### Added
- `CacheInterface` — unified `get()`, `set()`, `delete()`, `has()`, and `flush()` contract with optional TTL support
- `ArrayDriver` — in-memory cache; state is not persisted between requests; ideal for testing
- `FileDriver` — filesystem-backed cache with per-key TTL using serialized PHP files
- `RedisDriver` — Redis-backed cache using the `ext-redis` PHP extension; supports all TTL operations natively
- `CacheServiceProvider` — resolves the configured driver from environment and binds it as `CacheInterface`
- `CacheException` for driver initialization and operation failures
