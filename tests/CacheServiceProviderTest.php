<?php

declare(strict_types=1);

namespace Tests\Cache;

use EzPhp\Application\Application;
use EzPhp\Cache\ArrayDriver;
use EzPhp\Cache\CacheInterface;
use EzPhp\Cache\CacheServiceProvider;
use EzPhp\Cache\FileDriver;
use EzPhp\Cache\RedisDriver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Class CacheServiceProviderTest
 *
 * @package Tests\Cache
 */
#[CoversClass(CacheServiceProvider::class)]
#[UsesClass(ArrayDriver::class)]
#[UsesClass(FileDriver::class)]
#[UsesClass(RedisDriver::class)]
final class CacheServiceProviderTest extends ApplicationTestCase
{
    private string $cacheDir = '';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        putenv('CACHE_DRIVER=');
        putenv('CACHE_PATH=');
        putenv('CACHE_REDIS_HOST=');
        putenv('CACHE_REDIS_PORT=');

        parent::setUp();
    }

    /**
     * @param Application $app
     *
     * @return void
     */
    protected function configureApplication(Application $app): void
    {
        $app->register(CacheServiceProvider::class);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        if ($this->cacheDir !== '' && is_dir($this->cacheDir)) {
            foreach (glob($this->cacheDir . '/*.cache') ?: [] as $f) {
                unlink($f);
            }

            rmdir($this->cacheDir);
        }

        putenv('CACHE_DRIVER=');
        putenv('CACHE_PATH=');
        putenv('CACHE_REDIS_HOST=');
        putenv('CACHE_REDIS_PORT=');

        parent::tearDown();
    }

    /**
     * @return void
     * @throws \ReflectionException
     */
    public function test_default_driver_is_array(): void
    {
        $this->assertInstanceOf(ArrayDriver::class, $this->app()->make(CacheInterface::class));
    }

    /**
     * @return void
     * @throws \ReflectionException
     */
    public function test_file_driver_is_created_when_configured(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/ez-cache-svc-' . uniqid();
        putenv('CACHE_DRIVER=file');
        putenv('CACHE_PATH=' . $this->cacheDir);

        $this->assertInstanceOf(FileDriver::class, $this->app()->make(CacheInterface::class));
    }

    /**
     * @return void
     * @throws \ReflectionException
     */
    public function test_cache_set_and_get_via_container(): void
    {
        $cache = $this->app()->make(CacheInterface::class);
        $cache->set('greeting', 'hello');

        $this->assertSame('hello', $cache->get('greeting'));
    }

    /**
     * @return void
     * @throws \ReflectionException
     */
    public function test_redis_driver_is_created_when_configured(): void
    {
        putenv('CACHE_DRIVER=redis');
        putenv('CACHE_REDIS_HOST=redis');
        putenv('CACHE_REDIS_PORT=6379');

        $this->assertInstanceOf(RedisDriver::class, $this->app()->make(CacheInterface::class));
    }
}
