<?php

declare(strict_types=1);

namespace Uptimeify\Tests\Unit\Sync;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WHMCS\Module\Addon\Uptimeify\Sync\TargetNormalizer;

final class TargetNormalizerTest extends TestCase
{
    /** @return list<array{string,string}> */
    public static function cases(): array
    {
        return [
            ['Example.COM', 'example.com'],
            ['https://www.example.com/path?q=1', 'example.com'],
            ['http://user:pw@Example.com:8080/', 'example.com'],
            ['www.example.com.', 'example.com'],
            ['  sub.example.com ', 'sub.example.com'],
            ['', ''],
            ['wwwexample.com', 'wwwexample.com'],
            ['https://WWW.MÜNCHEN.de/', 'münchen.de'],
        ];
    }

    /** @return array<string,array{string,string}> */
    public static function edgeCases(): array
    {
        return [
            'whitespace only' => ["  \t\n ", ''],
            'uppercase with trailing dot' => ['EXAMPLE.COM.', 'example.com'],
            'bare domain with port' => ['example.com:8443', 'example.com'],
            'bare domain with path and fragment' => ['Example.com/a/b#top', 'example.com'],
            'uppercase scheme' => ['HTTPS://WWW.Example.com', 'example.com'],
            'www only as leading label' => ['shop.www.example.com', 'shop.www.example.com'],
            'only one leading www stripped' => ['www.www.example.com', 'www.example.com'],
            'punycode left as-is' => ['XN--MNCHEN-3YA.DE', 'xn--mnchen-3ya.de'],
            'scheme-like text in query of bare domain' => ['example.com/?next=https://other.example', 'example.com'],
            'credentials without scheme' => ['user@Example.com', 'example.com'],
            'ipv4' => ['203.0.113.10', '203.0.113.10'],
            'ipv4 url with port' => ['http://203.0.113.10:8080/x', '203.0.113.10'],
            'bare ipv6' => ['2001:DB8::10', '2001:db8::10'],
            'bracketed ipv6 url' => ['https://[2001:DB8::10]:443/', '2001:db8::10'],
            'ipv6 canonical short form' => ['2001:0DB8:0000:0:0::0010', '2001:db8::10'],
            'invalid bracketed host' => ['http://[not-an-ip]/', ''],
            'no host' => ['https:///path', ''],
        ];
    }

    #[DataProvider('cases')]
    public function testHost(string $in, string $out): void
    {
        self::assertSame($out, TargetNormalizer::host($in));
    }

    #[DataProvider('edgeCases')]
    public function testHostEdgeCases(string $in, string $out): void
    {
        self::assertSame($out, TargetNormalizer::host($in));
    }
}
