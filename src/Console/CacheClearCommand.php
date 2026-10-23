<?php

declare(strict_types=1);

namespace EzPhp\Cache\Console;

use EzPhp\Cache\ApcuDriver;
use EzPhp\Cache\CacheInterface;
use EzPhp\Cache\PrefixedCache;
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
 * An APCu store is refused from the CLI: every CLI process gets its own APCu
 * segment, discarded at exit, so clearing it would report success without
 * touching the cache the PHP-FPM workers use. Reset it from the web SAPI (or
 * restart PHP-FPM) instead.
 *
 * @package EzPhp\Cache\Console
 */
final readonly class CacheClearCommand implements CommandInterface
{
    /**
     * @param CacheInterface $cache
     * @param string         $sapi        The running SAPI (PHP_SAPI); a parameter so tests can pick it.
     * @param resource|null  $errorStream Where errors are written (default STDERR).
     */
    public function __construct(
        private CacheInterface $cache,
        private string $sapi = PHP_SAPI,
        private mixed $errorStream = null,
    ) {
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
        $store = $this->cache;

        while ($store instanceof PrefixedCache) {
            $store = $store->inner();
        }

        if ($store instanceof ApcuDriver && $this->sapi === 'cli') {
            Output::error(
                'cache:clear cannot reach the APCu cache of the web workers: the CLI has its own APCu memory. '
                . 'Restart PHP-FPM (or flush from a web request) to clear it.',
                $this->errorStream,
            );

            return 1;
        }

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
