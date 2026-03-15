<?php

declare(strict_types=1);

namespace Tests\Cache;

use EzPhp\Application\Application;
use EzPhp\Cache\ArrayDriver;
use EzPhp\Cache\CacheInterface;
use EzPhp\Cache\CacheServiceProvider;
use EzPhp\Cache\FileDriver;
use EzPhp\Cache\RedisDriver;
use EzPhp\Exceptions\ApplicationException;
use EzPhp\Exceptions\ContainerException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use ReflectionException;
use Tests\TestCase;

/**
 * Class CacheServiceProviderTest
 *
 * @package Tests\Cache
 */
#[CoversClass(CacheServiceProvider::class)]
#[UsesClass(ArrayDriver::class)]
#[UsesClass(FileDriver::class)]
#[UsesClass(RedisDriver::class)]
final class CacheServiceProviderTest extends TestCase
{
    private string $cacheDir = '';

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

        parent::tearDown();
    }

    /**
     * @throws ReflectionException
     * @throws ApplicationException
     * @throws ContainerException
     */
    public function test_default_driver_is_array(): void
    {
        putenv('CACHE_DRIVER=');

        $app = new Application();
        $app->register(CacheServiceProvider::class);
        $app->bootstrap();

        $cache = $app->make(CacheInterface::class);

        $this->assertInstanceOf(ArrayDriver::class, $cache);
    }

    /**
     * @throws ReflectionException
     * @throws ApplicationException
     * @throws ContainerException
     */
    public function test_file_driver_is_created_when_configured(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/ez-cache-svc-' . uniqid();
        putenv('CACHE_DRIVER=file');
        putenv('CACHE_PATH=' . $this->cacheDir);

        $app = new Application();
        $app->register(CacheServiceProvider::class);
        $app->bootstrap();

        $cache = $app->make(CacheInterface::class);

        $this->assertInstanceOf(FileDriver::class, $cache);

        putenv('CACHE_DRIVER=');
        putenv('CACHE_PATH=');
    }

    /**
     * @throws ReflectionException
     * @throws ApplicationException
     * @throws ContainerException
     */
    public function test_cache_set_and_get_via_container(): void
    {
        putenv('CACHE_DRIVER=');

        $app = new Application();
        $app->register(CacheServiceProvider::class);
        $app->bootstrap();

        $cache = $app->make(CacheInterface::class);
        $cache->set('greeting', 'hello');

        $this->assertSame('hello', $cache->get('greeting'));
    }

    /**
     * @throws ReflectionException
     * @throws ApplicationException
     * @throws ContainerException
     */
    public function test_redis_driver_is_created_when_configured(): void
    {
        putenv('CACHE_DRIVER=redis');
        putenv('CACHE_REDIS_HOST=redis');
        putenv('CACHE_REDIS_PORT=6379');

        $app = new Application();
        $app->register(CacheServiceProvider::class);
        $app->bootstrap();

        $cache = $app->make(CacheInterface::class);

        $this->assertInstanceOf(\EzPhp\Cache\RedisDriver::class, $cache);

        putenv('CACHE_DRIVER=');
        putenv('CACHE_REDIS_HOST=');
        putenv('CACHE_REDIS_PORT=');
    }
}
