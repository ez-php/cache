<?php

declare(strict_types=1);

namespace EzPhp\Cache;

use RuntimeException;

/**
 * Class CacheException
 *
 * Thrown by all cache drivers on unrecoverable cache operations such as
 * failed writes, missing extensions, or broken connections.
 *
 * Catching this single type is sufficient regardless of which driver is active,
 * because all drivers throw CacheException (a RuntimeException subtype) for
 * driver-level errors.
 *
 * @package EzPhp\Cache
 */
class CacheException extends RuntimeException
{
}
