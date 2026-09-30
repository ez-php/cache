<?php

declare(strict_types=1);

namespace EzPhp\Cache;

use Closure;

/**
 * Interface LockInterface
 *
 * Contract for distributed/process-level cache locks.
 *
 * @package EzPhp\Cache
 */
interface LockInterface
{
    /**
     * Attempt to acquire the lock without blocking.
     * Returns true on success, false if already held.
     *
     * @return bool
     */
    public function acquire(): bool;

    /**
     * Release the lock — only if this instance acquired it and still holds it.
     *
     * A lock whose TTL ran out may meanwhile belong to another holder; release()
     * leaves that lock alone. Calling it on an instance that never acquired the
     * lock is a no-op.
     *
     * @return void
     */
    public function release(): void;

    /**
     * Release the lock regardless of who holds it.
     *
     * For a deliberate hand-off where the releasing process is not the one
     * that acquired the lock (e.g. a queue worker releasing a unique-job lock
     * the dispatcher took). Prefer release() everywhere else.
     *
     * @return void
     */
    public function forceRelease(): void;

    /**
     * Execute the callback while holding the lock.
     * Returns the callback's return value, or null if the lock could not be acquired.
     *
     * @param Closure(): mixed $callback
     *
     * @return mixed
     */
    public function get(Closure $callback): mixed;
}
