<?php

declare(strict_types=1);

namespace Tests\Cache;

use EzPhp\Cache\CacheInterface;
use EzPhp\Cache\FileDriver;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * Class FileDriverTest
 *
 * @package Tests\Cache
 */
#[CoversClass(FileDriver::class)]
final class FileDriverTest extends TestCase
{
    private string $dir;

    private FileDriver $cache;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/ez-cache-test-' . uniqid();
        $this->cache = new FileDriver($this->dir);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $this->cache->flush();

        if (is_dir($this->dir)) {
            rmdir($this->dir);
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
        $this->cache->set('num', 99);

        $this->assertSame(99, $this->cache->get('num'));
    }

    /**
     * @return void
     */
    public function test_set_and_get_array(): void
    {
        $this->cache->set('data', ['x' => true]);

        $this->assertSame(['x' => true], $this->cache->get('data'));
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
        $this->cache->set('key', 'value', -1);

        $this->assertNull($this->cache->get('key'));
        $this->assertFalse($this->cache->has('key'));
    }

    /**
     * @return void
     */
    public function test_expired_entry_file_is_deleted(): void
    {
        $this->cache->set('key', 'value', -1);
        $this->cache->get('key'); // triggers expiry eviction

        // Directory should contain no .cache files.
        $files = glob($this->dir . '/*.cache') ?: [];
        $this->assertEmpty($files);
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
    public function test_flush_removes_all_cache_files(): void
    {
        $this->cache->set('a', 1);
        $this->cache->set('b', 2);

        $this->cache->flush();

        $this->assertFalse($this->cache->has('a'));
        $this->assertFalse($this->cache->has('b'));
    }

    // ─── directory creation ───────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_constructor_creates_directory_if_missing(): void
    {
        $newDir = sys_get_temp_dir() . '/ez-cache-new-' . uniqid();
        $this->assertDirectoryDoesNotExist($newDir);

        $driver = new FileDriver($newDir);
        $driver->flush();

        $this->assertDirectoryExists($newDir);
        rmdir($newDir);
    }

    // ─── increment / decrement ───────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_increment_creates_key_and_returns_new_value(): void
    {
        $result = $this->cache->increment('counter');

        $this->assertSame(1, $result);
        $this->assertSame(1, $this->cache->get('counter'));
    }

    /**
     * @return void
     */
    public function test_increment_on_existing_key(): void
    {
        $this->cache->set('counter', 10);

        $result = $this->cache->increment('counter', 5);

        $this->assertSame(15, $result);
    }

    /**
     * @return void
     */
    public function test_decrement_creates_key_and_returns_new_value(): void
    {
        $result = $this->cache->decrement('counter');

        $this->assertSame(-1, $result);
    }

    /**
     * @return void
     */
    public function test_decrement_on_existing_key(): void
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
