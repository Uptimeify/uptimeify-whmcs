<?php

declare(strict_types=1);

namespace Uptimeify\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class LangParityTest extends TestCase
{
    public function testEnglishAndGermanHaveIdenticalKeys(): void
    {
        $en = $this->load('english');
        $de = $this->load('german');
        self::assertSame([], array_values(array_diff(array_keys($en), array_keys($de))), 'missing in german');
        self::assertSame([], array_values(array_diff(array_keys($de), array_keys($en))), 'missing in english');
        foreach ($en as $key => $value) {
            self::assertNotSame('', trim((string) $value), "empty english value for {$key}");
            self::assertNotSame('', trim((string) $de[$key]), "empty german value for {$key}");
        }
    }

    public function testGermanUsesTheFormalSie(): void
    {
        foreach ($this->load('german') as $key => $value) {
            self::assertDoesNotMatchRegularExpression('/\\b(du|dich|dir|dein|deine|deinen|deinem|deiner|deines)\\b/iu', $value, "informal German in {$key}");
        }
    }

    public function testDeadJobsAreNotCalledDeadInGerman(): void
    {
        $de = $this->load('german');
        self::assertSame('endgültig fehlgeschlagen', $de['jobstatus.dead']);
        self::assertStringNotContainsString(' tot', $de['dashboard.queue']);
    }

    /** @return array<string,string> */
    private function load(string $language): array
    {
        $_ADDONLANG = [];
        require dirname(__DIR__, 2) . "/lang/{$language}.php";
        return $_ADDONLANG;
    }
}
