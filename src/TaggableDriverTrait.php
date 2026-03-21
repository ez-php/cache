<?php

declare(strict_types=1);

namespace EzPhp\Cache;

/**
 * Trait TaggableDriverTrait
 *
 * Provides the tags() method for CacheInterface drivers.
 * All three built-in drivers (ArrayDriver, FileDriver, RedisDriver) use this trait.
 *
 * @package EzPhp\Cache
 */
trait TaggableDriverTrait
{
    /**
     * Return a TaggedCache scoped to the given tag(s).
     *
     * @param string|list<string> $tags
     *
     * @return TaggedCache
     */
    public function tags(string|array $tags): TaggedCache
    {
        $list = is_array($tags) ? $tags : [$tags];

        return new TaggedCache($this, $list);
    }
}
