<?php

declare(strict_types=1);

namespace Tests\Cache;

use EzPhp\Cache\ArrayDriver;
use EzPhp\Cache\TaggedCache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\TestCase;

/**
 * Class TaggedCacheTest
 *
 * Tests TaggedCache backed by ArrayDriver.
 *
 * @package Tests\Cache
 */
#[CoversClass(TaggedCache::class)]
#[UsesClass(ArrayDriver::class)]
final class TaggedCacheTest extends TestCase
{
    private ArrayDriver $driver;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->driver = new ArrayDriver();
    }

    /**
     * @return void
     */
    public function test_set_stores_value_readable_via_driver(): void
    {
        $tagged = new TaggedCache($this->driver, ['articles']);
        $tagged->set('article:1', 'hello');

        $this->assertSame('hello', $this->driver->get('article:1'));
    }

    /**
     * @return void
     */
    public function test_set_records_key_in_tag_set(): void
    {
        $tagged = new TaggedCache($this->driver, ['articles']);
        $tagged->set('article:1', 'hello');

        $tagSet = $this->driver->get('__tag:articles', []);

        $this->assertIsArray($tagSet);
        $this->assertContains('article:1', $tagSet);
    }

    /**
     * @return void
     */
    public function test_get_retrieves_stored_value(): void
    {
        $tagged = new TaggedCache($this->driver, ['articles']);
        $tagged->set('article:2', 'world');

        $this->assertSame('world', $tagged->get('article:2'));
    }

    /**
     * @return void
     */
    public function test_flush_deletes_all_tagged_keys_and_tag_sets(): void
    {
        $tagged = new TaggedCache($this->driver, ['posts']);
        $tagged->set('post:1', 'foo');
        $tagged->set('post:2', 'bar');

        $tagged->flush();

        $this->assertNull($this->driver->get('post:1'));
        $this->assertNull($this->driver->get('post:2'));
        $this->assertSame([], $this->driver->get('__tag:posts', []));
        $this->assertFalse($this->driver->has('__tag:posts'));
    }

    /**
     * @return void
     */
    public function test_forget_removes_single_key(): void
    {
        $tagged = new TaggedCache($this->driver, ['items']);
        $tagged->set('item:1', 'value');
        $tagged->forget('item:1');

        $this->assertFalse($this->driver->has('item:1'));
    }

    /**
     * @return void
     */
    public function test_has_reflects_driver_state(): void
    {
        $tagged = new TaggedCache($this->driver, ['things']);
        $this->assertFalse($tagged->has('thing:1'));

        $tagged->set('thing:1', 'yes');
        $this->assertTrue($tagged->has('thing:1'));
    }

    /**
     * @return void
     */
    public function test_remember_stores_on_miss_and_returns_cached_on_hit(): void
    {
        $tagged = new TaggedCache($this->driver, ['computed']);
        $calls = 0;

        $result1 = $tagged->remember('computed:key', 0, function () use (&$calls): string {
            $calls++;

            return 'expensive';
        });

        $result2 = $tagged->remember('computed:key', 0, function () use (&$calls): string {
            $calls++;

            return 'expensive';
        });

        $this->assertSame('expensive', $result1);
        $this->assertSame('expensive', $result2);
        $this->assertSame(1, $calls);
    }

    /**
     * @return void
     */
    public function test_tags_returns_new_tagged_cache_with_merged_tags(): void
    {
        $tagged = new TaggedCache($this->driver, ['a']);
        $merged = $tagged->tags('b');

        $merged->set('key:1', 'val');

        // Key should appear in both tag sets.
        $tagSetA = $this->driver->get('__tag:a', []);
        $tagSetB = $this->driver->get('__tag:b', []);

        $this->assertIsArray($tagSetA);
        $this->assertIsArray($tagSetB);
        $this->assertContains('key:1', $tagSetA);
        $this->assertContains('key:1', $tagSetB);
    }

    /**
     * @return void
     */
    public function test_flush_on_merged_tags_clears_both_tag_sets(): void
    {
        $tagged = new TaggedCache($this->driver, ['x']);
        $merged = $tagged->tags('y');

        $merged->set('shared:1', 'data');
        $merged->flush();

        $this->assertFalse($this->driver->has('shared:1'));
        $this->assertFalse($this->driver->has('__tag:x'));
        $this->assertFalse($this->driver->has('__tag:y'));
    }
}
