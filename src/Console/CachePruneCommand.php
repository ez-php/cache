<?php

declare(strict_types=1);

namespace EzPhp\Cache\Console;

use EzPhp\Cache\CacheInterface;
use EzPhp\Cache\PrefixedCache;
use EzPhp\Cache\PrunableCacheInterface;
use EzPhp\Console\CommandInterface;
use EzPhp\Console\Output;

/**
 * Class CachePruneCommand
 *
 * `cache:prune` — deletes expired entries from a store that keeps them until
 * they are read again (`DatabaseDriver`). Meant for the scheduler or cron.
 * Stores that evict on their own (array, file reads, Redis, Memcached) report a
 * no-op and exit 0, so the command can stay scheduled when the driver changes.
 *
 * A `PrefixedCache` is unwrapped first: pruning removes only expired rows, so it
 * is safe across every prefix of the shared store.
 *
 * @package EzPhp\Cache\Console
 */
final readonly class CachePruneCommand implements CommandInterface
{
    /**
     * @param CacheInterface $cache
     */
    public function __construct(private CacheInterface $cache)
    {
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'cache:prune';
    }

    /**
     * @return string
     */
    public function getDescription(): string
    {
        return 'Delete expired entries from the database cache store';
    }

    /**
     * @return string
     */
    public function getHelp(): string
    {
        return 'Usage: ez cache:prune';
    }

    /**
     * @param list<string> $args
     *
     * @return int
     */
    public function handle(array $args): int
    {
        $store = $this->cache;

        while ($store instanceof PrefixedCache) {
            $store = $store->inner();
        }

        if (!$store instanceof PrunableCacheInterface) {
            Output::info(sprintf('Nothing to prune: %s expires entries on its own.', $store::class));

            return 0;
        }

        Output::success(sprintf('Pruned %d expired cache entr%s.', $count = $store->prune(), $count === 1 ? 'y' : 'ies'));

        return 0;
    }
}
