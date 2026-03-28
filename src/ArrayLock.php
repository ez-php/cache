<?php

declare(strict_types=1);

namespace EzPhp\Cache;

use Closure;

/**
 * Class ArrayLock
 *
 * In-process, non-blocking lock backed by a static registry.
 * Each entry maps a key to an expiry timestamp (or null for no expiry).
 *
 * Global state is intentional here: the static registry must be shared
 * across all ArrayLock instances within the same PHP process so that locks
 * held by one instance are visible to another instance for the same key.
 *
 * Call ArrayLock::reset() in test tearDown to prevent state leaking between tests.
 *
 * @package EzPhp\Cache
 */
final class ArrayLock implements LockInterface
{
    /**
     * Shared lock registry: key → expiry timestamp (or null for no expiry).
     *
     * @var array<string, int|null>
     */
    private static array $locks = [];

    /**
     * ArrayLock Constructor
     *
     * @param string $key The lock key.
     * @param int    $ttl Seconds until the lock expires; 0 means never expire.
     */
    public function __construct(
        private readonly string $key,
        private readonly int $ttl,
    ) {
    }

    /**
     * Attempt to acquire the lock without blocking.
     * Returns true on success; false if the lock is already held and not expired.
     *
     * @return bool
     */
    public function acquire(): bool
    {
        if (array_key_exists($this->key, self::$locks)) {
            $expiry = self::$locks[$this->key];

            if ($expiry === null || $expiry > time()) {
                return false;
            }
        }

        self::$locks[$this->key] = $this->ttl !== 0 ? time() + $this->ttl : null;

        return true;
    }

    /**
     * Release the lock by removing it from the registry.
     *
     * @return void
     */
    public function release(): void
    {
        unset(self::$locks[$this->key]);
    }

    /**
     * Execute the callback while holding the lock.
     * Returns the callback's return value, or null if the lock could not be acquired.
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

    /**
     * Reset the static lock registry.
     * Call this in test tearDown to prevent state leaking between tests.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$locks = [];
    }
}
