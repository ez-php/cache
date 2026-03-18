<?php

declare(strict_types=1);

namespace Tests\Cache;

use EzPhp\Testing\ApplicationTestCase as EzPhpApplicationTestCase;
use RuntimeException;

/**
 * Base class for cache module tests that need a bootstrapped Application.
 *
 * Creates a temporary application root containing a config/cache.php file
 * that reads from env vars at require-time. Because Config and all service
 * bindings are resolved lazily, env vars set in a test method before the first
 * make() call are picked up correctly — no special bootstrap ordering needed.
 *
 * @package Tests
 */
abstract class ApplicationTestCase extends EzPhpApplicationTestCase
{
    /**
     * @return string
     */
    protected function getBasePath(): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ez-cache-test-' . uniqid('', true);
        $configDir = $path . DIRECTORY_SEPARATOR . 'config';

        mkdir($configDir, 0o777, true);

        $content = <<<'PHP'
            <?php

            declare(strict_types=1);

            return [
                'driver'    => getenv('CACHE_DRIVER') ?: 'array',
                'file_path' => getenv('CACHE_PATH') ?: sys_get_temp_dir() . '/ez-cache',
                'redis'     => [
                    'host'     => getenv('CACHE_REDIS_HOST') ?: '127.0.0.1',
                    'port'     => (int) (getenv('CACHE_REDIS_PORT') ?: 6379),
                    'database' => (int) (getenv('CACHE_REDIS_DATABASE') ?: 0),
                ],
            ];
            PHP;

        $result = file_put_contents($configDir . DIRECTORY_SEPARATOR . 'cache.php', $content);

        if ($result === false) {
            throw new RuntimeException('Failed to write cache.php for test at ' . $configDir);
        }

        return $path;
    }
}
