<?php

declare(strict_types=1);

namespace EzPhp\Cache;

use EzPhp\Cache\Console\CacheClearCommand;
use EzPhp\Cache\Console\CachePruneCommand;
use EzPhp\Contracts\ConfigInterface;
use EzPhp\Contracts\ContainerInterface;
use EzPhp\Contracts\DatabaseInterface;
use EzPhp\Contracts\ServiceProvider;

/**
 * Class CacheServiceProvider
 *
 * Binds CacheInterface to the driver configured via config/cache.php.
 *
 * Supported drivers: array, file, redis, memcached, database
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

            $cache = match ($driver) {
                'file' => $this->makeFile($config),
                'redis' => $this->makeRedis($config),
                'memcached' => $this->makeMemcached($config),
                'database' => $this->makeDatabase($config),
                'apcu' => new ApcuDriver(),
                default => new ArrayDriver(),
            };

            // Optional key namespace for stores shared between applications or tenants.
            $prefix = $config->get('cache.prefix', '');

            return is_string($prefix) && $prefix !== '' ? new PrefixedCache($cache, $prefix) : $cache;
        });
    }

    /**
     * Wire the static Cache facade to CacheInterface, deferring actual
     * resolution to the first facade call (see Cache::setResolver()) rather
     * than resolving — and thereby permanently caching — the container
     * binding here in boot(), before the application may have finished
     * configuring it (e.g. env vars set after bootstrap in some test setups).
     *
     * @return void
     */
    public function boot(): void
    {
        Cache::setResolver(fn (): CacheInterface => $this->app->make(CacheInterface::class));

        // Console commands need ez-php/console (a suggest); registered by class name, so
        // nothing is autoloaded unless the application's console runs them.
        $this->registerCommand(CacheClearCommand::class);
        $this->registerCommand(CachePruneCommand::class);
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

    /**
     * @param ConfigInterface $config
     *
     * @return DatabaseDriver
     */
    private function makeDatabase(ConfigInterface $config): DatabaseDriver
    {
        $table = $config->get('cache.database.table', 'cache');
        $lockTable = $config->get('cache.database.lock_table', 'cache_locks');

        return new DatabaseDriver(
            $this->app->make(DatabaseInterface::class),
            is_string($table) ? $table : 'cache',
            is_string($lockTable) ? $lockTable : 'cache_locks',
        );
    }

    /**
     * @param ConfigInterface $config
     *
     * @return MemcachedDriver
     */
    private function makeMemcached(ConfigInterface $config): MemcachedDriver
    {
        $host = $config->get('cache.memcached.host', '127.0.0.1');
        $port = $config->get('cache.memcached.port', 11211);
        $weight = $config->get('cache.memcached.weight', 0);

        return new MemcachedDriver([
            [
                'host' => is_string($host) ? $host : '127.0.0.1',
                'port' => is_int($port) ? $port : 11211,
                'weight' => is_int($weight) ? $weight : 0,
            ],
        ]);
    }
}
