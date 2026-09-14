<?php

declare(strict_types=1);

namespace Uptimeify\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WHMCS\Module\Addon\Uptimeify\Version;

final class ConfigTest extends TestCase
{
    public function testConfigHasRequiredKeys(): void
    {
        $config = uptimeify_config();
        foreach (['name', 'description', 'author', 'language', 'version', 'fields'] as $key) {
            self::assertArrayHasKey($key, $config);
        }
        self::assertSame(Version::VERSION, $config['version']);
        self::assertSame('english', $config['language']);
        self::assertSame([], $config['fields'], 'settings are managed on the module page, not via _config fields');
    }
}
