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
    /**
     * FileDriver Constructor
     *
     * @param string $directory
     */
    public function __construct(private readonly string $directory)
    {
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

        return $entry !== null ? $entry['value'] : $default;
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
        $data = [
            'expires' => $ttl !== 0 ? time() + $ttl : null,
            'value' => $value,
        ];

        file_put_contents($this->path($key), serialize($data), LOCK_EX);
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
     * @param string   $key
     * @param int      $ttl
     * @param Closure $callback
     *
     * @return mixed
     */
    public function remember(string $key, int $ttl, Closure $callback): mixed
    {
        $entry = $this->read($key);

        if ($entry !== null) {
            return $entry['value'];
        }

        $value = $callback();
        $this->set($key, $value, $ttl);

        return $value;
    }

    /**
     * Delete all cache files from the directory.
     */
    public function flush(): void
    {
        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*.cache') ?: [] as $file) {
            unlink($file);
        }
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

        $data = unserialize($raw);

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
