<?php

declare(strict_types=1);

namespace Tests;

use Composer\Autoload\ClassLoader;
use EzPhp\Cache\CacheStats;
use EzPhp\Cache\CacheValue;
use EzPhp\Cache\DatabaseDriver;
use EzPhp\Cache\DatabaseLock;
use EzPhp\Database\Database;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use ReflectionClass;
use RuntimeException;

/**
 * The dialect-specific parts of DatabaseDriver on MySQL: the DDL, the
 * `ON DUPLICATE KEY UPDATE` upsert (also with an unchanged value, where MySQL
 * reports 0 affected rows), the primary-key lock INSERT and increment() under
 * concurrent writers (separate PHP processes, each with its own connection).
 * Uses the testing database; skipped when DB_HOST is not set.
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

    public function testConcurrentIncrementsLoseNoUpdate(): void
    {
        $this->driver(); // creates the tables before the children race on them

        $processes = 4;
        $perProcess = 25;
        $this->runIncrementers('fresh', $processes, $perProcess);

        $this->assertSame($processes * $perProcess, $this->driver()->get('fresh'));
    }

    private function driver(): DatabaseDriver
    {
        self::assertNotNull($this->db);

        return new DatabaseDriver($this->db, self::TABLE, self::LOCK_TABLE);
    }

    /**
     * Run $processes PHP children that each call increment($key) $perProcess times on their own connection.
     *
     * @param string $key
     * @param int    $processes
     * @param int    $perProcess
     */
    private function runIncrementers(string $key, int $processes, int $perProcess): void
    {
        $loaderFile = (new ReflectionClass(ClassLoader::class))->getFileName();

        if ($loaderFile === false) {
            throw new RuntimeException('Cannot locate the Composer autoloader.');
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            (string) getenv('DB_HOST'),
            (string) (getenv('DB_PORT') ?: '3306'),
            (string) (getenv('DB_TESTING_DATABASE') ?: 'ez-php_testing'),
        );
        $code = sprintf(
            'require %s; $c = new \\EzPhp\\Cache\\DatabaseDriver(new \\EzPhp\\Database\\Database(%s, %s, %s), %s, %s);'
            . ' time_nanosleep(0, (int) max(0, (%F - microtime(true)) * 1e9));'
            . ' for ($i = 0; $i < %d; $i++) { $c->increment(%s); }',
            var_export(dirname($loaderFile, 2) . '/autoload.php', true),
            var_export($dsn, true),
            var_export((string) getenv('DB_USERNAME'), true),
            var_export((string) getenv('DB_PASSWORD'), true),
            var_export(self::TABLE, true),
            var_export(self::LOCK_TABLE, true),
            microtime(true) + 0.5,
            $perProcess,
            var_export($key, true),
        );

        $handles = [];
        $pipes = [];

        for ($i = 0; $i < $processes; $i++) {
            $handle = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);

            if ($handle === false) {
                throw new RuntimeException('proc_open failed.');
            }

            $handles[$i] = $handle;
        }

        foreach ($handles as $i => $handle) {
            stream_get_contents($pipes[$i][1]);
            $stderr = (string) stream_get_contents($pipes[$i][2]);
            fclose($pipes[$i][1]);
            fclose($pipes[$i][2]);
            $this->assertSame(0, proc_close($handle), 'Incrementer failed: ' . $stderr);
        }
    }

    private function dropTables(): void
    {
        $this->db?->getPdo()->exec('DROP TABLE IF EXISTS ' . self::TABLE . ', ' . self::LOCK_TABLE);
    }
}
