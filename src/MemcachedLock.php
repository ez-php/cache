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
 * The stored value is a random owner token. release() reads the value with
 * its CAS id and, only if it is still this instance's token, overwrites it via
 * cas() with a negative expiry — which expires it at once. The CAS id makes
 * check and delete atomic: if another holder wrote in between, cas() fails and
 * that lock stays.
 *
 * @package EzPhp\Cache
 */
final class MemcachedLock implements LockInterface
{
    /**
     * Token written by a successful acquire(); null while not held.
     */
    private ?string $owner = null;

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
        $token = bin2hex(random_bytes(16));

        if (!$this->memcached->add($this->key, $token, $this->ttl)) {
            return false;
        }

        $this->owner = $token;

        return true;
    }

    /**
     * Expire the key if it still holds this instance's token (compare-and-swap).
     *
     * @return void
     */
    public function release(): void
    {
        if ($this->owner === null) {
            return;
        }

        $item = $this->memcached->get($this->key, null, Memcached::GET_EXTENDED);

        if (is_array($item) && ($item['value'] ?? null) === $this->owner && is_numeric($item['cas'] ?? null)) {
            // A negative expiry makes the item expire immediately.
            $this->memcached->cas((float) $item['cas'], $this->key, $this->owner, -1);
        }

        $this->owner = null;
    }

    /**
     * Delete the key whoever holds it.
     *
     * @return void
     */
    public function forceRelease(): void
    {
        $this->memcached->delete($this->key);
        $this->owner = null;
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
