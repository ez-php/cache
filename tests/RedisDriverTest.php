<?php

declare(strict_types=1);

namespace Tests\Cache;

use EzPhp\Cache\CacheInterface;
use EzPhp\Cache\RedisDriver;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;
use Throwable;

/**
 * Class RedisDriverTest
 *
 * Integration tests for the Redis cache driver.
 * All tests are skipped when the "redis" PHP extension is not loaded
 * or the configured Redis server is unreachable.
 *
 * @package Tests\Cache
 */
#[CoversClass(RedisDriver::class)]
final class RedisDriverTest extends TestCase
{
    private const string HOST = 'redis';
    private const int PORT = 6379;
    private const int DB = 1; // use DB 1 to avoid touching production data

    private RedisDriver $cache;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded('redis')) {
            $this->markTestSkipped('The "redis" PHP extension is not loaded.');
        }

        try {
            $this->cache = new RedisDriver(self::HOST, self::PORT, self::DB);
            $this->cache->flush();
        } catch (Throwable $e) {
            $this->markTestSkipped('Redis server not reachable: ' . $e->getMessage());
        }
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        if (isset($this->cache)) {
            $this->cache->flush();
        }

        parent::tearDown();
    }

    // ─── get / set ────────────────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_get_returns_default_for_missing_key(): void
    {
        $this->assertNull($this->cache->get('missing'));
        $this->assertSame('fallback', $this->cache->get('missing', 'fallback'));
    }

    /**
     * @return void
     */
    public function test_set_and_get_string(): void
    {
        $this->cache->set('key', 'hello');

        $this->assertSame('hello', $this->cache->get('key'));
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
        $this->cache->set('data', ['a' => 1, 'b' => 2]);

        $this->assertSame(['a' => 1, 'b' => 2], $this->cache->get('data'));
    }

    /**
     * @return void
     */
    public function test_set_and_get_null_value(): void
    {
        $this->cache->set('nothing', null);

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

    // ─── TTL ─────────────────────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_entry_with_zero_ttl_does_not_expire(): void
    {
        $this->cache->set('key', 'eternal', 0);

        $this->assertTrue($this->cache->has('key'));
    }

    /**
     * @return void
     */
    public function test_entry_expires_after_ttl(): void
    {
        $this->cache->set('key', 'value', 1); // 1 second TTL

        $this->assertTrue($this->cache->has('key'));

        sleep(2);

        $this->assertFalse($this->cache->has('key'));
        $this->assertNull($this->cache->get('key'));
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
        $this->assertSame('computed', $this->cache->get('key'));
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
    public function test_flush_clears_all_keys(): void
    {
        $this->cache->set('a', 1);
        $this->cache->set('b', 2);

        $this->cache->flush();

        $this->assertFalse($this->cache->has('a'));
        $this->assertFalse($this->cache->has('b'));
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
