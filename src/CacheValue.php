<?php

declare(strict_types=1);

namespace EzPhp\Cache;

/**
 * Class CacheValue
 *
 * Guards the cache value contract: only `null`, scalars and (nested) arrays of
 * those may be stored.
 *
 * The File and Redis drivers restore values with
 * `unserialize(..., ['allowed_classes' => false])` so a tampered cache entry
 * can never instantiate a class. An object would therefore come back as
 * `__PHP_Incomplete_Class` from those drivers while `ArrayDriver` returned the
 * real object — code that passed its tests against the array driver broke in
 * production. Rejecting objects on every driver makes that failure immediate
 * and identical everywhere. Cache a scalar/array representation instead
 * (e.g. `(string) $decimal`, `$dto->toArray()`) and rebuild the object after
 * reading it.
 *
 * @package EzPhp\Cache
 */
final class CacheValue
{
    /**
     * Throw unless the value is null, a scalar, or an array containing only those.
     *
     * @param string $key   Cache key, used in the error message.
     * @param mixed  $value Value about to be stored.
     *
     * @throws CacheException When the value (or a nested element) is an object or resource.
     *
     * @return void
     */
    public static function assertStorable(string $key, mixed $value): void
    {
        $path = self::firstInvalidPath($value, '');

        if ($path === null) {
            return;
        }

        [$where, $type] = $path;

        throw new CacheException(sprintf(
            "Cache key '%s': cannot store %s%s — only null, scalars and arrays of those are allowed. "
            . 'Store a scalar/array representation and rebuild the object after reading it.',
            $key,
            $type,
            $where === '' ? '' : ' at ' . $where,
        ));
    }

    /**
     * Locate the first object/resource inside the value.
     *
     * @param mixed  $value
     * @param string $path  Bracket path of $value inside the top-level value.
     *
     * @return array{string, string}|null [path, type description] or null when storable.
     */
    private static function firstInvalidPath(mixed $value, string $path): ?array
    {
        if (is_object($value)) {
            return [$path, 'an object of class ' . $value::class];
        }

        if (is_resource($value)) {
            return [$path, 'a resource'];
        }

        if (is_array($value)) {
            foreach ($value as $index => $element) {
                $found = self::firstInvalidPath($element, $path . '[' . $index . ']');

                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }
}
