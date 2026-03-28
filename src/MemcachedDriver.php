<?php

declare(strict_types=1);

namespace EzPhp\Cache;

use Closure;
use Memcached;
use RuntimeException;

/**
 * Class MemcachedDriver
 *
 * Memcached-backed cache driver using the PHP "ext-memcached" extension.
 * Values are PHP-serialised before storage (using Memcached::OPT_SERIALIZER_PHP)
 * so any serialisable type is supported.
 *
 * TTL rules (matching CacheInterface):
 *   0   = no expiry
 *   > 0 = expire after N seconds
 *   < 0 = treated as already expired; stored with TTL = 1 so Memcached evicts it immediately
 *
 * Note: `flush()` calls `Memcached::flush()` which clears the entire Memcached
 * server, not just this module's keys. Use a dedicated Memcached instance or
 * key prefix per application if sharing a server.
 *
 * @package EzPhp\Cache
 */
final class MemcachedDriver implements CacheInterface
{
    use TaggableDriverTrait;

    private readonly Memcached $memcached;

    private int $hits = 0;

    private int $misses = 0;

    /**
     * MemcachedDriver Constructor
     *
     * @param array<array{host: string, port: int, weight?: int}> $servers
     *   List of Memcached servers. Each entry must have 'host' and 'port';
     *   'weight' is optional (default 0 = equal weight).
     *   Example: [['host' => '127.0.0.1', 'port' => 11211]]
     *
     * @throws RuntimeException if ext-memcached is not loaded.
     */
    public function __construct(array $servers = [['host' => '127.0.0.1', 'port' => 11211]])
    {
        if (!extension_loaded('memcached')) {
            throw new RuntimeException('The "memcached" PHP extension is required for the Memcached cache driver.');
        }

        $this->memcached = new Memcached();
        $this->memcached->setOption(Memcached::OPT_SERIALIZER, Memcached::SERIALIZER_PHP);

        foreach ($servers as $server) {
            $this->memcached->addServer(
                $server['host'],
                $server['port'],
                $server['weight'] ?? 0,
            );
        }
    }

    /**
     * @param string     $key
     * @param mixed|null $default
     *
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->memcached->get($key);

        if ($this->memcached->getResultCode() !== Memcached::RES_SUCCESS) {
            $this->misses++;

            return $default;
        }

        $this->hits++;

        return $value;
    }

    /**
     * @param string $key
     * @param mixed  $value
     * @param int    $ttl
     *
     * @return void
     */
    public function set(string $key, mixed $value, int $ttl = 0): void
    {
        // Negative TTL means "already expired" — store with TTL=1 so Memcached evicts it immediately.
        $expiration = $ttl < 0 ? 1 : $ttl;

        $this->memcached->set($key, $value, $expiration);
    }

    /**
     * @param string $key
     *
     * @return void
     */
    public function forget(string $key): void
    {
        $this->memcached->delete($key);
    }

    /**
     * @param string $key
     *
     * @return bool
     */
    public function has(string $key): bool
    {
        $this->memcached->get($key);

        return $this->memcached->getResultCode() === Memcached::RES_SUCCESS;
    }

    /**
     * @param string           $key
     * @param int              $ttl
     * @param Closure(): mixed $callback
     *
     * @return mixed
     */
    public function remember(string $key, int $ttl, Closure $callback): mixed
    {
        $value = $this->memcached->get($key);

        if ($this->memcached->getResultCode() === Memcached::RES_SUCCESS) {
            $this->hits++;

            return $value;
        }

        $this->misses++;

        $computed = $callback();
        $this->set($key, $computed, $ttl);

        return $computed;
    }

    /**
     * Flush the entire Memcached server.
     *
     * Warning: this clears all keys on the connected server, not just this
     * module's keys. Use a dedicated Memcached instance per application when
     * sharing is a concern.
     *
     * @return void
     */
    public function flush(): void
    {
        $this->memcached->flush();
    }

    /**
     * Acquire a non-blocking Memcached lock for the given key.
     *
     * @param string $key
     * @param int    $ttl
     *
     * @return LockInterface
     */
    public function lock(string $key, int $ttl = 0): LockInterface
    {
        return new MemcachedLock($this->memcached, $key, $ttl);
    }

    /**
     * Return hit/miss statistics for this driver instance.
     *
     * @return CacheStats
     */
    public function stats(): CacheStats
    {
        return new CacheStats($this->hits, $this->misses);
    }
}
