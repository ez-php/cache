<?php

declare(strict_types=1);

namespace EzPhp\Cache;

use Closure;

/**
 * Class PrefixedCache
 *
 * Decorator that prefixes every key before it reaches the wrapped driver, so
 * several applications or tenants can share one Redis database, Memcached pool,
 * cache table or directory without colliding.
 *
 * Every key-based operation is prefixed: get/set/forget/has/remember,
 * increment/decrement and lock(). tags() builds the TaggedCache on this
 * decorator, so tagged entries and the `__tag:{name}` lists are prefixed too —
 * flushing a tag only touches this prefix's entries.
 *
 * flush() and stats() are not key-based and delegate unchanged: flush() empties
 * the whole underlying store (every prefix), exactly as on the bare driver. To
 * invalidate only this prefix's entries, tag them and flush the tag.
 *
 * @package EzPhp\Cache
 */
final class PrefixedCache implements CacheInterface
{
    use TaggableDriverTrait;

    /**
     * @param CacheInterface $driver The store being shared.
     * @param string         $prefix Prepended to every key, e.g. 'tenant-42:'.
     *
     * @throws CacheException When the prefix is empty.
     */
    public function __construct(
        private readonly CacheInterface $driver,
        private readonly string $prefix,
    ) {
        if ($prefix === '') {
            throw new CacheException('PrefixedCache needs a non-empty prefix.');
        }
    }

    /**
     * The prefix this decorator applies.
     *
     * @return string
     */
    public function prefix(): string
    {
        return $this->prefix;
    }

    /**
     * The wrapped store — e.g. for maintenance that is not key-based (`cache:prune`).
     *
     * @return CacheInterface
     */
    public function inner(): CacheInterface
    {
        return $this->driver;
    }

    /**
     * {@inheritDoc}
     */
    public function lock(string $key, int $ttl = 0): LockInterface
    {
        return $this->driver->lock($this->prefix . $key, $ttl);
    }

    /**
     * {@inheritDoc}
     */
    public function stats(): CacheStats
    {
        return $this->driver->stats();
    }

    /**
     * {@inheritDoc}
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->driver->get($this->prefix . $key, $default);
    }

    /**
     * {@inheritDoc}
     */
    public function set(string $key, mixed $value, int $ttl = 0): void
    {
        $this->driver->set($this->prefix . $key, $value, $ttl);
    }

    /**
     * {@inheritDoc}
     */
    public function forget(string $key): void
    {
        $this->driver->forget($this->prefix . $key);
    }

    /**
     * {@inheritDoc}
     */
    public function has(string $key): bool
    {
        return $this->driver->has($this->prefix . $key);
    }

    /**
     * {@inheritDoc}
     */
    public function remember(string $key, int $ttl, Closure $callback): mixed
    {
        return $this->driver->remember($this->prefix . $key, $ttl, $callback);
    }

    /**
     * Empties the whole underlying store — every prefix, not only this one.
     *
     * @return void
     */
    public function flush(): void
    {
        $this->driver->flush();
    }

    /**
     * {@inheritDoc}
     */
    public function increment(string $key, int $amount = 1): int
    {
        return $this->driver->increment($this->prefix . $key, $amount);
    }

    /**
     * {@inheritDoc}
     */
    public function decrement(string $key, int $amount = 1): int
    {
        return $this->driver->decrement($this->prefix . $key, $amount);
    }
}
