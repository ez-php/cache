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
 * Values are `serialize()`d to strings by the driver itself and restored with
 * `unserialize(..., ['allowed_classes' => false])`, exactly like RedisDriver, so a
 * tampered *string* entry can never instantiate a class (see CacheValue). The
 * driver never hands ext-memcached a non-string value; an entry that ext-memcached
 * decodes to anything but a string was not written by this driver and is reported
 * as a miss. Note that ext-memcached decodes by the *item's* flags: an entry written
 * by another client with the PHP-serializer flag is unserialized by the extension
 * itself before this driver sees it, so Memcached must still not be writable by
 * untrusted clients.
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
        [$found, $value] = $this->read($key);

        if (!$found) {
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
        CacheValue::assertStorable($key, $value);

        // Negative TTL means "already expired" — store with TTL=1 so Memcached evicts it immediately.
        $expiration = $ttl < 0 ? 1 : $ttl;

        $this->memcached->set($key, serialize($value), $expiration);
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
        return $this->read($key)[0];
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
        [$found, $value] = $this->read($key);

        if ($found) {
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
     * Increment a numeric value. Creates the key (starting at 0) if absent.
     *
     * Read-modify-write — set() stores serialize()d strings, so native
     * increment is not compatible with values written by set().
     *
     * @param string $key
     * @param int    $amount
     *
     * @return int
     */
    public function increment(string $key, int $amount = 1): int
    {
        $raw = $this->get($key, 0);
        $current = is_int($raw) ? $raw : (int) (is_scalar($raw) ? $raw : 0);
        $new = $current + $amount;
        $this->set($key, $new);

        return $new;
    }

    /**
     * Decrement a numeric value. Creates the key (starting at 0) if absent.
     *
     * Read-modify-write — set() stores serialize()d strings, so native
     * decrement is not compatible with values written by set().
     *
     * @param string $key
     * @param int    $amount
     *
     * @return int
     */
    public function decrement(string $key, int $amount = 1): int
    {
        $raw = $this->get($key, 0);
        $current = is_int($raw) ? $raw : (int) (is_scalar($raw) ? $raw : 0);
        $new = $current - $amount;
        $this->set($key, $new);

        return $new;
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
     * Look a key up and decode it: `[true, value]` on a hit, `[false, null]` on a miss.
     *
     * Counts as a miss: an entry ext-memcached decodes to a non-string (not
     * written by set()), and a string that is not serialize() output — e.g. a
     * plain string stored by this driver before it serialized values itself.
     * Only `b:0;` legitimately decodes to `false`.
     *
     * @param string $key
     *
     * @return array{bool, mixed}
     */
    private function read(string $key): array
    {
        $raw = $this->memcached->get($key);

        if ($this->memcached->getResultCode() !== Memcached::RES_SUCCESS || !is_string($raw)) {
            return [false, null];
        }

        // Invalid input makes unserialize() warn; keep that from reaching the
        // application's error handler (which `@` would still invoke).
        set_error_handler(static fn (): bool => true);

        try {
            /** @var mixed $value */
            $value = unserialize($raw, ['allowed_classes' => false]);
        } finally {
            restore_error_handler();
        }

        if ($value === false && $raw !== serialize(false)) {
            return [false, null];
        }

        return [true, $value];
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
