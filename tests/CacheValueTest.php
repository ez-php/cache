<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Cache\ArrayDriver;
use EzPhp\Cache\CacheException;
use EzPhp\Cache\CacheInterface;
use EzPhp\Cache\CacheValue;
use EzPhp\Cache\FileDriver;
use EzPhp\Cache\RedisDriver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Every driver enforces the same value contract: null, scalars, arrays of those.
 *
 * Regression: File/Redis silently returned __PHP_Incomplete_Class for cached
 * objects while ArrayDriver returned the real object.
 *
 * @package Tests
 */
#[CoversClass(CacheValue::class)]
#[UsesClass(ArrayDriver::class)]
#[UsesClass(FileDriver::class)]
#[UsesClass(RedisDriver::class)]
final class CacheValueTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            foreach (glob($dir . '/*') ?: [] as $file) {
                unlink($file);
            }

            @rmdir($dir);
        }

        parent::tearDown();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function drivers(): array
    {
        return ['array' => ['array'], 'file' => ['file'], 'redis' => ['redis']];
    }

    #[DataProvider('drivers')]
    public function testScalarsAndNestedArraysRoundTrip(string $driver): void
    {
        $cache = $this->makeDriver($driver);
        $value = ['a' => 1, 'b' => [true, null, 1.5, 'x']];

        $cache->set('cache-value-ok', $value, 60);

        $this->assertSame($value, $cache->get('cache-value-ok'));
    }

    #[DataProvider('drivers')]
    public function testObjectIsRejected(string $driver): void
    {
        $cache = $this->makeDriver($driver);

        $this->expectException(CacheException::class);
        $this->expectExceptionMessage("Cache key 'cache-value-obj': cannot store an object of class ArrayObject");

        $cache->set('cache-value-obj', new \ArrayObject([1]), 60);
    }

    #[DataProvider('drivers')]
    public function testObjectNestedInArrayIsRejectedWithItsPath(string $driver): void
    {
        $cache = $this->makeDriver($driver);

        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('at [rows][1]');

        $cache->set('cache-value-nested', ['rows' => [1, new \DateTimeImmutable()]], 60);
    }

    #[DataProvider('drivers')]
    public function testRememberRejectsAnObjectResult(string $driver): void
    {
        $cache = $this->makeDriver($driver);

        $this->expectException(CacheException::class);

        $cache->remember('cache-value-remember', 60, static fn (): object => new \stdClass());
    }

    public function testResourceIsRejected(): void
    {
        $handle = fopen('php://memory', 'r');
        $this->assertIsResource($handle);

        try {
            $this->expectException(CacheException::class);
            $this->expectExceptionMessage('a resource');

            CacheValue::assertStorable('res', $handle);
        } finally {
            fclose($handle);
        }
    }

    private function makeDriver(string $driver): CacheInterface
    {
        if ($driver === 'array') {
            return new ArrayDriver();
        }

        if ($driver === 'file') {
            $dir = sys_get_temp_dir() . '/ez-cache-value-' . uniqid('', true);
            $this->dirs[] = $dir;

            return new FileDriver($dir);
        }

        if (!extension_loaded('redis')) {
            $this->markTestSkipped('ext-redis not available.');
        }

        try {
            $redis = new RedisDriver((string) (getenv('REDIS_HOST') ?: '127.0.0.1'), 6379, 1);
        } catch (\Throwable $e) {
            $this->markTestSkipped('Redis not reachable: ' . $e->getMessage());
        }

        return $redis;
    }
}
