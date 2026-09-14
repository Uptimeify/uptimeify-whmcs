<?php

declare(strict_types=1);

namespace Uptimeify\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WHMCS\Module\Addon\Uptimeify\Version;

final class VersionTest extends TestCase
{
    public function testVersionIsSemver(): void
    {
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', Version::VERSION);
        self::assertSame('uptimeify-whmcs/' . Version::VERSION, Version::userAgent());
    }
}
