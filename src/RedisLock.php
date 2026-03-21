<?php

declare(strict_types=1);

namespace EzPhp\Cache;

use Closure;
use Redis;

/**
 * Class RedisLock
 *
 * Non-blocking exclusive lock using Redis SET NX (set-if-not-exists).
 *
 * Uses the atomic SET key value NX PX ttl command so that the lock is
 * automatically released by Redis when the TTL expires, even if the
 * process crashes. When ttl=0 the lock has no expiry (no PX option sent).
 *
 * phpredis returns mixed from set(); always compare against true strictly.
 *
 * @package EzPhp\Cache
 */
final class RedisLock implements LockInterface
{
    /**
     * RedisLock Constructor
     *
     * @param Redis  $redis The connected Redis instance.
     * @param string $key   The lock key.
     * @param int    $ttl   Seconds until the lock expires; 0 means never expire.
     */
    public function __construct(
        private readonly Redis $redis,
        private readonly string $key,
        private readonly int $ttl,
    ) {
    }

    /**
     * Attempt to acquire the lock without blocking.
     * Returns true on success; false if the key already exists in Redis.
     *
     * @return bool
     */
    public function acquire(): bool
    {
        if ($this->ttl > 0) {
            $result = $this->redis->set($this->key, '1', ['nx', 'px' => $this->ttl * 1000]);
        } else {
            $result = $this->redis->set($this->key, '1', ['nx']);
        }

        return $result === true;
    }

    /**
     * Release the lock by deleting the Redis key.
     *
     * @return void
     */
    public function release(): void
    {
        $this->redis->del($this->key);
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
}
