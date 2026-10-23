<?php

declare(strict_types=1);

namespace EzPhp\Cache;

/**
 * Interface PrunableCacheInterface
 *
 * A store whose expired entries stay behind until they are read again, and that
 * can delete them in bulk. Redis, Memcached and the in-memory driver evict on
 * their own and do not implement it; `DatabaseDriver` does.
 *
 * @package EzPhp\Cache
 */
interface PrunableCacheInterface
{
    /**
     * Delete every expired entry (and expired lock).
     *
     * @return int Number of deleted cache entries.
     */
    public function prune(): int;
}
