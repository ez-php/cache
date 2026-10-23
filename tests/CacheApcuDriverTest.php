<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Cache\ApcuDriver;
use EzPhp\Cache\ApcuLock;
use EzPhp\Cache\CacheException;
use EzPhp\Cache\CacheStats;
use EzPhp\Cache\CacheValue;
use EzPhp\Cache\TaggedCache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * ApcuDriver + ApcuLock against the real APCu user cache. Skipped when ext-apcu
 * is missing or disabled for the CLI (apc.enable_cli=0).
 *
 * @package Tests
 */
#[CoversClass(ApcuDriver::class)]
#[CoversClass(ApcuLock::class)]
#[UsesClass(CacheValue::class)]
#[UsesClass(CacheStats::class)]
#[UsesClass(TaggedCache::class)]
final class CacheApcuDriverTest extends TestCase
{
    private ApcuDriver $cache;

    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded('apcu') || !apcu_enabled()) {
            self::markTestSkipped('ext-apcu is not available or apc.enable_cli is off.');
        }

        apcu_clear_cache();
        $this->cache = new ApcuDriver();
    }

    protected function tearDown(): void
    {
        if (extension_loaded('apcu') && apcu_enabled()) {
            apcu_clear_cache();
        }

        parent::tearDown();
    }

    public function test_set_get_has_forget(): void
    {
        $this->cache->set('k', ['a' => 1]);

        $this->assertSame(['a' => 1], $this->cache->get('k'));
        $this->assertTrue($this->cache->has('k'));

        $this->cache->forget('k');

        $this->assertFalse($this->cache->has('k'));
        $this->assertSame('fallback', $this->cache->get('k', 'fallback'));
    }

    public function test_falsy_values_are_hits(): void
    {
        $this->cache->set('false', false);
        $this->cache->set('null', null);

        $this->assertFalse($this->cache->get('false', 'default'));
        $this->assertNull($this->cache->get('null', 'default'));
        $this->assertSame(2, $this->cache->stats()->hits);
    }

    public function test_negative_ttl_deletes_the_key(): void
    {
        $this->cache->set('k', 'v');
        $this->cache->set('k', 'v', -1);

        $this->assertFalse($this->cache->has('k'));
    }

    public function test_objects_are_rejected(): void
    {
        $this->expectException(CacheException::class);

        $this->cache->set('o', new \stdClass());
    }

    public function test_remember_computes_once(): void
    {
        $calls = 0;
        $compute = function () use (&$calls): int {
            $calls++;

            return 42;
        };

        $this->assertSame(42, $this->cache->remember('r', 60, $compute));
        $this->assertSame(42, $this->cache->remember('r', 60, $compute));
        $this->assertSame(1, $calls);
        $this->assertSame(1, $this->cache->stats()->misses);
    }

    public function test_increment_and_decrement(): void
    {
        $this->assertSame(1, $this->cache->increment('n'));
        $this->assertSame(6, $this->cache->increment('n', 5));
        $this->assertSame(4, $this->cache->decrement('n', 2));

        $this->cache->set('s', '10'); // non-int value: read-modify-write fallback
        $this->assertSame(11, $this->cache->increment('s'));
    }

    public function test_tags_and_flush(): void
    {
        $this->cache->tags('users')->set('u1', 'alice');
        $this->cache->set('plain', 'kept');

        $this->cache->tags('users')->flush();

        $this->assertFalse($this->cache->has('u1'));
        $this->assertTrue($this->cache->has('plain'));

        $this->cache->flush();

        $this->assertFalse($this->cache->has('plain'));
    }

    public function test_lock_is_exclusive_and_owner_only(): void
    {
        $first = $this->cache->lock('job', 10);
        $second = $this->cache->lock('job', 10);

        $this->assertTrue($first->acquire());
        $this->assertFalse($second->acquire());

        $second->release(); // never acquired: no-op
        $this->assertFalse($this->cache->lock('job', 10)->acquire());

        $first->release();
        $this->assertTrue($second->acquire());

        $second->forceRelease();
        $this->assertSame('ran', $this->cache->lock('job', 10)->get(fn (): string => 'ran'));
    }

    public function test_lock_does_not_collide_with_a_cache_entry_of_the_same_name(): void
    {
        $this->cache->set('job', 'value');

        $this->assertTrue($this->cache->lock('job')->acquire());
        $this->assertSame('value', $this->cache->get('job'));
    }
}
