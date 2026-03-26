<?php

declare(strict_types=1);

namespace EzPhp\Cache;

use Closure;
use Memcached;

/**
 * Class MemcachedLock
 *
 * Non-blocking exclusive lock using Memcached::add() (add-if-not-exists).
 *
 * `add()` is atomic on the Memcached server — only one caller can succeed
 * per key. TTL > 0 sets a server-side expiry so the lock is automatically
 * released if the process crashes. TTL = 0 means no automatic expiry.
 *
 * @package EzPhp\Cache
 */
final class MemcachedLock implements LockInterface
{
    /**
     * MemcachedLock Constructor
     *
     * @param Memcached $memcached The Memcached instance.
     * @param string    $key       The lock key.
     * @param int       $ttl       Seconds until lock expiry; 0 means no expiry.
     */
    public function __construct(
        private readonly Memcached $memcached,
        private readonly string $key,
        private readonly int $ttl,
    ) {
    }

    /**
     * Attempt to acquire the lock without blocking.
     *
     * Returns true on success; false if the key already exists.
     *
     * @return bool
     */
    public function acquire(): bool
    {
        return $this->memcached->add($this->key, '1', $this->ttl);
    }

    /**
     * Release the lock by deleting the Memcached key.
     *
     * @return void
     */
    public function release(): void
    {
        $this->memcached->delete($this->key);
    }

    /**
     * Execute the callback while holding the lock.
     *
     * Returns null if the lock could not be acquired.
     *
     * @param Closure(): mixed $callback
     *
     * @return mixed
     */
    public function get(Closure $callback): mixed
    {
        if (!$this->acquire()) {
            return null;
        }

        try {
            return $callback();
        } finally {
            $this->release();
        }
    }
}
