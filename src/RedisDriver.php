<?php

declare(strict_types=1);

namespace EzPhp\Cache;

use Redis;
use RuntimeException;

/**
 * Class RedisDriver
 *
 * Redis-backed cache driver using the PHP "ext-redis" extension.
 * Values are PHP-serialised before storage so any serialisable type is supported.
 *
 * @package EzPhp\Cache
 */
final class RedisDriver implements CacheInterface
{
    private readonly Redis $redis;

    /**
     * RedisDriver Constructor
     *
     * @param string $host
     * @param int    $port
     * @param int    $database
     */
    public function __construct(
        string $host = '127.0.0.1',
        int $port = 6379,
        int $database = 0,
    ) {
        if (!extension_loaded('redis')) {
            throw new RuntimeException('The "redis" PHP extension is required for the Redis cache driver.');
        }

        $this->redis = new Redis();
        $this->redis->connect($host, $port);

        if ($database !== 0) {
            $this->redis->select($database);
        }
    }

    /**
     * @param string     $key
     * @param mixed|null $default
     *
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $raw = $this->redis->get($key);

        if (!is_string($raw)) {
            return $default;
        }

        $value = unserialize($raw);

        return $value;
    }

    /**
     * @param string $key
     * @param mixed  $value
     * @param int    $ttl
     *
     * @return void
     */
    public function set(string $key, mixed $value, int $ttl = 0): void
    {
        $serialised = serialize($value);

        if ($ttl > 0) {
            $this->redis->setex($key, $ttl, $serialised);
        } else {
            $this->redis->set($key, $serialised);
        }
    }

    /**
     * @param string $key
     *
     * @return void
     */
    public function forget(string $key): void
    {
        $this->redis->del($key);
    }

    /**
     * @param string $key
     *
     * @return bool
     */
    public function has(string $key): bool
    {
        return $this->redis->exists($key) > 0;
    }

    /**
     * @param string   $key
     * @param int      $ttl
     * @param \Closure $callback
     *
     * @return mixed
     */
    public function remember(string $key, int $ttl, \Closure $callback): mixed
    {
        $raw = $this->redis->get($key);

        if (is_string($raw)) {
            $value = unserialize($raw);

            return $value;
        }

        $value = $callback();
        $this->set($key, $value, $ttl);

        return $value;
    }

    /**
     * Flush the entire currently selected Redis database.
     */
    public function flush(): void
    {
        $this->redis->flushDB();
    }
}
