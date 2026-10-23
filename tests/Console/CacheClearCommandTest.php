<?php

declare(strict_types=1);

namespace Tests\Console;

use EzPhp\Cache\ArrayDriver;
use EzPhp\Cache\CacheValue;
use EzPhp\Cache\Console\CacheClearCommand;
use EzPhp\Cache\TaggedCache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\TestCase;

/**
 * cache:clear over an ArrayDriver: full flush, or only the given tags.
 *
 * @package Tests\Console
 */
#[CoversClass(CacheClearCommand::class)]
#[UsesClass(ArrayDriver::class)]
#[UsesClass(CacheValue::class)]
#[UsesClass(TaggedCache::class)]
final class CacheClearCommandTest extends TestCase
{
    public function test_without_tags_the_whole_store_is_flushed(): void
    {
        $cache = new ArrayDriver();
        $cache->set('a', 1);
        $cache->set('b', 2);

        ob_start();
        $code = (new CacheClearCommand($cache))->handle([]);
        $output = (string) ob_get_clean();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Cache cleared.', $output);
        $this->assertFalse($cache->has('a'));
        $this->assertFalse($cache->has('b'));
    }

    public function test_tags_option_only_flushes_tagged_entries(): void
    {
        $cache = new ArrayDriver();
        $cache->tags('users')->set('u1', 'alice');
        $cache->tags('posts')->set('p1', 'hello');
        $cache->set('plain', 'kept');

        ob_start();
        $code = (new CacheClearCommand($cache))->handle(['--tags=users, ,']);
        $output = (string) ob_get_clean();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('tagged users', $output);
        $this->assertFalse($cache->has('u1'));
        $this->assertTrue($cache->has('p1'));
        $this->assertTrue($cache->has('plain'));
    }

    public function test_name_description_and_help(): void
    {
        $command = new CacheClearCommand(new ArrayDriver());

        $this->assertSame('cache:clear', $command->getName());
        $this->assertNotSame('', $command->getDescription());
        $this->assertStringContainsString('--tags', $command->getHelp());
    }
}
