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
     * Release the lock.
     *
     * @return void
     */
    public function release(): void;

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
