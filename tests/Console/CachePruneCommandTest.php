<?php

declare(strict_types=1);

namespace Tests\Console;

use EzPhp\Cache\ArrayDriver;
use EzPhp\Cache\CacheValue;
use EzPhp\Cache\Console\CachePruneCommand;
use EzPhp\Cache\DatabaseDriver;
use EzPhp\Cache\PrefixedCache;
use EzPhp\Database\Database;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\TestCase;

/**
 * cache:prune on a SQLite DatabaseDriver (directly and behind a PrefixedCache)
 * and the no-op report for stores that evict on their own.
 *
 * @package Tests\Console
 */
#[CoversClass(CachePruneCommand::class)]
#[UsesClass(DatabaseDriver::class)]
#[UsesClass(PrefixedCache::class)]
#[UsesClass(ArrayDriver::class)]
#[UsesClass(CacheValue::class)]
final class CachePruneCommandTest extends TestCase
{
    private function databaseDriverWithOneExpiredEntry(): DatabaseDriver
    {
        $db = new Database('sqlite::memory:', '', '');
        $cache = new DatabaseDriver($db);
        $cache->set('expired', 'v', 60);
        $cache->set('live', 'v', 60);
        $db->execute('UPDATE cache SET expiration = ? WHERE cache_key = ?', [time() - 1, 'expired']);

        return $cache;
    }

    public function test_prunes_expired_rows_of_the_database_driver(): void
    {
        $cache = $this->databaseDriverWithOneExpiredEntry();

        ob_start();
        $code = (new CachePruneCommand($cache))->handle([]);
        $output = (string) ob_get_clean();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Pruned 1 expired cache entry.', $output);
        $this->assertTrue($cache->has('live'));
    }

    public function test_unwraps_a_prefixed_cache(): void
    {
        $cache = $this->databaseDriverWithOneExpiredEntry();

        ob_start();
        (new CachePruneCommand(new PrefixedCache($cache, 'app:')))->handle([]);
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('Pruned 1 expired cache entry.', $output);
    }

    public function test_stores_that_evict_on_their_own_are_a_no_op(): void
    {
        ob_start();
        $code = (new CachePruneCommand(new ArrayDriver()))->handle([]);
        $output = (string) ob_get_clean();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Nothing to prune', $output);
        $this->assertSame('cache:prune', (new CachePruneCommand(new ArrayDriver()))->getName());
    }
}
