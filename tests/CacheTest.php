<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Cache\ArrayDriver;
use EzPhp\Cache\Cache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Class CacheTest
 *
 * @package Tests
 */
#[CoversClass(Cache::class)]
#[UsesClass(ArrayDriver::class)]
final class CacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::resetInstance();
    }

    protected function tearDown(): void
    {
        Cache::resetInstance();
        parent::tearDown();
    }

    public function testThrowsWhenInstanceNotSet(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Cache instance not set/');

        Cache::get('key');
    }

    public function testSetAndGetDelegateToInstance(): void
    {
        Cache::setInstance(new ArrayDriver());

        Cache::set('name', 'Alice');

        $this->assertSame('Alice', Cache::get('name'));
    }

    public function testGetReturnsDefaultOnMiss(): void
    {
        Cache::setInstance(new ArrayDriver());

        $this->assertSame('fallback', Cache::get('missing', 'fallback'));
    }

    public function testHasDelegatesToInstance(): void
    {
        Cache::setInstance(new ArrayDriver());

        $this->assertFalse(Cache::has('key'));
        Cache::set('key', 'value');
        $this->assertTrue(Cache::has('key'));
    }

    public function testForgetDelegatesToInstance(): void
    {
        Cache::setInstance(new ArrayDriver());

        Cache::set('key', 'value');
        Cache::forget('key');

        $this->assertFalse(Cache::has('key'));
    }

    public function testRememberDelegatesToInstance(): void
    {
        Cache::setInstance(new ArrayDriver());

        $calls = 0;
        $callback = function () use (&$calls): string {
            $calls++;
            return 'computed';
        };

        $miss = Cache::remember('key', 0, $callback);
        $hit = Cache::remember('key', 0, $callback);

        $this->assertSame(['computed', 'computed'], [$miss, $hit]);
        $this->assertSame(1, $calls, 'callback must only run on the first (miss) call');
    }

    public function testFlushDelegatesToInstance(): void
    {
        Cache::setInstance(new ArrayDriver());

        Cache::set('key', 'value');
        Cache::flush();

        $this->assertFalse(Cache::has('key'));
    }

    public function testIncrementAndDecrementDelegateToInstance(): void
    {
        Cache::setInstance(new ArrayDriver());

        $this->assertSame(1, Cache::increment('counter'));
        $this->assertSame(3, Cache::increment('counter', 2));
        $this->assertSame(2, Cache::decrement('counter'));
    }

    public function testLockDelegatesToInstance(): void
    {
        $instance = new ArrayDriver();
        Cache::setInstance($instance);

        $lock = Cache::lock('resource');

        $this->assertTrue($lock->acquire());
    }

    public function testTagsDelegatesToInstance(): void
    {
        Cache::setInstance(new ArrayDriver());

        $tagged = Cache::tags('users');
        $tagged->set('key', 'value');

        $this->assertSame('value', $tagged->get('key'));
    }

    public function testStatsDelegatesToInstance(): void
    {
        Cache::setInstance(new ArrayDriver());

        Cache::get('missing');
        $stats = Cache::stats();

        $this->assertSame(1, $stats->misses);
    }

    public function testResetCausesThrowOnNextCall(): void
    {
        Cache::setInstance(new ArrayDriver());
        Cache::resetInstance();

        $this->expectException(\RuntimeException::class);
        Cache::get('key');
    }
}
