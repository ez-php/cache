<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Cache\ArrayLock;
use EzPhp\Cache\MemcachedLock;
use EzPhp\Cache\RedisLock;
use Memcached;
use PHPUnit\Framework\Attributes\CoversClass;
use Redis;
use Throwable;

/**
 * Class CacheLockOwnershipTest
 *
 * release() only removes a lock the instance itself acquired; forceRelease()
 * removes it regardless. Covers the case a TTL makes possible: holder A's lock
 * expires, B takes it, and A's late release() must not delete B's lock.
 *
 * @package Tests
 */
#[CoversClass(ArrayLock::class)]
#[CoversClass(RedisLock::class)]
#[CoversClass(MemcachedLock::class)]
final class CacheLockOwnershipTest extends TestCase
{
    private const string KEY = 'ownership-lock';

    private ?Redis $redis = null;

    private ?Memcached $memcached = null;

    protected function setUp(): void
    {
        parent::setUp();
        ArrayLock::reset();
    }

    protected function tearDown(): void
    {
        ArrayLock::reset();
        $this->redis?->del(self::KEY);
        $this->memcached?->delete(self::KEY);
        parent::tearDown();
    }

    // ─── ArrayLock ────────────────────────────────────────────────────────────

    public function test_array_lock_late_release_keeps_the_new_holders_lock(): void
    {
        $old = new ArrayLock(self::KEY, -1); // already expired once taken
        $new = new ArrayLock(self::KEY, 0);

        self::assertTrue($old->acquire());
        self::assertTrue($new->acquire());

        $old->release();

        self::assertFalse((new ArrayLock(self::KEY, 0))->acquire());
    }

    public function test_array_lock_release_by_non_holder_is_a_noop(): void
    {
        self::assertTrue((new ArrayLock(self::KEY, 0))->acquire());

        (new ArrayLock(self::KEY, 0))->release();

        self::assertFalse((new ArrayLock(self::KEY, 0))->acquire());
    }

    public function test_array_lock_force_release_removes_any_holder(): void
    {
        self::assertTrue((new ArrayLock(self::KEY, 0))->acquire());

        (new ArrayLock(self::KEY, 0))->forceRelease();

        self::assertTrue((new ArrayLock(self::KEY, 0))->acquire());
    }

    // ─── RedisLock ────────────────────────────────────────────────────────────

    public function test_redis_lock_late_release_keeps_the_new_holders_lock(): void
    {
        $redis = $this->redis();
        $old = new RedisLock($redis, self::KEY, 30);
        self::assertTrue($old->acquire());

        // Simulates: the TTL ran out and another process took the lock.
        $redis->set(self::KEY, 'other-owner');

        $old->release();

        self::assertSame('other-owner', $redis->get(self::KEY));
    }

    public function test_redis_lock_release_by_non_holder_is_a_noop(): void
    {
        $redis = $this->redis();
        self::assertTrue((new RedisLock($redis, self::KEY, 30))->acquire());

        (new RedisLock($redis, self::KEY, 30))->release();

        self::assertFalse((new RedisLock($redis, self::KEY, 30))->acquire());
    }

    public function test_redis_lock_owner_release_and_force_release(): void
    {
        $redis = $this->redis();
        $lock = new RedisLock($redis, self::KEY, 30);

        self::assertTrue($lock->acquire());
        $lock->release();
        self::assertTrue($lock->acquire());

        (new RedisLock($redis, self::KEY, 30))->forceRelease();

        self::assertTrue((new RedisLock($redis, self::KEY, 30))->acquire());
    }

    // ─── MemcachedLock ────────────────────────────────────────────────────────

    public function test_memcached_lock_late_release_keeps_the_new_holders_lock(): void
    {
        $memcached = $this->memcached();
        $old = new MemcachedLock($memcached, self::KEY, 30);
        self::assertTrue($old->acquire());

        $memcached->set(self::KEY, 'other-owner', 30);

        $old->release();

        self::assertSame('other-owner', $memcached->get(self::KEY));
    }

    public function test_memcached_lock_release_by_non_holder_is_a_noop(): void
    {
        $memcached = $this->memcached();
        self::assertTrue((new MemcachedLock($memcached, self::KEY, 30))->acquire());

        (new MemcachedLock($memcached, self::KEY, 30))->release();

        self::assertFalse((new MemcachedLock($memcached, self::KEY, 30))->acquire());
    }

    public function test_memcached_lock_owner_release_and_force_release(): void
    {
        $memcached = $this->memcached();
        $lock = new MemcachedLock($memcached, self::KEY, 30);

        self::assertTrue($lock->acquire());
        $lock->release();
        self::assertTrue($lock->acquire());

        (new MemcachedLock($memcached, self::KEY, 30))->forceRelease();

        self::assertTrue((new MemcachedLock($memcached, self::KEY, 30))->acquire());
    }

    // ─── helpers ──────────────────────────────────────────────────────────────

    private function redis(): Redis
    {
        if (!extension_loaded('redis')) {
            self::markTestSkipped('The "redis" PHP extension is not loaded.');
        }

        try {
            $redis = new Redis();
            $redis->connect('redis', 6379);
            $redis->select(1);
            $redis->del(self::KEY);
        } catch (Throwable $e) {
            self::markTestSkipped('Redis server not reachable: ' . $e->getMessage());
        }

        return $this->redis = $redis;
    }

    private function memcached(): Memcached
    {
        if (!extension_loaded('memcached')) {
            self::markTestSkipped('The "memcached" PHP extension is not loaded.');
        }

        $memcached = new Memcached();
        $memcached->addServer('memcached', 11211);
        $memcached->delete(self::KEY);

        if (!$memcached->set('__probe__', '1') || $memcached->get('__probe__') !== '1') {
            self::markTestSkipped('Memcached server is not reachable.');
        }

        return $this->memcached = $memcached;
    }
}
