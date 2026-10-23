<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Cache\ArrayDriver;
use EzPhp\Cache\ArrayLock;
use EzPhp\Cache\CacheException;
use EzPhp\Cache\CacheStats;
use EzPhp\Cache\CacheValue;
use EzPhp\Cache\PrefixedCache;
use EzPhp\Cache\TaggedCache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * PrefixedCache over a shared ArrayDriver: two prefixes never see each other's
 * entries, tags and locks are prefixed too, flush() empties the shared store.
 *
 * @package Tests
 */
#[CoversClass(PrefixedCache::class)]
#[UsesClass(ArrayDriver::class)]
#[UsesClass(ArrayLock::class)]
#[UsesClass(CacheValue::class)]
#[UsesClass(CacheStats::class)]
#[UsesClass(TaggedCache::class)]
final class PrefixedCacheTest extends TestCase
{
    private ArrayDriver $store;

    private PrefixedCache $a;

    private PrefixedCache $b;

    protected function setUp(): void
    {
        parent::setUp();
        ArrayLock::reset();
        $this->store = new ArrayDriver();
        $this->a = new PrefixedCache($this->store, 'a:');
        $this->b = new PrefixedCache($this->store, 'b:');
    }

    protected function tearDown(): void
    {
        ArrayLock::reset();
        parent::tearDown();
    }

    public function test_keys_are_prefixed_and_isolated(): void
    {
        $this->a->set('user', 'alice');
        $this->b->set('user', 'bob');

        $this->assertSame('alice', $this->a->get('user'));
        $this->assertSame('bob', $this->b->get('user'));
        $this->assertSame('alice', $this->store->get('a:user'));
        $this->assertTrue($this->a->has('user'));
        $this->assertFalse($this->a->has('missing'));

        $this->a->forget('user');

        $this->assertNull($this->a->get('user'));
        $this->assertSame('bob', $this->b->get('user'));
    }

    public function test_remember_and_counters_use_the_prefixed_key(): void
    {
        $this->assertSame(5, $this->a->remember('n', 60, fn (): int => 5));
        $this->assertSame(5, $this->store->get('a:n'));

        $this->assertSame(2, $this->a->increment('hits', 2));
        $this->assertSame(1, $this->a->decrement('hits'));
        $this->assertSame(1, $this->store->get('a:hits'));
        $this->assertNull($this->store->get('hits'));
    }

    public function test_locks_are_prefixed(): void
    {
        $lock = $this->a->lock('job', 10);

        $this->assertTrue($lock->acquire());
        $this->assertTrue($this->b->lock('job', 10)->acquire()); // other prefix, other lock
        $this->assertFalse($this->store->lock('a:job', 10)->acquire());
    }

    public function test_tag_flush_only_touches_this_prefix(): void
    {
        $this->a->tags('users')->set('1', 'alice');
        $this->b->tags('users')->set('1', 'bob');

        $this->a->tags('users')->flush();

        $this->assertNull($this->a->get('1'));
        $this->assertSame('bob', $this->b->get('1'));
        $this->assertNotNull($this->store->get('b:__tag:users'));
    }

    public function test_flush_empties_the_shared_store(): void
    {
        $this->a->set('x', 1);
        $this->b->set('y', 2);

        $this->a->flush();

        $this->assertNull($this->b->get('y'));
    }

    public function test_stats_and_prefix_accessors(): void
    {
        $this->a->get('missing');

        $this->assertSame('a:', $this->a->prefix());
        $this->assertSame($this->store->stats()->misses, $this->a->stats()->misses);
    }

    public function test_empty_prefix_is_rejected(): void
    {
        $this->expectException(CacheException::class);

        new PrefixedCache($this->store, '');
    }
}
