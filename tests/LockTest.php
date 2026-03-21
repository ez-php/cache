<?php

declare(strict_types=1);

namespace Tests\Cache;

use EzPhp\Cache\ArrayLock;
use EzPhp\Cache\FileLock;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * Class LockTest
 *
 * Tests ArrayLock and FileLock (no Redis required).
 *
 * @package Tests\Cache
 */
#[CoversClass(ArrayLock::class)]
#[CoversClass(FileLock::class)]
final class LockTest extends TestCase
{
    private string $lockDir;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        ArrayLock::reset();
        $this->lockDir = sys_get_temp_dir() . '/ez-lock-test-' . uniqid();
        mkdir($this->lockDir, 0o755, true);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        ArrayLock::reset();

        foreach (glob($this->lockDir . '/*.lock') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->lockDir);

        parent::tearDown();
    }

    // ─── ArrayLock ────────────────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_array_lock_acquire_returns_true_on_first_call(): void
    {
        $lock = new ArrayLock('my-key', 0);

        $this->assertTrue($lock->acquire());
    }

    /**
     * @return void
     */
    public function test_array_lock_second_acquire_on_same_key_returns_false(): void
    {
        $lock1 = new ArrayLock('my-key', 0);
        $lock2 = new ArrayLock('my-key', 0);

        $lock1->acquire();

        $this->assertFalse($lock2->acquire());
    }

    /**
     * @return void
     */
    public function test_array_lock_can_be_reacquired_after_release(): void
    {
        $lock1 = new ArrayLock('my-key', 0);
        $lock2 = new ArrayLock('my-key', 0);

        $lock1->acquire();
        $lock1->release();

        $this->assertTrue($lock2->acquire());
    }

    /**
     * @return void
     */
    public function test_array_lock_ttl_expired_lock_can_be_stolen(): void
    {
        // Use TTL=-1 so the lock expires immediately (expiry = time() - 1).
        $lock1 = new ArrayLock('my-key', -1);
        $lock2 = new ArrayLock('my-key', 0);

        $lock1->acquire();

        // The lock is already expired, so a new acquire should succeed.
        $this->assertTrue($lock2->acquire());
    }

    /**
     * @return void
     */
    public function test_array_lock_get_runs_callback_and_returns_result(): void
    {
        $lock = new ArrayLock('cb-key', 0);

        $result = $lock->get(fn (): string => 'computed');

        $this->assertSame('computed', $result);
    }

    /**
     * @return void
     */
    public function test_array_lock_get_returns_null_when_locked(): void
    {
        $lock1 = new ArrayLock('cb-key', 0);
        $lock2 = new ArrayLock('cb-key', 0);

        $lock1->acquire();

        $result = $lock2->get(fn (): string => 'computed');

        $this->assertNull($result);
    }

    /**
     * @return void
     */
    public function test_array_lock_get_releases_after_callback(): void
    {
        $lock1 = new ArrayLock('cb-key', 0);
        $lock2 = new ArrayLock('cb-key', 0);

        $lock1->get(fn (): string => 'done');

        // After get() completes, the lock should be released.
        $this->assertTrue($lock2->acquire());
    }

    // ─── FileLock ─────────────────────────────────────────────────────────────

    /**
     * @return void
     */
    public function test_file_lock_acquire_returns_true(): void
    {
        $path = $this->lockDir . '/test.lock';
        $lock = new FileLock($path, 0);

        $acquired = $lock->acquire();
        $lock->release();

        $this->assertTrue($acquired);
    }

    /**
     * @return void
     */
    public function test_file_lock_second_acquire_returns_false(): void
    {
        $path = $this->lockDir . '/test2.lock';
        $lock1 = new FileLock($path, 0);
        $lock2 = new FileLock($path, 0);

        $lock1->acquire();

        $this->assertFalse($lock2->acquire());

        $lock1->release();
    }

    /**
     * @return void
     */
    public function test_file_lock_release_allows_reacquire(): void
    {
        $path = $this->lockDir . '/test3.lock';
        $lock1 = new FileLock($path, 0);
        $lock2 = new FileLock($path, 0);

        $lock1->acquire();
        $lock1->release();

        $acquired = $lock2->acquire();
        $lock2->release();

        $this->assertTrue($acquired);
    }

    /**
     * @return void
     */
    public function test_file_lock_get_runs_callback(): void
    {
        $path = $this->lockDir . '/test4.lock';
        $lock = new FileLock($path, 0);

        $result = $lock->get(fn (): int => 42);

        $this->assertSame(42, $result);
    }
}
