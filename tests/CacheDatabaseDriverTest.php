<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Cache\CacheException;
use EzPhp\Cache\CacheStats;
use EzPhp\Cache\CacheValue;
use EzPhp\Cache\DatabaseDriver;
use EzPhp\Cache\DatabaseLock;
use EzPhp\Cache\TaggedCache;
use EzPhp\Database\Database;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * DatabaseDriver and DatabaseLock against SQLite :memory: (framework Database,
 * via ez-php/testing-application). DatabaseDriverMysqlTest repeats the
 * dialect-specific parts (DDL, upsert, lock INSERT) on MySQL.
 *
 * @package Tests
 */
#[CoversClass(DatabaseDriver::class)]
#[CoversClass(DatabaseLock::class)]
#[UsesClass(CacheValue::class)]
#[UsesClass(CacheStats::class)]
#[UsesClass(TaggedCache::class)]
#[UsesClass(CacheException::class)]
final class CacheDatabaseDriverTest extends TestCase
{
    private Database $db;

    private DatabaseDriver $cache;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new Database('sqlite::memory:', '', '');
        $this->cache = new DatabaseDriver($this->db);
    }

    public function testMissReturnsDefault(): void
    {
        $this->assertSame('d', $this->cache->get('missing', 'd'));
        $this->assertFalse($this->cache->has('missing'));
    }

    public function testSetGetRoundTripsScalarsAndArrays(): void
    {
        $this->cache->set('a', ['x' => 1, 'y' => [true, null, 1.5]]);
        $this->cache->set('b', false);

        $this->assertSame(['x' => 1, 'y' => [true, null, 1.5]], $this->cache->get('a'));
        $this->assertFalse($this->cache->get('b', 'default'));
        $this->assertTrue($this->cache->has('b'));
    }

    public function testSetOverwritesAnExistingKey(): void
    {
        $this->cache->set('k', 1);
        $this->cache->set('k', 2);

        $this->assertSame(2, $this->cache->get('k'));
        $this->assertSame([['cnt' => 1]], $this->db->query('SELECT COUNT(*) AS cnt FROM cache'));
    }

    public function testExpiredEntryIsAMissAndIsDeleted(): void
    {
        $this->cache->set('k', 'v', 60);
        $this->db->execute('UPDATE cache SET expiration = ?', [time() - 1]);

        $this->assertNull($this->cache->get('k'));
        $this->assertSame([['cnt' => 0]], $this->db->query('SELECT COUNT(*) AS cnt FROM cache'));
    }

    public function testPruneDeletesOnlyExpiredRowsAndExpiredLocks(): void
    {
        $this->cache->set('expired', 'v', 60);
        $this->cache->set('live', 'v', 60);
        $this->cache->set('forever', 'v');
        $this->db->execute('UPDATE cache SET expiration = ? WHERE cache_key = ?', [time() - 1, 'expired']);
        $this->assertTrue($this->cache->lock('stale', 60)->acquire());
        $this->assertTrue($this->cache->lock('held', 60)->acquire());
        $this->db->execute('UPDATE cache_locks SET expiration = ? WHERE lock_key = ?', [time() - 1, 'stale']);

        $this->assertSame(1, $this->cache->prune());

        $this->assertSame(
            [['cache_key' => 'forever'], ['cache_key' => 'live']],
            $this->db->query('SELECT cache_key FROM cache ORDER BY cache_key'),
        );
        $this->assertSame([['lock_key' => 'held']], $this->db->query('SELECT lock_key FROM cache_locks'));
        $this->assertSame(0, $this->cache->prune());
    }

    public function testExpirationIsIndexed(): void
    {
        $indexes = $this->db->query("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'cache' AND sql LIKE '%expiration%'");

        $this->assertSame([['name' => 'cache_expiration_idx']], $indexes);

        new DatabaseDriver($this->db); // the index statement is repeatable
    }

    public function testNegativeTtlRemovesTheKey(): void
    {
        $this->cache->set('k', 'v');
        $this->cache->set('k', 'v2', -1);

        $this->assertFalse($this->cache->has('k'));
    }

    public function testForgetAndFlush(): void
    {
        $this->cache->set('a', 1);
        $this->cache->set('b', 2);
        $this->cache->forget('a');

        $this->assertFalse($this->cache->has('a'));
        $this->assertTrue($this->cache->has('b'));

        $this->cache->flush();

        $this->assertFalse($this->cache->has('b'));
    }

    public function testRememberStoresOnMissOnly(): void
    {
        $calls = 0;
        $callback = static function () use (&$calls): string {
            $calls++;

            return 'computed';
        };

        $this->assertSame('computed', $this->cache->remember('r', 0, $callback));
        $this->assertSame('computed', $this->cache->remember('r', 0, $callback));
        $this->assertSame(1, $calls);
    }

    public function testIncrementAndDecrement(): void
    {
        $this->assertSame(1, $this->cache->increment('n'));
        $this->assertSame(6, $this->cache->increment('n', 5));
        $this->assertSame(4, $this->cache->decrement('n', 2));
        $this->assertSame(4, $this->cache->get('n'));
    }

    public function testIncrementKeepsTheExpiration(): void
    {
        $this->cache->set('n', 1, 100);
        $this->cache->increment('n');

        $rows = $this->db->query('SELECT expiration FROM cache WHERE cache_key = ?', ['n']);
        $expiration = $rows[0]['expiration'] ?? null;
        $this->assertIsNumeric($expiration);
        $this->assertGreaterThan(time(), (int) $expiration);
    }

    public function testTamperedObjectIsNeverRestoredAsAnInstance(): void
    {
        $this->db->execute(
            'INSERT INTO cache (cache_key, value, expiration) VALUES (?, ?, 0)',
            ['evil', serialize(new \ArrayObject([1]))],
        );

        $this->assertNotInstanceOf(\ArrayObject::class, $this->cache->get('evil'));
    }

    public function testObjectsAreRejected(): void
    {
        $this->expectException(CacheException::class);

        $this->cache->set('o', new \stdClass());
    }

    public function testStatsCountHitsAndMisses(): void
    {
        $this->cache->set('k', 1);
        $this->cache->get('k');
        $this->cache->get('nope');

        $stats = $this->cache->stats();
        $this->assertSame(1, $stats->hits);
        $this->assertSame(1, $stats->misses);
    }

    public function testTagsFlushOnlyTheirGroup(): void
    {
        $this->cache->tags('users')->set('u1', 'a');
        $this->cache->set('plain', 'b');

        $this->cache->tags('users')->flush();

        $this->assertFalse($this->cache->has('u1'));
        $this->assertTrue($this->cache->has('plain'));
    }

    public function testInvalidTableNameIsRejected(): void
    {
        $this->expectException(CacheException::class);

        new DatabaseDriver($this->db, 'cache; DROP TABLE users');
    }

    public function testLockIsExclusiveAndReleasedOnlyByItsOwner(): void
    {
        $first = $this->cache->lock('job', 10);
        $second = $this->cache->lock('job', 10);

        $this->assertTrue($first->acquire());
        $this->assertFalse($second->acquire());

        $second->release(); // never acquired: must not free the lock
        $this->assertFalse($this->cache->lock('job', 10)->acquire());

        $first->release();
        $this->assertTrue($second->acquire());
        $second->release();
    }

    public function testExpiredLockCanBeTakenOver(): void
    {
        $stale = $this->cache->lock('job', 10);
        $this->assertTrue($stale->acquire());
        $this->db->execute('UPDATE cache_locks SET expiration = ?', [time() - 1]);

        $fresh = $this->cache->lock('job', 10);
        $this->assertTrue($fresh->acquire());

        $stale->release(); // the old holder must not delete the new holder's lock
        $this->assertFalse($this->cache->lock('job', 10)->acquire());
    }

    public function testForceReleaseAndGet(): void
    {
        $held = $this->cache->lock('job');
        $this->assertTrue($held->acquire());

        $this->assertNull($this->cache->lock('job')->get(static fn (): string => 'ran'));

        $this->cache->lock('job')->forceRelease();

        $this->assertSame('ran', $this->cache->lock('job')->get(static fn (): string => 'ran'));
        $this->assertTrue($this->cache->lock('job')->acquire(), 'get() released the lock afterwards');
    }
}
