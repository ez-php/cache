<?php

declare(strict_types=1);

namespace EzPhp\Cache;

use Closure;

/**
 * Interface CacheInterface
 *
 * Unified contract for all cache drivers.
 *
 * TTL rules:
 *   0   = no expiry (store forever)
 *   > 0 = expire after N seconds
 *   < 0 = expire immediately (already in the past; useful in tests)
 *
 * @package EzPhp\Cache
 */
interface CacheInterface
{
    /**
     * Acquire a non-blocking lock for the given key.
     *
     * @param string $key The lock key.
     * @param int    $ttl Seconds until the lock expires; 0 means never expire.
     *
     * @return LockInterface
     */
    public function lock(string $key, int $ttl = 0): LockInterface;

    /**
     * Return a TaggedCache scoped to the given tag(s).
     *
     * @param string|list<string> $tags
     *
     * @return TaggedCache
     */
    public function tags(string|array $tags): TaggedCache;

    /**
     * Return hit/miss statistics for this driver instance.
     *
     * @return CacheStats
     */
    public function stats(): CacheStats;

    /**
     * Retrieve an item from the cache.
     * Returns $default when the key is missing or expired.
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Store an item in the cache.
     *
     * @param int $ttl Seconds until expiry; 0 means never expire.
     */
    public function set(string $key, mixed $value, int $ttl = 0): void;

    /**
     * Remove an item from the cache.
     */
    public function forget(string $key): void;

    /**
     * Return true if the key exists and has not expired.
     */
    public function has(string $key): bool;

    /**
     * Retrieve an item from the cache, or compute and store it.
     *
     * @param Closure(): mixed $callback Called only on a cache miss.
     * @param int               $ttl      Seconds until expiry; 0 means never expire.
     */
    public function remember(string $key, int $ttl, Closure $callback): mixed;

    /**
     * Remove all items from the cache.
     */
    public function flush(): void;

    /**
     * Atomically increment a numeric value in the cache.
     *
     * Creates the key with a starting value of 0 when absent, then adds $amount.
     * Redis: uses native INCRBY (atomic). File/Array/Memcached: read-modify-write.
     *
     * @param string $key
     * @param int    $amount
     *
     * @return int The new value after incrementing.
     */
    public function increment(string $key, int $amount = 1): int;

    /**
     * Atomically decrement a numeric value in the cache.
     *
     * Creates the key with a starting value of 0 when absent, then subtracts $amount.
     * Redis: uses native DECRBY (atomic). File/Array/Memcached: read-modify-write.
     *
     * @param string $key
     * @param int    $amount
     *
     * @return int The new value after decrementing.
     */
    public function decrement(string $key, int $amount = 1): int;
}
