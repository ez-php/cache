<?php

declare(strict_types=1);

namespace Tests\Cache;

use EzPhp\Cache\ArrayDriver;
use EzPhp\Cache\CacheInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * Class ArrayDriverTest
 *
 * @package Tests\Cache
 */
#[CoversClass(ArrayDriver::class)]
final class ArrayDriverTest extends TestCase
{
    private ArrayDriver $cache;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->cache = new ArrayDriver();
    }

    // ─── get / set ────────────────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_get_returns_default_for_missing_key(): void
    {
        $this->assertNull($this->cache->get('missing'));
        $this->assertSame('default', $this->cache->get('missing', 'default'));
    }

    /**
     * @return void
     */
    public function test_set_and_get_string(): void
    {
        $this->cache->set('key', 'value');

        $this->assertSame('value', $this->cache->get('key'));
    }

    /**
     * @return void
     */
    public function test_set_and_get_integer(): void
    {
        $this->cache->set('num', 42);

        $this->assertSame(42, $this->cache->get('num'));
    }

    /**
     * @return void
     */
    public function test_set_and_get_array(): void
    {
        $this->cache->set('arr', ['a' => 1]);

        $this->assertSame(['a' => 1], $this->cache->get('arr'));
    }

    /**
     * @return void
     */
    public function test_set_and_get_null_value(): void
    {
        $this->cache->set('nothing', null);

        // has() should return true — null is a valid stored value.
        $this->assertTrue($this->cache->has('nothing'));
    }

    // ─── has ─────────────────────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_has_returns_false_for_missing_key(): void
    {
        $this->assertFalse($this->cache->has('missing'));
    }

    /**
     * @return void
     */
    public function test_has_returns_true_after_set(): void
    {
        $this->cache->set('key', 'value');

        $this->assertTrue($this->cache->has('key'));
    }

    // ─── forget ───────────────────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_forget_removes_key(): void
    {
        $this->cache->set('key', 'value');
        $this->cache->forget('key');

        $this->assertFalse($this->cache->has('key'));
        $this->assertNull($this->cache->get('key'));
    }

    /**
     * @return void
     */
    public function test_forget_on_missing_key_does_not_throw(): void
    {
        $this->cache->forget('nonexistent');
        $this->assertFalse($this->cache->has('nonexistent'));
    }

    // ─── TTL ─────────────────────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_entry_with_zero_ttl_does_not_expire(): void
    {
        $this->cache->set('key', 'value', 0);

        $this->assertTrue($this->cache->has('key'));
        $this->assertSame('value', $this->cache->get('key'));
    }

    /**
     * @return void
     */
    public function test_expired_entry_returns_default(): void
    {
        $this->cache->set('key', 'value', -1); // expires in the past

        $this->assertNull($this->cache->get('key'));
        $this->assertFalse($this->cache->has('key'));
    }

    // ─── remember ────────────────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_remember_stores_and_returns_callback_result(): void
    {
        $called = 0;

        $result = $this->cache->remember('key', 0, function () use (&$called): string {
            $called++;

            return 'computed';
        });

        $this->assertSame('computed', $result);
        $this->assertSame(1, $called);
    }

    /**
     * @return void
     */
    public function test_remember_does_not_call_callback_on_hit(): void
    {
        $this->cache->set('key', 'cached');

        $called = 0;
        $result = $this->cache->remember('key', 0, function () use (&$called): string {
            $called++;

            return 'computed';
        });

        $this->assertSame('cached', $result);
        $this->assertSame(0, $called);
    }

    // ─── flush ───────────────────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_flush_clears_all_entries(): void
    {
        $this->cache->set('a', 1);
        $this->cache->set('b', 2);

        $this->cache->flush();

        $this->assertFalse($this->cache->has('a'));
        $this->assertFalse($this->cache->has('b'));
    }

    // ─── increment / decrement ───────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_increment_creates_key_with_zero_plus_amount(): void
    {
        $result = $this->cache->increment('counter');

        $this->assertSame(1, $result);
        $this->assertSame(1, $this->cache->get('counter'));
    }

    /**
     * @return void
     */
    public function test_increment_by_custom_amount(): void
    {
        $this->cache->increment('counter', 5);
        $result = $this->cache->increment('counter', 3);

        $this->assertSame(8, $result);
    }

    /**
     * @return void
     */
    public function test_increment_on_existing_key(): void
    {
        $this->cache->set('counter', 10);

        $result = $this->cache->increment('counter');

        $this->assertSame(11, $result);
    }

    /**
     * @return void
     */
    public function test_decrement_creates_key_with_zero_minus_amount(): void
    {
        $result = $this->cache->decrement('counter');

        $this->assertSame(-1, $result);
        $this->assertSame(-1, $this->cache->get('counter'));
    }

    /**
     * @return void
     */
    public function test_decrement_by_custom_amount(): void
    {
        $this->cache->set('counter', 10);

        $result = $this->cache->decrement('counter', 3);

        $this->assertSame(7, $result);
    }

    // ─── implements interface ─────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_implements_cache_interface(): void
    {
        $this->assertInstanceOf(CacheInterface::class, $this->cache);
    }
}
