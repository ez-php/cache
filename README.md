# ez-php/cache

Cache module for the [ez-php framework](https://github.com/ez-php/framework) — array, file, Redis, and Memcached drivers with a unified interface, tagging, locking, cache statistics, and stampede protection.

[![CI](https://github.com/ez-php/cache/actions/workflows/ci.yml/badge.svg)](https://github.com/ez-php/cache/actions/workflows/ci.yml)

## Requirements

- PHP 8.5+
- ez-php/framework 0.*
- ext-redis (for Redis driver)
- ext-memcached (for Memcached driver)

## Installation

```bash
composer require ez-php/cache
```

## Setup

Register the service provider:

```php
$app->register(\EzPhp\Cache\CacheServiceProvider::class);
```

Configure in `config/cache.php`:

```php
return [
    'driver'    => getenv('CACHE_DRIVER') ?: 'array', // array | file | redis | memcached | database
    'file_path' => getenv('CACHE_PATH') ?: sys_get_temp_dir() . '/ez-cache',
    'redis'     => [
        'host'     => getenv('CACHE_REDIS_HOST') ?: '127.0.0.1',
        'port'     => (int) (getenv('CACHE_REDIS_PORT') ?: 6379),
        'database' => (int) (getenv('CACHE_REDIS_DB') ?: 0),
    ],
    'memcached' => [
        'host'   => getenv('CACHE_MEMCACHED_HOST') ?: '127.0.0.1',
        'port'   => (int) (getenv('CACHE_MEMCACHED_PORT') ?: 11211),
        'weight' => (int) (getenv('CACHE_MEMCACHED_WEIGHT') ?: 0),
    ],
    'database'  => [
        'table'      => getenv('CACHE_DB_TABLE') ?: 'cache',
        'lock_table' => getenv('CACHE_DB_LOCK_TABLE') ?: 'cache_locks',
    ],
];
```

> Use the module-scoped `CACHE_REDIS_*` variables, not the generic `REDIS_HOST`/`REDIS_PORT`:
> in an application built from the `ez-php/` template, `REDIS_PORT` is the host-published
> port (e.g. `6383`), while the app container always reaches Redis at `redis:6379`.

## Usage

### Basic operations

```php
$cache = $app->make(\EzPhp\Cache\CacheInterface::class);

$cache->set('key', 'value', ttl: 3600);
$value  = $cache->get('key', 'default');
$cache->forget('key');
$cache->has('key');
$result = $cache->remember('key', 60, fn () => expensiveComputation());
$cache->flush();
```

**Values must be `null`, scalars, or (nested) arrays of those.** Every driver throws a
`CacheException` for objects and resources — the File and Redis drivers restore values with
`allowed_classes => false`, so an object could never come back intact. Cache a scalar/array
representation instead and rebuild the object after reading it:

```php
$cache->set('rate', (string) $decimal, 3600);
$decimal = BigDecimal::of($cache->get('rate'));
```

### Increment / decrement

```php
$cache->increment('page-views');           // 1
$cache->increment('page-views', 5);        // 6
$cache->decrement('stock:sku-42', 2);      // creates the key at 0 if absent, then subtracts
```

Both are atomic (Redis: native `INCRBY`/`DECRBY`; File/Array/Memcached: read-modify-write) and create the key with a starting value of 0 when it does not already exist.

### Tagging

```php
$tagged = $cache->tags('users');
$tagged->set('profile:42', $profile, 300);
$tagged->flush(); // invalidates all keys tagged with 'users'
```

### Locking

```php
$lock = $cache->lock('process-payments', ttl: 30);
if ($lock->acquire()) {
    try {
        // critical section
    } finally {
        $lock->release();
    }
}
```

`release()` only removes a lock this instance acquired and still owns: if the TTL ran out and
another process took the lock meanwhile, the late `release()` leaves that lock alone. Use
`forceRelease()` for a deliberate hand-off where another process releases (it removes the lock
whoever holds it; `FileLock` can't break another process's `flock()`, so there it only releases its own).

### Stampede protection

```php
use EzPhp\Cache\StampedeProtectedCache;

$protected = new StampedeProtectedCache($cache);
$value = $protected->remember('expensive-key', 300, fn () => heavyQuery());
```

## Drivers

| Driver | `CACHE_DRIVER` | Notes |
|--------|---------------|-------|
| `array` | `array` | In-memory, request lifetime only |
| `file` | `file` | Filesystem, serialised entries |
| `redis` | `redis` | Via `ext-redis`; `flush()` clears entire database |
| `memcached` | `memcached` | Via `ext-memcached`; `flush()` clears entire server |
| `database` | `database` | On the bound `DatabaseInterface`; tables `cache`/`cache_locks` auto-created (MySQL, SQLite, PostgreSQL); `flush()` empties only the cache table |

## Classes

| Class | Description |
|---|---|
| `CacheInterface` | Unified contract: `get`, `set`, `forget`, `has`, `remember`, `flush`, `increment`, `decrement` |
| `ArrayDriver` | In-memory driver |
| `FileDriver` | Filesystem driver with MD5-keyed files |
| `RedisDriver` | Redis driver via `ext-redis`; native TTL |
| `MemcachedDriver` | Memcached driver via `ext-memcached` |
| `DatabaseDriver` | Database driver on `DatabaseInterface`; auto-created tables; call `prune()` periodically to delete expired rows that are never read again |
| `FileLock` / `ArrayLock` / `RedisLock` / `MemcachedLock` / `DatabaseLock` | Driver-specific lock implementations |
| `TaggableDriverTrait` | Provides `tags()` → `TaggedCache` for all drivers |
| `TaggedCache` | Tag-scoped decorator: records keys per tag; `flush()` invalidates the group |
| `CacheStats` | Immutable value object: hits, misses |
| `CacheValue` | Value-contract guard used by every driver's `set()`: rejects objects and resources |
| `StampedeProtectedCache` | Decorator: probabilistic early recompute to prevent stampedes |
| `CacheServiceProvider` | Config-driven driver binding |

## License

MIT — [Andreas Uretschnig](mailto:andreas.uretschnig@gmail.com)
