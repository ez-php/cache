<?php

declare(strict_types=1);

namespace EzPhp\Cache;

use Closure;
use RuntimeException;

/**
 * Class ApcuDriver
 *
 * Shared-memory cache via ext-apcu: shared by every PHP-FPM worker of one host,
 * gone on restart, never shared between hosts. The fastest store for per-host
 * data (compiled config, view fragments — see ez-php/view-cache) and an
 * alternative to Redis on a single machine.
 *
 * Values are stored natively (APCu serialises them itself); CacheValue keeps
 * objects out, as on every driver. increment()/decrement() are atomic
 * (apcu_add() + apcu_inc()/apcu_dec()) and keep the entry's TTL. flush()
 * clears the whole APCu user cache of this host.
 *
 * Needs ext-apcu with `apc.enabled` — and `apc.enable_cli=1` for the CLI
 * (console commands, workers, tests); without it APCu is a silent no-op, so the
 * constructor refuses to run.
 *
 * @package EzPhp\Cache
 */
final class ApcuDriver implements CacheInterface
{
    use TaggableDriverTrait;

    private int $hits = 0;

    private int $misses = 0;

    /**
     * @throws RuntimeException When ext-apcu is missing or disabled (CLI: apc.enable_cli).
     */
    public function __construct()
    {
        if (!extension_loaded('apcu') || !apcu_enabled()) {
            throw new RuntimeException(
                'The "apcu" PHP extension is required and enabled for the APCu cache driver (CLI: apc.enable_cli=1).'
            );
        }
    }

    /**
     * {@inheritDoc}
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $value = apcu_fetch($key, $found);

        if (!$found) {
            $this->misses++;

            return $default;
        }

        $this->hits++;

        return $value;
    }

    /**
     * {@inheritDoc}
     */
    public function set(string $key, mixed $value, int $ttl = 0): void
    {
        CacheValue::assertStorable($key, $value);

        if ($ttl < 0) {
            apcu_delete($key);

            return;
        }

        apcu_store($key, $value, $ttl);
    }

    /**
     * {@inheritDoc}
     */
    public function forget(string $key): void
    {
        apcu_delete($key);
    }

    /**
     * {@inheritDoc}
     */
    public function has(string $key): bool
    {
        return apcu_exists($key);
    }

    /**
     * {@inheritDoc}
     */
    public function remember(string $key, int $ttl, Closure $callback): mixed
    {
        $value = apcu_fetch($key, $found);

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
     * Clears the whole APCu user cache of this host, not only this driver's keys.
     *
     * @return void
     */
    public function flush(): void
    {
        apcu_clear_cache();
    }

    /**
     * {@inheritDoc}
     */
    public function increment(string $key, int $amount = 1): int
    {
        return $this->adjust($key, $amount);
    }

    /**
     * {@inheritDoc}
     */
    public function decrement(string $key, int $amount = 1): int
    {
        return $this->adjust($key, -$amount);
    }

    /**
     * {@inheritDoc}
     */
    public function lock(string $key, int $ttl = 0): LockInterface
    {
        return new ApcuLock('__lock:' . $key, $ttl);
    }

    /**
     * {@inheritDoc}
     */
    public function stats(): CacheStats
    {
        return new CacheStats($this->hits, $this->misses);
    }

    /**
     * Atomic add: create the key at 0 if absent (apcu_add is a no-op otherwise), then
     * apcu_inc() it in one step. A non-integer value cannot be incremented by APCu and
     * falls back to read-modify-write, like the other non-atomic drivers.
     *
     * @param string $key
     * @param int    $delta
     *
     * @return int
     */
    private function adjust(string $key, int $delta): int
    {
        apcu_add($key, 0);

        $new = apcu_inc($key, $delta, $success);

        if ($success && is_int($new)) {
            return $new;
        }

        $raw = apcu_fetch($key);
        $current = is_int($raw) ? $raw : (int) (is_scalar($raw) ? $raw : 0);
        apcu_store($key, $current + $delta);

        return $current + $delta;
    }
}
