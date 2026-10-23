<?php

declare(strict_types=1);

namespace EzPhp\Cache;

use Closure;

/**
 * Class ApcuLock
 *
 * Host-wide lock in APCu shared memory: apcu_add() of an integer owner token,
 * which fails while another holder's entry exists. Locks only one host — use
 * Redis/Memcached/Database locks across machines.
 *
 * release() is owner-only: apcu_cas() swaps this instance's token for 0 (atomic,
 * fails once the entry belongs to someone else) and only then deletes the entry.
 * Between those two calls the entry holds 0 and nobody can acquire it — a
 * microsecond window in which a concurrent acquire() simply reports "held".
 *
 * @package EzPhp\Cache
 */
final class ApcuLock implements LockInterface
{
    private ?int $owner = null;

    /**
     * @param string $key Full APCu key of the lock entry.
     * @param int    $ttl Seconds until the lock expires on its own; 0 = never.
     */
    public function __construct(
        private readonly string $key,
        private readonly int $ttl,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function acquire(): bool
    {
        $token = random_int(1, PHP_INT_MAX);

        if (!apcu_add($this->key, $token, max(0, $this->ttl))) {
            return false;
        }

        $this->owner = $token;

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function release(): void
    {
        if ($this->owner === null) {
            return;
        }

        if (apcu_cas($this->key, $this->owner, 0)) {
            apcu_delete($this->key);
        }

        $this->owner = null;
    }

    /**
     * {@inheritDoc}
     */
    public function forceRelease(): void
    {
        apcu_delete($this->key);
        $this->owner = null;
    }

    /**
     * {@inheritDoc}
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
