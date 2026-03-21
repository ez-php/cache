<?php

declare(strict_types=1);

namespace EzPhp\Cache;

use Closure;

/**
 * Class StampedeProtectedCache
 *
 * A CacheInterface decorator that implements the XFetch algorithm to
 * probabilistically recompute a cached value before it expires, reducing
 * the risk of cache stampedes under high concurrency.
 *
 * XFetch logic:
 *   Alongside every cached value a metadata key ("__xfetch_meta:{key}") is
 *   stored with the same TTL, recording:
 *     - expiry: the Unix timestamp when the value expires
 *     - delta:  how long the last recomputation took (in seconds)
 *
 *   On each cache hit the decorator samples a random float rand ∈ (0, 1)
 *   exclusive and performs an early refresh when:
 *     now - delta * beta * log(rand) >= expiry
 *
 *   A higher beta increases the probability of early refresh. The default
 *   value of 1.0 follows the paper's recommendation.
 *
 * Note: stats() includes metadata key reads in its counters.
 *
 * All other methods (get, set, forget, has, flush, lock, tags, stats)
 * delegate directly to the underlying driver.
 *
 * @package EzPhp\Cache
 */
final class StampedeProtectedCache implements CacheInterface
{
    /**
     * StampedeProtectedCache Constructor
     *
     * @param CacheInterface $driver The underlying cache driver.
     * @param float          $beta   XFetch beta parameter; higher = more aggressive early refresh.
     */
    public function __construct(
        private readonly CacheInterface $driver,
        private readonly float $beta = 1.0,
    ) {
    }

    /**
     * Retrieve an item from the cache.
     * Returns $default when the key is missing or expired.
     *
     * @param string $key
     * @param mixed  $default
     *
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->driver->get($key, $default);
    }

    /**
     * Store an item in the cache.
     *
     * @param string $key
     * @param mixed  $value
     * @param int    $ttl
     *
     * @return void
     */
    public function set(string $key, mixed $value, int $ttl = 0): void
    {
        $this->driver->set($key, $value, $ttl);
    }

    /**
     * Remove an item from the cache.
     *
     * @param string $key
     *
     * @return void
     */
    public function forget(string $key): void
    {
        $this->driver->forget($key);
    }

    /**
     * Return true if the key exists and has not expired.
     *
     * @param string $key
     *
     * @return bool
     */
    public function has(string $key): bool
    {
        return $this->driver->has($key);
    }

    /**
     * Retrieve an item from the cache or compute it, using XFetch to
     * probabilistically trigger early recomputation near expiry.
     *
     * Stores metadata under "__xfetch_meta:{key}" with the same TTL,
     * containing the expiry timestamp and the last recomputation delta.
     *
     * @param string           $key
     * @param int              $ttl      Seconds until expiry; 0 means never expire.
     * @param Closure(): mixed $callback Called on miss or early refresh.
     *
     * @return mixed
     */
    public function remember(string $key, int $ttl, Closure $callback): mixed
    {
        $metaKey = '__xfetch_meta:' . $key;

        $value = $this->driver->get($key);
        $meta = $this->driver->get($metaKey);

        if ($value !== null && is_array($meta)) {
            /** @var int $expiry */
            $expiry = $meta['expiry'];
            /** @var float $delta */
            $delta = $meta['delta'];

            $rand = mt_rand(1, mt_getrandmax()) / mt_getrandmax();
            $earlyRefresh = (int) round(microtime(true) - $delta * $this->beta * log($rand)) >= $expiry;

            if (!$earlyRefresh) {
                return $value;
            }
        }

        $start = microtime(true);
        $computed = $callback();
        $delta = microtime(true) - $start;

        $expiry = $ttl !== 0 ? time() + $ttl : PHP_INT_MAX;

        $this->driver->set($key, $computed, $ttl);
        $this->driver->set($metaKey, ['expiry' => $expiry, 'delta' => $delta], $ttl);

        return $computed;
    }

    /**
     * Remove all items from the cache.
     *
     * @return void
     */
    public function flush(): void
    {
        $this->driver->flush();
    }

    /**
     * Acquire a lock for the given key.
     *
     * @param string $key
     * @param int    $ttl
     *
     * @return LockInterface
     */
    public function lock(string $key, int $ttl = 0): LockInterface
    {
        return $this->driver->lock($key, $ttl);
    }

    /**
     * Return a TaggedCache scoped to the given tag(s), delegating to the driver.
     *
     * @param string|list<string> $tags
     *
     * @return TaggedCache
     */
    public function tags(string|array $tags): TaggedCache
    {
        return $this->driver->tags($tags);
    }

    /**
     * Return cache statistics from the underlying driver.
     *
     * @return CacheStats
     */
    public function stats(): CacheStats
    {
        return $this->driver->stats();
    }
}
