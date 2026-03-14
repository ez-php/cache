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
    /** @var array<string, array{value: mixed, expires: int|null}> */
    private array $store = [];

    /**
     * Retrieve an item from the cache.
     * Returns $default when the key is missing or expired.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $entry = $this->read($key);

        return $entry !== null ? $entry['value'] : $default;
    }

    /**
     * Store an item in the cache.
     *
     * @param int $ttl Seconds until expiry; 0 means never expire.
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
     */
    public function forget(string $key): void
    {
        unset($this->store[$key]);
    }

    /**
     * Return true if the key exists and has not expired.
     */
    public function has(string $key): bool
    {
        return $this->read($key) !== null;
    }

    /**
     * Retrieve an item from the cache, or compute and store it.
     *
     * @param Closure(): mixed $callback Called only on a cache miss.
     * @param int               $ttl      Seconds until expiry; 0 means never expire.
     */
    public function remember(string $key, int $ttl, Closure $callback): mixed
    {
        $entry = $this->read($key);

        if ($entry !== null) {
            return $entry['value'];
        }

        $value = $callback();
        $this->set($key, $value, $ttl);

        return $value;
    }

    /**
     * Remove all items from the cache.
     */
    public function flush(): void
    {
        $this->store = [];
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
