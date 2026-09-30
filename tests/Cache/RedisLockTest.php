<?php

declare(strict_types=1);

namespace Tests\Cache;

use EzPhp\Cache\RedisLock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use RuntimeException;
use Tests\TestCase;

/**
 * Class RedisLockTest
 *
 * Unit tests for RedisLock against a mocked phpredis client (no Redis server required).
 *
 * @package Tests\Cache
 */
#[CoversClass(RedisLock::class)]
#[RequiresPhpExtension('redis')]
final class RedisLockTest extends TestCase
{
    /**
     * @return void
     */
    public function test_acquire_sets_key_with_nx_and_millisecond_ttl(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::once())
            ->method('set')
            ->with('lock:a', self::matchesRegularExpression('/^[0-9a-f]{32}$/'), ['nx', 'px' => 30000])
            ->willReturn(true);

        self::assertTrue((new RedisLock($redis, 'lock:a', 30))->acquire());
    }

    /**
     * @return void
     */
    public function test_acquire_without_ttl_sends_no_expiry(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::once())
            ->method('set')
            ->with('lock:a', self::matchesRegularExpression('/^[0-9a-f]{32}$/'), ['nx'])
            ->willReturn(true);

        self::assertTrue((new RedisLock($redis, 'lock:a', 0))->acquire());
    }

    /**
     * @return void
     */
    public function test_acquire_returns_false_when_key_exists(): void
    {
        $redis = $this->createStub(\Redis::class);
        $redis->method('set')->willReturn(false);

        self::assertFalse((new RedisLock($redis, 'lock:a', 5))->acquire());
    }

    /**
     * @return void
     */
    public function test_release_compare_and_deletes_with_the_acquired_token(): void
    {
        $token = null;
        $redis = $this->createMock(\Redis::class);
        $redis->method('set')->willReturnCallback(static function (string $key, string $value) use (&$token): bool {
            $token = $value;

            return true;
        });
        $redis->expects(self::never())->method('del');
        $redis->expects(self::once())
            ->method('eval')
            ->with(self::stringContains("redis.call('GET', KEYS[1]) == ARGV[1]"), self::callback(
                static function (array $args) use (&$token): bool {
                    return $args === ['lock:a', $token];
                },
            ), 1)
            ->willReturn(1);

        $lock = new RedisLock($redis, 'lock:a', 5);
        self::assertTrue($lock->acquire());
        $lock->release();
    }

    /**
     * @return void
     */
    public function test_release_without_acquire_touches_nothing(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::never())->method('del');
        $redis->expects(self::never())->method('eval');

        (new RedisLock($redis, 'lock:a', 5))->release();
    }

    /**
     * @return void
     */
    public function test_force_release_deletes_the_key(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::once())->method('del')->with('lock:a');

        (new RedisLock($redis, 'lock:a', 5))->forceRelease();
    }

    /**
     * @return void
     */
    public function test_get_runs_callback_and_releases(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->method('set')->willReturn(true);
        $redis->expects(self::once())->method('eval')->willReturn(1);

        self::assertSame(42, (new RedisLock($redis, 'lock:a', 5))->get(static fn (): int => 42));
    }

    /**
     * @return void
     */
    public function test_get_returns_null_without_running_callback_when_lock_is_held(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->method('set')->willReturn(false);
        $redis->expects(self::never())->method('eval');

        $ran = false;
        $result = (new RedisLock($redis, 'lock:a', 5))->get(static function () use (&$ran): int {
            $ran = true;

            return 1;
        });

        self::assertNull($result);
        self::assertFalse($ran);
    }

    /**
     * @return void
     */
    public function test_get_releases_when_callback_throws(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->method('set')->willReturn(true);
        $redis->expects(self::once())->method('eval')->willReturn(1);

        $this->expectException(RuntimeException::class);

        (new RedisLock($redis, 'lock:a', 5))->get(static function (): never {
            throw new RuntimeException('boom');
        });
    }
}
