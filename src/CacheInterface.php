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
}
