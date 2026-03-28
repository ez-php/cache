<?php

declare(strict_types=1);

namespace EzPhp\Cache;

use Closure;

/**
 * Class TaggedCache
 *
 * A CacheInterface decorator that groups cache entries under one or more tags.
 *
 * set() stores the value via the underlying driver AND records the key in
 * each tag set (stored as a list<string> under "__tag:{tagname}", no TTL).
 * flush() reads each tag set, deletes all listed keys, then deletes the tag
 * sets themselves — enabling bulk invalidation of a logical group.
 *
 * All other operations delegate to the underlying driver unchanged.
 *
 * @package EzPhp\Cache
 */
final class TaggedCache implements CacheInterface
{
    /**
     * TaggedCache Constructor
     *
     * @param CacheInterface $driver The underlying cache driver.
     * @param list<string>   $tags   The tag names this instance is scoped to.
     */
    public function __construct(
        private readonly CacheInterface $driver,
        private readonly array $tags,
    ) {
    }

    /**
     * Retrieve an item from the cache.
     * Returns $default when the key is missing or expired.
     *
     * @param string $key
     * @param mixed  $default
     *
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->driver->get($key, $default);
    }

    /**
     * Store an item in the cache and record the key in each tag set.
     *
     * @param string $key
     * @param mixed  $value
     * @param int    $ttl  Seconds until expiry; 0 means never expire.
     *
     * @return void
     */
    public function set(string $key, mixed $value, int $ttl = 0): void
    {
        $this->driver->set($key, $value, $ttl);
        $this->addKeyToTags($key);
    }

    /**
     * Remove an item from the cache.
     *
     * @param string $key
     *
     * @return void
     */
    public function forget(string $key): void
    {
        $this->driver->forget($key);
    }

    /**
     * Return true if the key exists and has not expired.
     *
     * @param string $key
     *
     * @return bool
     */
    public function has(string $key): bool
    {
        return $this->driver->has($key);
    }

    /**
     * Retrieve an item from the cache, or compute and store it.
     * The stored value is tagged with this instance's tags.
     *
     * @param string           $key
     * @param int              $ttl      Seconds until expiry; 0 means never expire.
     * @param Closure(): mixed $callback Called only on a cache miss.
     *
     * @return mixed
     */
    public function remember(string $key, int $ttl, Closure $callback): mixed
    {
        if ($this->driver->has($key)) {
            return $this->driver->get($key);
        }

        $value = $callback();
        $this->set($key, $value, $ttl);

        return $value;
    }

    /**
     * Delete all keys associated with this instance's tags,
     * then delete the tag sets themselves.
     *
     * @return void
     */
    public function flush(): void
    {
        foreach ($this->tags as $tag) {
            $tagSetKey = '__tag:' . $tag;
            $keys = $this->driver->get($tagSetKey, []);

            if (!is_array($keys)) {
                $keys = [];
            }

            foreach ($keys as $key) {
                if (is_string($key)) {
                    $this->driver->forget($key);
                }
            }

            $this->driver->forget($tagSetKey);
        }
    }

    /**
     * Acquire a lock for the given key.
     *
     * @param string $key
     * @param int    $ttl
     *
     * @return LockInterface
     */
    public function lock(string $key, int $ttl = 0): LockInterface
    {
        return $this->driver->lock($key, $ttl);
    }

    /**
     * Return a new TaggedCache scoped to the merged tag list.
     *
     * @param string|list<string> $tags
     *
     * @return TaggedCache
     */
    public function tags(string|array $tags): TaggedCache
    {
        $list = is_array($tags) ? $tags : [$tags];

        return new TaggedCache($this->driver, [...$this->tags, ...$list]);
    }

    /**
     * Return cache statistics from the underlying driver.
     *
     * @return CacheStats
     */
    public function stats(): CacheStats
    {
        return $this->driver->stats();
    }

    /**
     * Record the given key in each tag set stored in the driver.
     *
     * @param string $key
     *
     * @return void
     */
    private function addKeyToTags(string $key): void
    {
        foreach ($this->tags as $tag) {
            $tagSetKey = '__tag:' . $tag;
            $existing = $this->driver->get($tagSetKey, []);

            if (!is_array($existing)) {
                $existing = [];
            }

            $this->driver->set($tagSetKey, [...$existing, $key], 0);
        }
    }
}
