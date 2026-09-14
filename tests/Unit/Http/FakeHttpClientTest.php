<?php

declare(strict_types=1);

namespace Uptimeify\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Uptimeify\Tests\Fake\FakeHttpClient;

final class FakeHttpClientTest extends TestCase
{
    public function testMatchesByMethodAndPathAndRecords(): void
    {
        $http = (new FakeHttpClient())
            ->on('GET', '/api/organization', 200, ['id' => 'org_1'])
            ->on('POST', '#^/api/websites/[^/]+/trigger-check$#', 202, '');
        $r = $http->send('GET', 'https://x.test/api/organization', ['Accept' => 'application/json'], null, 5.0);
        self::assertSame(200, $r->status);
        self::assertSame(['id' => 'org_1'], $r->json());
        $r2 = $http->send('POST', 'https://x.test/api/websites/abc/trigger-check', [], null, 5.0);
        self::assertSame(202, $r2->status);
        self::assertCount(2, $http->requests());
        self::assertSame('GET', $http->requests()[0]['method']);
    }

    public function testUnmatchedRequestThrows(): void
    {
        $this->expectException(\LogicException::class);
        (new FakeHttpClient())->send('GET', 'https://x.test/nope', [], null, 1.0);
    }
}
