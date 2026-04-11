<?php

declare(strict_types=1);

namespace EzPhp\Cache;

use Closure;

/**
 * Class ArrayDriver
 *
 * In-memory cache driver. Data lives only for the duration of the request.
 * Ideal for tests and as a no-op stand-in when no persistence is needed.
 *
 * @package EzPhp\Cache
 */
final class ArrayDriver implements CacheInterface
{
    use TaggableDriverTrait;

    /** @var array<string, array{value: mixed, expires: int|null}> */
    private array $store = [];

    private int $hits = 0;

    private int $misses = 0;

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
        $entry = $this->read($key);

        if ($entry !== null) {
            $this->hits++;

            return $entry['value'];
        }

        $this->misses++;

        return $default;
    }

    /**
     * Store an item in the cache.
     *
     * @param string $key
     * @param mixed  $value
     * @param int    $ttl  Seconds until expiry; 0 means never expire.
     *
     * @return void
     */
    public function set(string $key, mixed $value, int $ttl = 0): void
    {
        $this->store[$key] = [
            'value' => $value,
            'expires' => $ttl !== 0 ? time() + $ttl : null,
        ];
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
        unset($this->store[$key]);
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
        return $this->read($key) !== null;
    }

    /**
     * Retrieve an item from the cache, or compute and store it.
     *
     * @param string           $key
     * @param int              $ttl      Seconds until expiry; 0 means never expire.
     * @param Closure(): mixed $callback Called only on a cache miss.
     *
     * @return mixed
     */
    public function remember(string $key, int $ttl, Closure $callback): mixed
    {
        $entry = $this->read($key);

        if ($entry !== null) {
            $this->hits++;

            return $entry['value'];
        }

        $this->misses++;

        $value = $callback();
        $this->set($key, $value, $ttl);

        return $value;
    }

    /**
     * Remove all items from the cache.
     *
     * @return void
     */
    public function flush(): void
    {
        $this->store = [];
    }

    /**
     * Increment a numeric value. Creates the key (starting at 0) if absent.
     *
     * @param string $key
     * @param int    $amount
     *
     * @return int
     */
    public function increment(string $key, int $amount = 1): int
    {
        $entry = $this->read($key);
        $raw = $entry !== null ? $entry['value'] : 0;
        $current = is_int($raw) ? $raw : (int) (is_scalar($raw) ? $raw : 0);
        $new = $current + $amount;
        $this->set($key, $new);

        return $new;
    }

    /**
     * Decrement a numeric value. Creates the key (starting at 0) if absent.
     *
     * @param string $key
     * @param int    $amount
     *
     * @return int
     */
    public function decrement(string $key, int $amount = 1): int
    {
        $entry = $this->read($key);
        $raw = $entry !== null ? $entry['value'] : 0;
        $current = is_int($raw) ? $raw : (int) (is_scalar($raw) ? $raw : 0);
        $new = $current - $amount;
        $this->set($key, $new);

        return $new;
    }

    /**
     * Acquire a non-blocking lock for the given key.
     *
     * @param string $key
     * @param int    $ttl
     *
     * @return LockInterface
     */
    public function lock(string $key, int $ttl = 0): LockInterface
    {
        return new ArrayLock($key, $ttl);
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

    /** @return array{value: mixed}|null */
    private function read(string $key): ?array
    {
        if (!array_key_exists($key, $this->store)) {
            return null;
        }

        $entry = $this->store[$key];
        $expires = $entry['expires'];

        if (is_int($expires) && $expires < time()) {
            $this->forget($key);

            return null;
        }

        return ['value' => $entry['value']];
    }
}
