<?php

declare(strict_types=1);

namespace EzPhp\Cache;

/**
 * Class CacheStats
 *
 * Immutable value object holding cache hit/miss statistics.
 *
 * @package EzPhp\Cache
 */
final readonly class CacheStats
{
    /**
     * CacheStats Constructor
     *
     * @param int $hits   Number of successful cache lookups.
     * @param int $misses Number of failed cache lookups.
     */
    public function __construct(
        public int $hits,
        public int $misses,
    ) {
    }

    /**
     * Return the total number of cache lookups (hits + misses).
     *
     * @return int
     */
    public function total(): int
    {
        return $this->hits + $this->misses;
    }

    /**
     * Return the ratio of hits to total lookups.
     * Returns 0.0 when no lookups have been recorded.
     *
     * @return float
     */
    public function hitRatio(): float
    {
        $total = $this->total();

        if ($total === 0) {
            return 0.0;
        }

        return $this->hits / $total;
    }
}
