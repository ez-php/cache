# ez-php/cache

Cache module for the [ez-php framework](https://github.com/ez-php/framework) — array, file, and Redis drivers with a unified interface.

[![CI](https://github.com/ez-php/cache/actions/workflows/ci.yml/badge.svg)](https://github.com/ez-php/cache/actions/workflows/ci.yml)

## Requirements

- PHP 8.5+
- ez-php/framework ^1.0
- ext-redis (for Redis driver)

## Installation

```bash
composer require ez-php/cache
```

## Setup

Register the service provider:

```php
$app->register(\EzPhp\Cache\CacheServiceProvider::class);
```

Configure the driver in `config/cache.php`:

```php
return [
    'driver' => env('CACHE_DRIVER', 'array'), // array | file | redis
    'path'   => storage_path('cache'),
    'redis'  => [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'port' => (int) env('REDIS_PORT', 6379),
    ],
];
```

## Usage

```php
$cache = $app->make(\EzPhp\Cache\CacheInterface::class);

$cache->put('key', 'value', ttl: 3600);
$value = $cache->get('key');
$cache->forget('key');
```

## License

MIT — [Andreas Uretschnig](mailto:andreas.uretschnig@gmail.com)
