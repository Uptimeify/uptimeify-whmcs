<?php

declare(strict_types=1);

namespace Uptimeify\Tests\Unit\Client;

use PHPUnit\Framework\TestCase;

/**
 * The client template is Smarty, which the tests cannot render (no Smarty in the dev dependencies). These checks
 * hold it to the rules that matter: Smarty 4 compatible (no `{php}`), every printed variable escaped (spec §10),
 * no request data, and every `$L.key` it uses exists as `client.key` in both language files.
 */
final class TemplateTest extends TestCase
{
    private const TEMPLATE = __DIR__ . '/../../../templates/overview.tpl';

    public function testNoPhpTagsAndNoRequestData(): void
    {
        $tpl = $this->template();
        self::assertDoesNotMatchRegularExpression('/\{\/?php\}|<\?php|<\?=/i', $tpl);
        self::assertStringNotContainsString('$smarty.get', $tpl);
        self::assertStringNotContainsString('$smarty.post', $tpl);
        self::assertStringNotContainsString('$smarty.request', $tpl);
        self::assertStringNotContainsString('nofilter', $tpl);
    }

    public function testEveryPrintedVariableIsEscaped(): void
    {
        preg_match_all('/\{(\$[^}]*)\}/', $this->template(), $matches);
        self::assertNotEmpty($matches[1]);
        foreach ($matches[1] as $expression) {
            self::assertMatchesRegularExpression('/\|escape(:\'html\')?$/', $expression, 'unescaped output: {' . $expression . '}');
        }
    }

    public function testEveryLanguageKeyExists(): void
    {
        preg_match_all('/\$L\.([a-z_]+)/', $this->template(), $matches);
        self::assertNotEmpty($matches[1]);
        foreach (['english', 'german'] as $language) {
            $_ADDONLANG = [];
            require __DIR__ . '/../../../lang/' . $language . '.php';
            foreach (array_unique($matches[1]) as $key) {
                self::assertArrayHasKey('client.' . $key, $_ADDONLANG, $language);
            }
        }
    }

    private function template(): string
    {
        $tpl = file_get_contents(self::TEMPLATE);
        self::assertIsString($tpl);
        return $tpl;
    }
}
