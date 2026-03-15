<?php

declare(strict_types=1);

namespace Tests\Cache;

use EzPhp\Cache\ArrayDriver;
use EzPhp\Cache\CacheInterface;
use EzPhp\Cache\CacheServiceProvider;
use EzPhp\Cache\FileDriver;
use EzPhp\Cache\RedisDriver;
use EzPhp\Contracts\ConfigInterface;
use EzPhp\Contracts\ContainerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
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
     * Build a container with ConfigInterface pre-bound to an env-backed fake.
     */
    private function makeBootedContainer(): ContainerInterface
    {
        $config = new class () implements ConfigInterface {
            public function get(string $key, mixed $default = null): mixed
            {
                return match ($key) {
                    'cache.driver' => (getenv('CACHE_DRIVER') ?: null) ?? $default,
                    'cache.file_path' => (getenv('CACHE_PATH') ?: null) ?? $default,
                    'cache.redis.host' => (getenv('CACHE_REDIS_HOST') ?: null) ?? $default,
                    'cache.redis.port' => getenv('CACHE_REDIS_PORT') !== false
                                                ? (int) getenv('CACHE_REDIS_PORT')
                                                : $default,
                    'cache.redis.database' => getenv('CACHE_REDIS_DATABASE') !== false
                                                ? (int) getenv('CACHE_REDIS_DATABASE')
                                                : $default,
                    default => $default,
                };
            }
        };

        $container = new class ($config) implements ContainerInterface {
            /** @var array<string, callable> */
            private array $bindings = [];

            /** @var array<string, object> */
            private array $instances = [];

            public function __construct(ConfigInterface $config)
            {
                $this->instances[ConfigInterface::class] = $config;
            }

            public function bind(string $abstract, string|callable|null $factory = null): void
            {
                if (is_callable($factory)) {
                    $this->bindings[$abstract] = $factory;
                }
            }

            public function instance(string $abstract, object $instance): void
            {
                $this->instances[$abstract] = $instance;
            }

            /**
             * @template T of object
             * @param class-string<T> $abstract
             * @return T
             */
            public function make(string $abstract): mixed
            {
                if (isset($this->instances[$abstract])) {
                    /** @var T */
                    return $this->instances[$abstract];
                }

                if (isset($this->bindings[$abstract])) {
                    /** @var T */
                    return $this->instances[$abstract] = ($this->bindings[$abstract])($this);
                }

                throw new \RuntimeException("No binding registered for {$abstract}.");
            }
        };

        $provider = new CacheServiceProvider($container);
        $provider->register();

        return $container;
    }

    /**
     * @return void
     */
    public function test_default_driver_is_array(): void
    {
        putenv('CACHE_DRIVER=');

        $container = $this->makeBootedContainer();

        $this->assertInstanceOf(ArrayDriver::class, $container->make(CacheInterface::class));
    }

    /**
     * @return void
     */
    public function test_file_driver_is_created_when_configured(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/ez-cache-svc-' . uniqid();
        putenv('CACHE_DRIVER=file');
        putenv('CACHE_PATH=' . $this->cacheDir);

        $container = $this->makeBootedContainer();

        $this->assertInstanceOf(FileDriver::class, $container->make(CacheInterface::class));

        putenv('CACHE_DRIVER=');
        putenv('CACHE_PATH=');
    }

    /**
     * @return void
     */
    public function test_cache_set_and_get_via_container(): void
    {
        putenv('CACHE_DRIVER=');

        $container = $this->makeBootedContainer();
        $cache = $container->make(CacheInterface::class);
        $cache->set('greeting', 'hello');

        $this->assertSame('hello', $cache->get('greeting'));
    }

    /**
     * @return void
     */
    public function test_redis_driver_is_created_when_configured(): void
    {
        putenv('CACHE_DRIVER=redis');
        putenv('CACHE_REDIS_HOST=redis');
        putenv('CACHE_REDIS_PORT=6379');

        $container = $this->makeBootedContainer();

        $this->assertInstanceOf(RedisDriver::class, $container->make(CacheInterface::class));

        putenv('CACHE_DRIVER=');
        putenv('CACHE_REDIS_HOST=');
        putenv('CACHE_REDIS_PORT=');
    }
}
