<?php

declare(strict_types=1);

namespace EzPhp\Cache;

use EzPhp\Application\Application;
use EzPhp\Config\Config;
use EzPhp\ServiceProvider\ServiceProvider;

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
        $this->app->bind(CacheInterface::class, function (Application $app): CacheInterface {
            $config = $app->make(Config::class);
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
     * @param Config $config
     *
     * @return FileDriver
     */
    private function makeFile(Config $config): FileDriver
    {
        $path = $config->get('cache.file_path', sys_get_temp_dir() . '/ez-cache');

        return new FileDriver(is_string($path) ? $path : sys_get_temp_dir() . '/ez-cache');
    }

    /**
     * @param Config $config
     *
     * @return RedisDriver
     */
    private function makeRedis(Config $config): RedisDriver
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
