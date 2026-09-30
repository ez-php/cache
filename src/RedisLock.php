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
 * The stored value is a random owner token; release() deletes the key only
 * while it still holds this instance's token (compare-and-delete in one Lua
 * script), so a holder whose TTL expired can't delete the next holder's lock.
 *
 * phpredis returns mixed from set(); always compare against true strictly.
 *
 * @package EzPhp\Cache
 */
final class RedisLock implements LockInterface
{
    private const string RELEASE_SCRIPT = <<<'LUA'
        if redis.call('GET', KEYS[1]) == ARGV[1] then
            return redis.call('DEL', KEYS[1])
        end
        return 0
        LUA;

    /**
     * Token written by a successful acquire(); null while not held.
     */
    private ?string $owner = null;

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
        $token = bin2hex(random_bytes(16));

        if ($this->ttl > 0) {
            $result = $this->redis->set($this->key, $token, ['nx', 'px' => $this->ttl * 1000]);
        } else {
            $result = $this->redis->set($this->key, $token, ['nx']);
        }

        if ($result !== true) {
            return false;
        }

        $this->owner = $token;

        return true;
    }

    /**
     * Delete the key if it still holds this instance's token.
     *
     * @return void
     */
    public function release(): void
    {
        if ($this->owner === null) {
            return;
        }

        $this->redis->eval(self::RELEASE_SCRIPT, [$this->key, $this->owner], 1);
        $this->owner = null;
    }

    /**
     * Delete the key whoever holds it.
     *
     * @return void
     */
    public function forceRelease(): void
    {
        $this->redis->del($this->key);
        $this->owner = null;
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
