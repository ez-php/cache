<?php

declare(strict_types=1);

namespace EzPhp\Cache;

use EzPhp\Contracts\ConfigInterface;
use EzPhp\Contracts\ContainerInterface;
use EzPhp\Contracts\ServiceProvider;

/**
 * Class CacheServiceProvider
 *
 * Binds CacheInterface to the driver configured via config/cache.php.
 *
 * Supported drivers: array, file, redis
 * Default: array
 *
 * @package EzPhp\Cache
 */
final class CacheServiceProvider extends ServiceProvider
{
    /**
     * @return void
     */
    public function register(): void
    {
        $this->app->bind(CacheInterface::class, function (ContainerInterface $app): CacheInterface {
            $config = $app->make(ConfigInterface::class);
            $driver = $config->get('cache.driver', 'array');
            $driver = is_string($driver) ? $driver : 'array';

            return match ($driver) {
                'file' => $this->makeFile($config),
                'redis' => $this->makeRedis($config),
                default => new ArrayDriver(),
            };
        });
    }

    /**
     * @param ConfigInterface $config
     *
     * @return FileDriver
     */
    private function makeFile(ConfigInterface $config): FileDriver
    {
        $path = $config->get('cache.file_path', sys_get_temp_dir() . '/ez-cache');

        return new FileDriver(is_string($path) ? $path : sys_get_temp_dir() . '/ez-cache');
    }

    /**
     * @param ConfigInterface $config
     *
     * @return RedisDriver
     */
    private function makeRedis(ConfigInterface $config): RedisDriver
    {
        $host = $config->get('cache.redis.host', '127.0.0.1');
        $port = $config->get('cache.redis.port', 6379);
        $db = $config->get('cache.redis.database', 0);

        return new RedisDriver(
            is_string($host) ? $host : '127.0.0.1',
            is_int($port) ? $port : 6379,
            is_int($db) ? $db : 0,
        );
    }
}
