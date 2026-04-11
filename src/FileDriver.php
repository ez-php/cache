<?php

declare(strict_types=1);

namespace EzPhp\Cache;

use Closure;
use RuntimeException;

/**
 * Class FileDriver
 *
 * File-based cache driver. Each cache entry is stored as a serialised PHP file
 * in the configured directory. Entry keys are hashed with MD5 to produce safe,
 * fixed-length filenames.
 *
 * @package EzPhp\Cache
 */
final class FileDriver implements CacheInterface
{
    use TaggableDriverTrait;

    private readonly string $directory;

    private int $hits = 0;

    private int $misses = 0;

    /**
     * FileDriver Constructor
     *
     * @param string $directory
     */
    public function __construct(string $directory)
    {
        $this->directory = $directory;

        if (!is_dir($this->directory) && !mkdir($this->directory, 0o755, true)) {
            throw new RuntimeException("Cannot create cache directory: $this->directory");
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
        $entry = $this->read($key);

        if ($entry !== null) {
            $this->hits++;

            return $entry['value'];
        }

        $this->misses++;

        return $default;
    }

    /**
     * @param string $key
     * @param mixed  $value
     * @param int    $ttl
     *
     * @return void
     * @throws RuntimeException When the cache file cannot be written.
     */
    public function set(string $key, mixed $value, int $ttl = 0): void
    {
        $data = [
            'expires' => $ttl !== 0 ? time() + $ttl : null,
            'value' => $value,
        ];

        if (file_put_contents($this->path($key), serialize($data), LOCK_EX) === false) {
            throw new RuntimeException('Cache write failed: ' . $this->path($key));
        }
    }

    /**
     * @param string $key
     *
     * @return void
     */
    public function forget(string $key): void
    {
        $path = $this->path($key);

        if (is_file($path)) {
            unlink($path);
        }
    }

    /**
     * @param string $key
     *
     * @return bool
     */
    public function has(string $key): bool
    {
        return $this->read($key) !== null;
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
        $entry = $this->read($key);

        if ($entry !== null) {
            $this->hits++;

            return $entry['value'];
        }

        $this->misses++;

        $value = $callback();
        $this->set($key, $value, $ttl);

        return $value;
    }

    /**
     * Delete all cache files from the directory.
     *
     * @return void
     */
    public function flush(): void
    {
        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*.cache') ?: [] as $file) {
            unlink($file);
        }
    }

    /**
     * Atomically increment a numeric value using an exclusive file lock.
     * Creates the key (starting at 0) if absent.
     *
     * @param string $key
     * @param int    $amount
     *
     * @return int
     * @throws RuntimeException When the cache file cannot be opened or written.
     */
    public function increment(string $key, int $amount = 1): int
    {
        return $this->atomicModify($key, $amount);
    }

    /**
     * Atomically decrement a numeric value using an exclusive file lock.
     * Creates the key (starting at 0) if absent.
     *
     * @param string $key
     * @param int    $amount
     *
     * @return int
     * @throws RuntimeException When the cache file cannot be opened or written.
     */
    public function decrement(string $key, int $amount = 1): int
    {
        return $this->atomicModify($key, -$amount);
    }

    /**
     * Acquire a non-blocking file lock for the given key.
     *
     * @param string $key
     * @param int    $ttl
     *
     * @return LockInterface
     */
    public function lock(string $key, int $ttl = 0): LockInterface
    {
        return new FileLock($this->directory . DIRECTORY_SEPARATOR . md5($key) . '.lock', $ttl);
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

    /**
     * Atomically read-modify-write a numeric value using an exclusive file lock.
     *
     * Opens (or creates) the cache file, acquires LOCK_EX for the entire
     * read-modify-write sequence, and releases the lock before returning.
     * This prevents lost updates under concurrent access.
     *
     * @param string $key   Cache key.
     * @param int    $delta Amount to add; pass a negative value to decrement.
     *
     * @return int New value after applying the delta.
     * @throws RuntimeException When the file cannot be opened or written.
     */
    private function atomicModify(string $key, int $delta): int
    {
        $path = $this->path($key);

        $fp = fopen($path, 'c+');

        if ($fp === false) {
            throw new RuntimeException("Cannot open cache file for atomic operation: $path");
        }

        $new = 0;

        try {
            flock($fp, LOCK_EX);

            $raw = stream_get_contents($fp);
            $current = 0;

            if ($raw !== false && $raw !== '') {
                /** @var mixed $data */
                $data = unserialize($raw, ['allowed_classes' => false]);

                if (is_array($data)
                    && array_key_exists('expires', $data)
                    && array_key_exists('value', $data)
                ) {
                    $expires = $data['expires'];

                    if (!is_int($expires) || $expires >= time()) {
                        $val = $data['value'];
                        $current = is_int($val) ? $val : (int) (is_scalar($val) ? $val : 0);
                    }
                }
            }

            $new = $current + $delta;
            $serialized = serialize(['expires' => null, 'value' => $new]);

            ftruncate($fp, 0);
            rewind($fp);

            if (fwrite($fp, $serialized) === false) {
                throw new RuntimeException("Cache write failed: $path");
            }

            fflush($fp);
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }

        return $new;
    }

    /**
     * @param string $key
     *
     * @return string
     */
    private function path(string $key): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . md5($key) . '.cache';
    }

    /** @return array{value: mixed}|null */
    private function read(string $key): ?array
    {
        $path = $this->path($key);

        if (!is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);

        if ($raw === false) {
            return null;
        }

        /** @var mixed $data */
        $data = unserialize($raw, ['allowed_classes' => false]);

        if (!is_array($data)
            || !array_key_exists('expires', $data)
            || !array_key_exists('value', $data)
        ) {
            return null;
        }

        $expires = $data['expires'];

        if (is_int($expires) && $expires < time()) {
            $this->forget($key);

            return null;
        }

        return ['value' => $data['value']];
    }
}
