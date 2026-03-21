<?php

declare(strict_types=1);

namespace Tests\Cache;

use EzPhp\Cache\ArrayDriver;
use EzPhp\Cache\ArrayLock;
use EzPhp\Cache\CacheStats;
use EzPhp\Cache\LockInterface;
use EzPhp\Cache\StampedeProtectedCache;
use EzPhp\Cache\TaggedCache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\TestCase;

/**
 * Class StampedeProtectedCacheTest
 *
 * Tests StampedeProtectedCache backed by ArrayDriver.
 *
 * @package Tests\Cache
 */
#[CoversClass(StampedeProtectedCache::class)]
#[UsesClass(ArrayDriver::class)]
#[UsesClass(ArrayLock::class)]
#[UsesClass(CacheStats::class)]
#[UsesClass(TaggedCache::class)]
final class StampedeProtectedCacheTest extends TestCase
{
    private ArrayDriver $driver;

    private StampedeProtectedCache $cache;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        ArrayLock::reset();
        $this->driver = new ArrayDriver();
        $this->cache = new StampedeProtectedCache($this->driver);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        ArrayLock::reset();
        parent::tearDown();
    }

    /**
     * @return void
     */
    public function test_remember_works_on_miss_and_hit(): void
    {
        $calls = 0;

        $result1 = $this->cache->remember('key', 60, function () use (&$calls): string {
            $calls++;

            return 'value';
        });

        $result2 = $this->cache->remember('key', 60, function () use (&$calls): string {
            $calls++;

            return 'value';
        });

        $this->assertSame('value', $result1);
        $this->assertSame('value', $result2);
        $this->assertSame(1, $calls);
    }

    /**
     * @return void
     */
    public function test_callback_called_once_on_miss_not_again_on_hit(): void
    {
        $calls = 0;

        for ($i = 0; $i < 5; $i++) {
            $this->cache->remember('stable-key', 3600, function () use (&$calls): int {
                $calls++;

                return 99;
            });
        }

        $this->assertSame(1, $calls);
    }

    /**
     * @return void
     */
    public function test_xfetch_triggers_early_refresh_with_expired_meta(): void
    {
        // Store the value and meta directly in the driver with an already-expired
        // expiry timestamp so that early refresh is guaranteed to trigger.
        $this->driver->set('xkey', 'original');
        $this->driver->set('__xfetch_meta:xkey', [
            'expiry' => time() - 1,
            'delta' => 0.0,
        ]);

        $calls = 0;

        // With beta=1000.0 and an expired expiry, early refresh must trigger.
        $spc = new StampedeProtectedCache($this->driver, 1000.0);
        $result = $spc->remember('xkey', 60, function () use (&$calls): string {
            $calls++;

            return 'refreshed';
        });

        $this->assertSame('refreshed', $result);
        $this->assertSame(1, $calls);
    }

    /**
     * @return void
     */
    public function test_stats_delegates_to_underlying_driver(): void
    {
        $this->cache->remember('stat-key', 60, fn (): string => 'x');
        $this->cache->remember('stat-key', 60, fn (): string => 'x');

        $stats = $this->cache->stats();

        $this->assertInstanceOf(CacheStats::class, $stats);
        $this->assertGreaterThanOrEqual(1, $stats->total());
    }

    /**
     * @return void
     */
    public function test_lock_delegates_to_driver(): void
    {
        $lock = $this->cache->lock('lock-key', 10);

        $this->assertInstanceOf(LockInterface::class, $lock);
    }

    /**
     * @return void
     */
    public function test_tags_delegates_to_driver(): void
    {
        $tagged = $this->cache->tags('mytag');

        $this->assertInstanceOf(TaggedCache::class, $tagged);
    }

    /**
     * @return void
     */
    public function test_get_delegates_to_driver(): void
    {
        $this->driver->set('direct', 'hi');

        $this->assertSame('hi', $this->cache->get('direct'));
    }

    /**
     * @return void
     */
    public function test_set_delegates_to_driver(): void
    {
        $this->cache->set('setkey', 'setval');

        $this->assertSame('setval', $this->driver->get('setkey'));
    }

    /**
     * @return void
     */
    public function test_forget_delegates_to_driver(): void
    {
        $this->driver->set('forgetme', 'yes');
        $this->cache->forget('forgetme');

        $this->assertFalse($this->driver->has('forgetme'));
    }

    /**
     * @return void
     */
    public function test_has_delegates_to_driver(): void
    {
        $this->assertFalse($this->cache->has('nokey'));
        $this->driver->set('nokey', 'val');
        $this->assertTrue($this->cache->has('nokey'));
    }

    /**
     * @return void
     */
    public function test_flush_delegates_to_driver(): void
    {
        $this->driver->set('a', 1);
        $this->driver->set('b', 2);

        $this->cache->flush();

        $this->assertFalse($this->driver->has('a'));
        $this->assertFalse($this->driver->has('b'));
    }
}
