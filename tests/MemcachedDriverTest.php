<?php

declare(strict_types=1);

namespace Tests\Cache;

use EzPhp\Cache\MemcachedDriver;
use EzPhp\Cache\MemcachedLock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\TestCase;
use Throwable;

/**
 * Class MemcachedDriverTest
 *
 * Integration tests for the Memcached cache driver.
 * All tests are skipped when the "memcached" PHP extension is not loaded
 * or the configured Memcached server is unreachable.
 *
 * @package Tests\Cache
 */
#[CoversClass(MemcachedDriver::class)]
#[UsesClass(MemcachedLock::class)]
#[Group('memcached')]
final class MemcachedDriverTest extends TestCase
{
    private const string HOST = 'memcached';

    private const int PORT = 11211;

    private MemcachedDriver $cache;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded('memcached')) {
            $this->markTestSkipped('The "memcached" PHP extension is not loaded.');
        }

        try {
            $this->cache = new MemcachedDriver([['host' => self::HOST, 'port' => self::PORT]]);
            $this->cache->flush();
        } catch (Throwable $e) {
            $this->markTestSkipped('Memcached not reachable: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        try {
            $this->cache->flush();
        } catch (Throwable) {
            // ignore
        }
    }

    public function testGetReturnsMissDefault(): void
    {
        $this->assertNull($this->cache->get('missing-key'));
        $this->assertSame('default', $this->cache->get('missing-key', 'default'));
    }

    public function testSetAndGet(): void
    {
        $this->cache->set('foo', 'bar');
        $this->assertSame('bar', $this->cache->get('foo'));
    }

    public function testSetWithTtlExpires(): void
    {
        $this->cache->set('ttl-key', 'value', 1);
        $this->assertSame('value', $this->cache->get('ttl-key'));

        sleep(2);

        $this->assertNull($this->cache->get('ttl-key'));
    }

    public function testSetWithNegativeTtlIsEffectivelyExpired(): void
    {
        $this->cache->set('neg-ttl', 'value', -1);

        sleep(2);

        $this->assertNull($this->cache->get('neg-ttl'));
    }

    public function testHasTrueForExistingKey(): void
    {
        $this->cache->set('exists', 'yes');
        $this->assertTrue($this->cache->has('exists'));
    }

    public function testHasFalseForMissingKey(): void
    {
        $this->assertFalse($this->cache->has('no-such-key'));
    }

    public function testForgetRemovesKey(): void
    {
        $this->cache->set('to-delete', 'value');
        $this->cache->forget('to-delete');
        $this->assertFalse($this->cache->has('to-delete'));
    }

    public function testRememberCachesCallbackResult(): void
    {
        $called = 0;
        $result = $this->cache->remember('computed', 60, function () use (&$called): string {
            $called++;

            return 'hello';
        });

        $this->assertSame('hello', $result);
        $this->assertSame(1, $called);

        // Second call must not invoke the callback.
        $result2 = $this->cache->remember('computed', 60, function () use (&$called): string {
            $called++;

            return 'hello';
        });

        $this->assertSame('hello', $result2);
        $this->assertSame(1, $called);
    }

    public function testFlushClearsAll(): void
    {
        $this->cache->set('a', 1);
        $this->cache->set('b', 2);
        $this->cache->flush();

        $this->assertFalse($this->cache->has('a'));
        $this->assertFalse($this->cache->has('b'));
    }

    public function testStatsTracksHitsAndMisses(): void
    {
        $this->cache->set('stat-key', 'value');
        $this->cache->get('stat-key');  // hit
        $this->cache->get('missing');   // miss

        $stats = $this->cache->stats();
        $this->assertGreaterThanOrEqual(1, $stats->hits);
        $this->assertGreaterThanOrEqual(1, $stats->misses);
    }

    public function testLockAcquireAndRelease(): void
    {
        $lock = $this->cache->lock('my-lock', 10);
        $this->assertTrue($lock->acquire());
        $this->assertFalse($lock->acquire()); // already held

        $lock->release();
        $this->assertTrue($lock->acquire()); // acquirable after release
        $lock->release();
    }
}
