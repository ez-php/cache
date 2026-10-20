<?php

declare(strict_types=1);

namespace EzPhp\Cache;

use Closure;
use EzPhp\Contracts\DatabaseInterface;
use PDOException;

/**
 * Class DatabaseLock
 *
 * Non-blocking lock as a row in DatabaseDriver's lock table: acquire() is an
 * INSERT on the `lock_key` primary key — the database's uniqueness check is
 * the atomic test-and-set — carrying a random owner token and an expiration
 * (Unix timestamp, 0 = never). An expired row for the key is deleted first,
 * so a crashed holder's lock frees itself after its TTL.
 *
 * release() deletes the row only while it still carries this instance's
 * token, so a holder whose TTL ran out can't delete the next holder's lock;
 * forceRelease() deletes it whoever holds it.
 *
 * Note: on PostgreSQL a failed INSERT aborts an enclosing transaction —
 * acquire() outside an open transaction there.
 *
 * @package EzPhp\Cache
 */
final class DatabaseLock implements LockInterface
{
    /**
     * Token written by a successful acquire(); null while not held.
     */
    private ?string $owner = null;

    /**
     * DatabaseLock Constructor
     *
     * @param DatabaseInterface $db
     * @param string            $table Lock table (validated by DatabaseDriver).
     * @param string            $key   The lock key.
     * @param int               $ttl   Seconds until the lock expires; 0 means never expire.
     */
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly string $table,
        private readonly string $key,
        private readonly int $ttl,
    ) {
    }

    /**
     * Attempt to acquire the lock without blocking.
     *
     * @return bool
     */
    public function acquire(): bool
    {
        $this->db->execute(
            "DELETE FROM {$this->table} WHERE lock_key = ? AND expiration > 0 AND expiration <= ?",
            [$this->key, time()],
        );

        $token = bin2hex(random_bytes(16));

        try {
            $this->db->execute(
                "INSERT INTO {$this->table} (lock_key, owner, expiration) VALUES (?, ?, ?)",
                [$this->key, $token, $this->ttl > 0 ? time() + $this->ttl : 0],
            );
        } catch (PDOException) {
            // Primary-key violation: the lock is held.
            return false;
        }

        $this->owner = $token;

        return true;
    }

    /**
     * Delete the row if it still carries this instance's token.
     *
     * @return void
     */
    public function release(): void
    {
        if ($this->owner === null) {
            return;
        }

        $this->db->execute(
            "DELETE FROM {$this->table} WHERE lock_key = ? AND owner = ?",
            [$this->key, $this->owner],
        );
        $this->owner = null;
    }

    /**
     * Delete the row whoever holds it.
     *
     * @return void
     */
    public function forceRelease(): void
    {
        $this->db->execute("DELETE FROM {$this->table} WHERE lock_key = ?", [$this->key]);
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
