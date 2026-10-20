<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Cache\CacheStats;
use EzPhp\Cache\CacheValue;
use EzPhp\Cache\DatabaseDriver;
use EzPhp\Cache\DatabaseLock;
use EzPhp\Database\Database;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * The dialect-specific parts of DatabaseDriver on MySQL: the DDL, the
 * `ON DUPLICATE KEY UPDATE` upsert (also with an unchanged value, where MySQL
 * reports 0 affected rows) and the primary-key lock INSERT. Uses the testing
 * database; skipped when DB_HOST is not set.
 *
 * @package Tests
 */
#[CoversClass(DatabaseDriver::class)]
#[CoversClass(DatabaseLock::class)]
#[UsesClass(CacheValue::class)]
#[UsesClass(CacheStats::class)]
final class CacheDatabaseDriverMysqlTest extends TestCase
{
    private const string TABLE = 'ez_cache_driver_test';

    private const string LOCK_TABLE = 'ez_cache_driver_test_locks';

    private ?Database $db = null;

    protected function setUp(): void
    {
        parent::setUp();

        $host = (string) getenv('DB_HOST');

        if ($host === '') {
            $this->markTestSkipped('MySQL not available — set DB_HOST to run this test.');
        }

        $port = (string) (getenv('DB_PORT') ?: '3306');
        $database = (string) (getenv('DB_TESTING_DATABASE') ?: 'ez-php_testing');
        $this->db = new Database(
            "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
            (string) getenv('DB_USERNAME'),
            (string) getenv('DB_PASSWORD'),
        );
        $this->dropTables();
    }

    protected function tearDown(): void
    {
        $this->dropTables();

        parent::tearDown();
    }

    public function testUpsertGetAndUnchangedRewrite(): void
    {
        $cache = $this->driver();

        $cache->set('k', ['a' => 1], 60);
        $cache->set('k', ['a' => 1], 60); // unchanged row: 0 affected rows on MySQL
        $cache->set('k', ['a' => 2]);

        $this->assertSame(['a' => 2], $cache->get('k'));
        $this->assertSame(3, $cache->increment('k2', 3));
    }

    public function testLockIsExclusive(): void
    {
        $cache = $this->driver();
        $first = $cache->lock('job', 10);

        $this->assertTrue($first->acquire());
        $this->assertFalse($cache->lock('job', 10)->acquire());

        $first->release();
        $this->assertTrue($cache->lock('job', 10)->acquire());
    }

    public function testPruneAndExpirationIndexOnMysql(): void
    {
        $cache = $this->driver();
        $cache->set('expired', 'v', 60);
        $cache->set('live', 'v', 60);
        self::assertNotNull($this->db);
        $this->db->execute('UPDATE ' . self::TABLE . ' SET expiration = ? WHERE cache_key = ?', [time() - 1, 'expired']);

        $this->assertSame(1, $cache->prune());
        $this->assertSame([['cache_key' => 'live']], $this->db->query('SELECT cache_key FROM ' . self::TABLE));

        $keys = $this->db->query('SHOW INDEX FROM ' . self::TABLE . " WHERE Column_name = 'expiration'");
        $this->assertCount(1, $keys);
    }

    private function driver(): DatabaseDriver
    {
        self::assertNotNull($this->db);

        return new DatabaseDriver($this->db, self::TABLE, self::LOCK_TABLE);
    }

    private function dropTables(): void
    {
        $this->db?->getPdo()->exec('DROP TABLE IF EXISTS ' . self::TABLE . ', ' . self::LOCK_TABLE);
    }
}
