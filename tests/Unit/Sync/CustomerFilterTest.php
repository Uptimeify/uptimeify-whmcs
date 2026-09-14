<?php

declare(strict_types=1);

namespace Uptimeify\Tests\Unit\Sync;

use Uptimeify\Tests\Support\DbTestCase;
use WHMCS\Module\Addon\Uptimeify\Settings;
use WHMCS\Module\Addon\Uptimeify\Sync\CustomerFilter;

final class CustomerFilterTest extends DbTestCase
{
    public function testBlacklistModeAllowsByDefault(): void
    {
        $s = new Settings();
        $s->setCustomerFilter([2 => 'skip', 3 => 'sync']);
        $f = new CustomerFilter($s);
        self::assertTrue($f->allows(1));
        self::assertFalse($f->allows(2));
        self::assertTrue($f->allows(3));
    }

    public function testWhitelistModeDeniesByDefault(): void
    {
        $s = new Settings();
        $s->set('filter_mode', 'whitelist');
        $s->setCustomerFilter([2 => 'skip', 3 => 'sync']);
        $f = new CustomerFilter($s);
        self::assertFalse($f->allows(1));
        self::assertFalse($f->allows(2));
        self::assertTrue($f->allows(3));
    }

    public function testIgnoredTargetsCompareNormalized(): void
    {
        $s = new Settings();
        $s->setIgnoredTargets(['WWW.Ignored.Example']);
        $f = new CustomerFilter($s);
        self::assertTrue($f->isIgnoredTarget('https://ignored.example/'));
        self::assertFalse($f->isIgnoredTarget('other.example'));
    }

    public function testEmptyIgnoredEntriesNeverMatch(): void
    {
        $s = new Settings();
        $s->setIgnoredTargets(['', '   ', 'https:///nohost', '203.0.113.10']);
        $f = new CustomerFilter($s);
        self::assertFalse($f->isIgnoredTarget(''));
        self::assertFalse($f->isIgnoredTarget('  '));
        self::assertTrue($f->isIgnoredTarget('203.0.113.10'));
        self::assertFalse($f->isIgnoredTarget('203.0.113.11'));
    }

    public function testNoSettingsStoredAllowsEveryoneAndIgnoresNothing(): void
    {
        $f = new CustomerFilter(new Settings());
        self::assertTrue($f->allows(42));
        self::assertFalse($f->isIgnoredTarget('example.com'));
    }
}
