<?php

declare(strict_types=1);

namespace Uptimeify\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use WHMCS\Module\Addon\Uptimeify\Http\HttpResponse;

final class HttpResponseTest extends TestCase
{
    public function testHeaderLookupIsCaseInsensitive(): void
    {
        $r = new HttpResponse(429, ['Retry-After' => '7'], '');
        self::assertSame('7', $r->header('retry-after'));
        self::assertNull($r->header('x-missing'));
    }

    public function testJsonReturnsNullForNonObjectBodies(): void
    {
        self::assertNull((new HttpResponse(200, [], ''))->json());
        self::assertNull((new HttpResponse(200, [], '"str"'))->json());
        self::assertSame(['a' => 1], (new HttpResponse(200, [], '{"a":1}'))->json());
        self::assertSame([1, 2], (new HttpResponse(200, [], '[1,2]'))->json());
    }
}
