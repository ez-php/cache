<?php

declare(strict_types=1);

namespace EzPhp\Cache;

use Closure;

/**
 * Class FileLock
 *
 * Non-blocking exclusive file lock using flock(LOCK_EX|LOCK_NB).
 *
 * TTL is advisory and is not enforced by flock itself — the OS releases
 * the lock when the file handle is closed or the process exits.
 * A crashed process will have its lock released automatically by the OS.
 *
 * @package EzPhp\Cache
 */
final class FileLock implements LockInterface
{
    /**
     * The open file handle, or null/false when not acquired.
     *
     * Typed as mixed because is_resource() is needed to safely detect
     * whether a valid handle is present across PHP versions.
     */
    private mixed $handle = null;

    /**
     * FileLock Constructor
     *
     * @param string $path The path to the lock file.
     * @param int    $ttl  Advisory TTL in seconds (not enforced by flock).
     */
    public function __construct(
        private readonly string $path,
        private readonly int $ttl,
    ) {
    }

    /**
     * Attempt to acquire the lock without blocking.
     * Returns true on success; false if already held by another process.
     *
     * When acquired, writes the advisory expiry timestamp to the lock file
     * so that external tooling can identify stale locks.
     *
     * @return bool
     */
    public function acquire(): bool
    {
        $handle = fopen($this->path, 'c+');

        if ($handle === false) {
            return false;
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }

        // Write the advisory expiry so the TTL property is used and external
        // tooling can detect stale locks. flock does not enforce this value.
        $expiry = $this->ttl !== 0 ? time() + $this->ttl : 0;
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, (string) $expiry);

        $this->handle = $handle;

        return true;
    }

    /**
     * Release the lock and close the file handle.
     *
     * @return void
     */
    public function release(): void
    {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
            $this->handle = null;
        }
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
