<?php

declare(strict_types=1);

namespace EzPhp\Cache\Console;

use EzPhp\Cache\CacheInterface;
use EzPhp\Console\CommandInterface;
use EzPhp\Console\Input;
use EzPhp\Console\Output;

/**
 * Class CacheClearCommand
 *
 * `cache:clear` — empties the bound cache store, or only the entries of the
 * given tags with `--tags=a,b`.
 *
 * Note that a full clear empties the whole store: on Redis that is the selected
 * database, on Memcached the server, under a `cache.prefix` every prefix.
 *
 * @package EzPhp\Cache\Console
 */
final readonly class CacheClearCommand implements CommandInterface
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
        return 'cache:clear';
    }

    /**
     * @return string
     */
    public function getDescription(): string
    {
        return 'Flush the cache store, or only the entries of the given tags';
    }

    /**
     * @return string
     */
    public function getHelp(): string
    {
        return implode("\n", [
            'Usage: ez cache:clear [--tags=a,b]',
            '',
            'Options:',
            '  --tags=LIST  Comma-separated tags; only their entries are removed',
        ]);
    }

    /**
     * @param list<string> $args
     *
     * @return int
     */
    public function handle(array $args): int
    {
        $tags = array_values(array_filter(
            array_map('trim', explode(',', (new Input($args))->option('tags'))),
            static fn (string $tag): bool => $tag !== '',
        ));

        if ($tags === []) {
            $this->cache->flush();
            Output::success('Cache cleared.');

            return 0;
        }

        $this->cache->tags($tags)->flush();
        Output::success(sprintf('Cleared the cache entries tagged %s.', implode(', ', $tags)));

        return 0;
    }
}
