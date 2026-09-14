<?php

declare(strict_types=1);

namespace Uptimeify\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WHMCS\Module\Addon\Uptimeify\Lang;

final class LangTest extends TestCase
{
    public function testFallsBackToEnglishForUnknownLanguage(): void
    {
        $lang = Lang::load('klingon');
        self::assertSame('Monitoring', $lang['nav.monitoring']);
    }

    public function testSubstitutesBrandAndVars(): void
    {
        $lang = ['widget.all_ok' => 'All %brand% systems nominal (%count%)'];
        self::assertSame('All Acme systems nominal (3)', Lang::t($lang, 'widget.all_ok', ['brand' => 'Acme', 'count' => 3]));
        self::assertSame('[[missing.key]]', Lang::t($lang, 'missing.key'));
    }
}
