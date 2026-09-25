<?php

declare(strict_types=1);

namespace EzPhp\Cache;

use Closure;
use RuntimeException;

/**
 * Class Cache
 *
 * Static facade for the CacheInterface singleton.
 * Call setInstance() (done automatically by CacheServiceProvider::boot()) before
 * using any static methods. Throws RuntimeException if called before the
 * instance is set — fail-fast prevents silently reading/writing nothing.
 *
 * Every static method is a one-line delegation to the underlying CacheInterface;
 * this class holds no cache state or logic of its own.
 *
 * @package EzPhp\Cache
 */
final class Cache
{
    private static ?CacheInterface $instance = null;

    /**
     * @var (Closure(): CacheInterface)|null
     */
    private static ?Closure $resolver = null;

    /**
     * Set the underlying CacheInterface instance directly.
     *
     * @param CacheInterface $instance
     *
     * @return void
     */
    public static function setInstance(CacheInterface $instance): void
    {
        self::$instance = $instance;
    }

    /**
     * Defer resolution of the underlying CacheInterface until the first
     * actual facade call, instead of resolving it immediately.
     *
     * Used by CacheServiceProvider::boot() so that registering the provider
     * does not force CacheInterface's container binding to resolve (and be
     * cached as a singleton) before the application has finished configuring
     * it — e.g. before test code has set CACHE_DRIVER for that test case.
     *
     * @param Closure(): CacheInterface $resolver
     *
     * @return void
     */
    public static function setResolver(Closure $resolver): void
    {
        self::$resolver = $resolver;
    }

    /**
     * Reset the CacheInterface singleton and resolver to null.
     *
     * Call this in setUp()/tearDown() of any test that touches the Cache facade.
     *
     * @return void
     */
    public static function resetInstance(): void
    {
        self::$instance = null;
        self::$resolver = null;
    }

    /**
     * @param string $key
     * @param mixed  $default
     *
     * @return mixed
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return self::instance()->get($key, $default);
    }

    /**
     * @param string $key
     * @param mixed  $value
     * @param int    $ttl
     *
     * @return void
     */
    public static function set(string $key, mixed $value, int $ttl = 0): void
    {
        self::instance()->set($key, $value, $ttl);
    }

    /**
     * @param string $key
     *
     * @return void
     */
    public static function forget(string $key): void
    {
        self::instance()->forget($key);
    }

    /**
     * @param string $key
     *
     * @return bool
     */
    public static function has(string $key): bool
    {
        return self::instance()->has($key);
    }

    /**
     * @param string           $key
     * @param int              $ttl
     * @param Closure(): mixed $callback
     *
     * @return mixed
     *
     * @phpstan-impure
     */
    public static function remember(string $key, int $ttl, Closure $callback): mixed
    {
        return self::instance()->remember($key, $ttl, $callback);
    }

    /**
     * @return void
     */
    public static function flush(): void
    {
        self::instance()->flush();
    }

    /**
     * @param string $key
     * @param int    $amount
     *
     * @return int
     */
    public static function increment(string $key, int $amount = 1): int
    {
        return self::instance()->increment($key, $amount);
    }

    /**
     * @param string $key
     * @param int    $amount
     *
     * @return int
     */
    public static function decrement(string $key, int $amount = 1): int
    {
        return self::instance()->decrement($key, $amount);
    }

    /**
     * @param string $key
     * @param int    $ttl
     *
     * @return LockInterface
     */
    public static function lock(string $key, int $ttl = 0): LockInterface
    {
        return self::instance()->lock($key, $ttl);
    }

    /**
     * @param string|list<string> $tags
     *
     * @return TaggedCache
     */
    public static function tags(string|array $tags): TaggedCache
    {
        return self::instance()->tags($tags);
    }

    /**
     * @return CacheStats
     */
    public static function stats(): CacheStats
    {
        return self::instance()->stats();
    }

    /**
     * Return the current CacheInterface instance, resolving it from the
     * deferred resolver (set via setResolver()) on first access, or throw
     * if neither an instance nor a resolver has been set.
     *
     * @return CacheInterface
     */
    private static function instance(): CacheInterface
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        if (self::$resolver !== null) {
            self::$instance = (self::$resolver)();

            return self::$instance;
        }

        throw new RuntimeException(
            'Cache instance not set. Register CacheServiceProvider or call Cache::setInstance().'
        );
    }
}
