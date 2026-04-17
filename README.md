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
    'driver'    => env('CACHE_DRIVER', 'array'), // array | file | redis | memcached
    'file_path' => storage_path('cache'),
    'redis'     => [
        'host'     => env('REDIS_HOST', '127.0.0.1'),
        'port'     => (int) env('REDIS_PORT', 6379),
        'database' => (int) env('REDIS_DATABASE', 0),
    ],
    'memcached' => [
        'host' => env('MEMCACHED_HOST', '127.0.0.1'),
        'port' => (int) env('MEMCACHED_PORT', 11211),
    ],
];
```

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

## Classes

| Class | Description |
|---|---|
| `CacheInterface` | Unified contract: `get`, `set`, `forget`, `has`, `remember`, `flush` |
| `ArrayDriver` | In-memory driver |
| `FileDriver` | Filesystem driver with MD5-keyed files |
| `RedisDriver` | Redis driver via `ext-redis`; native TTL |
| `MemcachedDriver` | Memcached driver via `ext-memcached` |
| `FileLock` / `ArrayLock` / `RedisLock` / `MemcachedLock` | Driver-specific lock implementations |
| `TaggableDriverTrait` | Provides `tags()` → `TaggedCache` for all drivers |
| `TaggedCache` | Scoped cache view: keys prefixed with tag hash |
| `CacheStats` | Immutable value object: hits, misses |
| `StampedeProtectedCache` | Decorator: probabilistic early recompute to prevent stampedes |
| `CacheServiceProvider` | Config-driven driver binding |

## License

MIT — [Andreas Uretschnig](mailto:andreas.uretschnig@gmail.com)
