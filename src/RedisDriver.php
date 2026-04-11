<?php

declare(strict_types=1);

namespace EzPhp\Cache;

use Closure;
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
    use TaggableDriverTrait;

    private Redis $redis;

    private int $hits = 0;

    private int $misses = 0;

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

        try {
            $connected = @$this->redis->connect($host, $port);
        } catch (\RedisException $e) {
            throw new RuntimeException("Redis connection failed: {$e->getMessage()}", previous: $e);
        }

        if (!$connected) {
            throw new RuntimeException("Redis connection failed: could not connect to {$host}:{$port}.");
        }

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
            $this->misses++;

            return $default;
        }

        $this->hits++;

        /** @var mixed $value */
        $value = unserialize($raw, ['allowed_classes' => false]);

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
     * @param string           $key
     * @param int              $ttl
     * @param Closure(): mixed $callback
     *
     * @return mixed
     */
    public function remember(string $key, int $ttl, Closure $callback): mixed
    {
        $raw = $this->redis->get($key);

        if (is_string($raw)) {
            $this->hits++;
            /** @var mixed $value */
            $value = unserialize($raw, ['allowed_classes' => false]);

            return $value;
        }

        $this->misses++;

        $value = $callback();
        $this->set($key, $value, $ttl);

        return $value;
    }

    /**
     * Flush the entire currently selected Redis database.
     *
     * @return void
     */
    public function flush(): void
    {
        $this->redis->flushDB();
    }

    /**
     * Atomically increment a counter key using Redis INCRBY.
     *
     * Creates the key (initialised to 0) if absent. Note: this method
     * operates on raw Redis integer keys and is NOT compatible with keys
     * created via set() (which serialises values). Use increment/decrement
     * exclusively for counter keys.
     *
     * @param string $key
     * @param int    $amount
     *
     * @return int
     */
    public function increment(string $key, int $amount = 1): int
    {
        return (int) $this->redis->incrBy($key, $amount);
    }

    /**
     * Atomically decrement a counter key using Redis DECRBY.
     *
     * Creates the key (initialised to 0) if absent. Note: this method
     * operates on raw Redis integer keys and is NOT compatible with keys
     * created via set() (which serialises values). Use increment/decrement
     * exclusively for counter keys.
     *
     * @param string $key
     * @param int    $amount
     *
     * @return int
     */
    public function decrement(string $key, int $amount = 1): int
    {
        return (int) $this->redis->decrBy($key, $amount);
    }

    /**
     * Acquire a non-blocking Redis lock for the given key.
     *
     * @param string $key
     * @param int    $ttl
     *
     * @return LockInterface
     */
    public function lock(string $key, int $ttl = 0): LockInterface
    {
        return new RedisLock($this->redis, $key, $ttl);
    }

    /**
     * Return hit/miss statistics for this driver instance.
     *
     * @return CacheStats
     */
    public function stats(): CacheStats
    {
        return new CacheStats($this->hits, $this->misses);
    }
}
