<?php

declare(strict_types=1);

namespace Uptimeify\Tests\Unit\Api;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Uptimeify\Tests\Fake\FakeHttpClient;
use WHMCS\Module\Addon\Uptimeify\Api\Client;
use WHMCS\Module\Addon\Uptimeify\Api\Exception\ApiException;
use WHMCS\Module\Addon\Uptimeify\Api\Exception\NotFoundException;
use WHMCS\Module\Addon\Uptimeify\Api\Exception\QuotaExceededException;
use WHMCS\Module\Addon\Uptimeify\Api\Exception\RateLimitedException;
use WHMCS\Module\Addon\Uptimeify\Api\Exception\UnauthorizedException;
use WHMCS\Module\Addon\Uptimeify\Http\HttpResponse;
use WHMCS\Module\Addon\Uptimeify\Http\TransportException;

/**
 * Response shapes and error codes follow docs/api-surface.md (verified against the Uptimeify API).
 */
final class ClientTest extends TestCase
{
    private const BEARER = 'wsm_secret123';

    private FakeHttpClient $http;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $GLOBALS['uptimeify_test']['moduleCalls'] = [];
    }

    private function client(?FakeHttpClient $http = null): Client
    {
        return new Client($http ?? $this->http, 'https://api.test', self::BEARER, 3.0);
    }

    /**
     * Serves paginated list endpoints page-aware (FakeHttpClient ignores the query string).
     *
     * @param list<array<string,mixed>> $all
     */
    private static function pagedHttp(array $all, int $pageSize): FakeHttpClient
    {
        return new class ($all, $pageSize) extends FakeHttpClient {
            /** @param list<array<string,mixed>> $all */
            public function __construct(private readonly array $all, private readonly int $pageSize)
            {
            }

            public function send(string $method, string $url, array $headers, ?string $body, float $timeout): HttpResponse
            {
                parent::on($method, (string) parse_url($url, PHP_URL_PATH), 200, []);
                parent::send($method, $url, $headers, $body, $timeout);
                parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
                $page = (int) ($q['page'] ?? 1);
                $items = array_slice($this->all, ($page - 1) * $this->pageSize, $this->pageSize);
                return new HttpResponse(200, [], (string) json_encode(['items' => $items, 'total' => count($this->all), 'page' => $page, 'perPage' => $this->pageSize]));
            }
        };
    }

    public function testSendsBearerAcceptAndUserAgent(): void
    {
        $this->http->on('GET', '/api/organization', 200, ['id' => 1, 'publicId' => 'org', 'name' => 'Acme']);
        $org = $this->client()->getOrganization();
        self::assertSame('Acme', $org['name']);
        $request = $this->http->requests()[0];
        self::assertSame('https://api.test/api/organization', $request['url']);
        self::assertSame('Bearer ' . self::BEARER, $request['headers']['Authorization']);
        self::assertSame('application/json', $request['headers']['Accept']);
        self::assertStringStartsWith('uptimeify-whmcs/', $request['headers']['User-Agent']);
        self::assertArrayNotHasKey('Content-Type', $request['headers']);
        self::assertNull($request['body']);
    }

    public function testTokenIsMaskedInModuleLog(): void
    {
        $this->http->on('POST', '/api/websites', 200, ['publicId' => 'w1', 'id' => 1]);
        $this->client()->createWebsite('c1', 'n', 'https://a.test', 'combined', 5, []);
        $call = $GLOBALS['uptimeify_test']['moduleCalls'][0];
        self::assertSame('uptimeify', $call['module']);
        self::assertSame('POST /api/websites', $call['action']);
        self::assertContains(self::BEARER, $call['replaceVars']);
    }

    public function testTokenIsMaskedInModuleLogOnTransportError(): void
    {
        $http = new class () extends FakeHttpClient {
            public function send(string $method, string $url, array $headers, ?string $body, float $timeout): HttpResponse
            {
                throw new TransportException('dns fail');
            }
        };
        try {
            $this->client($http)->getOrganization();
            self::fail('expected ApiException');
        } catch (ApiException) {
        }
        $call = $GLOBALS['uptimeify_test']['moduleCalls'][0];
        self::assertContains(self::BEARER, $call['replaceVars']);
    }

    public function testBaseUrlTrailingSlashIsIgnored(): void
    {
        $this->http->on('GET', '/api/organization', 200, ['id' => 1]);
        (new Client($this->http, 'https://api.test/', self::BEARER))->getOrganization();
        self::assertSame('https://api.test/api/organization', $this->http->requests()[0]['url']);
    }

    /** @return iterable<string,array{\Closure(Client):mixed}> */
    public static function pathParamCalls(): iterable
    {
        yield 'getCustomer' => [static fn (Client $c) => $c->getCustomer('a/b c')];
        yield 'patchCustomer' => [static fn (Client $c) => $c->patchCustomer('a/b c', ['name' => 'x'])];
        yield 'setWebsiteStatus' => [static fn (Client $c) => $c->setWebsiteStatus('a/b c', 'inactive')];
        yield 'updateWebsiteTarget' => [static fn (Client $c) => $c->updateWebsiteTarget('a/b c', 'https://example.com', 'example.com')];
        yield 'setCustomerIpStatus' => [static fn (Client $c) => $c->setCustomerIpStatus('a/b c', 'disabled')];
        yield 'deleteWebsite' => [static fn (Client $c) => $c->deleteWebsite('a/b c')];
        yield 'getUptimeStats' => [static fn (Client $c) => $c->getUptimeStats('a/b c')];
        yield 'createCustomerDomain' => [static fn (Client $c) => $c->createCustomerDomain('a/b c', 'example.com', 'l')];
        yield 'deleteCustomerDomain' => [static fn (Client $c) => $c->deleteCustomerDomain('a/b c')];
        yield 'deleteDnsMonitor' => [static fn (Client $c) => $c->deleteDnsMonitor('a/b c')];
        yield 'createCustomerIp' => [static fn (Client $c) => $c->createCustomerIp('a/b c', '203.0.113.1', 'l')];
        yield 'deleteCustomerIp' => [static fn (Client $c) => $c->deleteCustomerIp('a/b c')];
    }

    /** @param \Closure(Client):mixed $call */
    #[DataProvider('pathParamCalls')]
    public function testPathParamsAreEncoded(\Closure $call): void
    {
        foreach (['GET', 'POST', 'PATCH', 'DELETE'] as $method) {
            $this->http->on($method, '#^/api/#', 200, ['success' => true]);
        }
        $call($this->client());
        $url = $this->http->requests()[0]['url'];
        self::assertStringContainsString('/a%2Fb%20c', $url);
        self::assertStringNotContainsString('a/b', $url);
    }

    public function testGetCustomerReturnsPlainObject(): void
    {
        $this->http->on('GET', '/api/customers/c1', 200, ['publicId' => 'c1', 'customFields' => ['whmcs_client_id' => '7']]);
        self::assertSame(['publicId' => 'c1', 'customFields' => ['whmcs_client_id' => '7']], $this->client()->getCustomer('c1'));
    }

    public function testListPackageConfigsReturnsPlainArray(): void
    {
        $this->http->on('GET', '/api/package-configs', 200, [
            ['id' => 10, 'packageType' => 'pro', 'displayName' => 'Pro', 'maxUrls' => 100],
            ['id' => 11, 'packageType' => 'essential', 'displayName' => null, 'maxUrls' => null],
        ]);
        self::assertSame(['pro', 'essential'], array_column($this->client()->listPackageConfigs(), 'packageType'));
    }

    public function testListCustomFieldDefinitionsReturnsPlainArray(): void
    {
        $this->http->on('GET', '/api/custom-fields', 200, [['id' => 1, 'fieldKey' => 'contract', 'isActive' => true]]);
        self::assertSame([1], array_column($this->client()->listCustomFieldDefinitions(), 'id'));
        self::assertSame([], (new Client((new FakeHttpClient())->on('GET', '/api/custom-fields', 200, []), 'https://api.test', self::BEARER))->listCustomFieldDefinitions());
    }

    public function testListCustomersWalksAllPages(): void
    {
        $http = self::pagedHttp([['publicId' => 'c1'], ['publicId' => 'c2'], ['publicId' => 'c3']], 2);
        self::assertSame(['c1', 'c2', 'c3'], array_column($this->client($http)->listCustomers(), 'publicId'));
        $urls = array_column($http->requests(), 'url');
        self::assertSame([
            'https://api.test/api/customers?page=1&perPage=100&sortBy=createdAt&sortOrder=asc',
            'https://api.test/api/customers?page=2&perPage=100&sortBy=createdAt&sortOrder=asc',
        ], $urls);
    }

    /** @return iterable<string,array{string,\Closure(Client):list<array<string,mixed>>}> */
    public static function pagedLists(): iterable
    {
        yield 'websites' => ['/api/websites', static fn (Client $c) => $c->listWebsites()];
        yield 'customer-domains' => ['/api/customer-domains', static fn (Client $c) => $c->listCustomerDomains()];
        yield 'dns-monitors' => ['/api/dns-monitors', static fn (Client $c) => $c->listDnsMonitors()];
        yield 'customer-ips' => ['/api/customer-ips', static fn (Client $c) => $c->listCustomerIps()];
    }

    /** @param \Closure(Client):list<array<string,mixed>> $list */
    #[DataProvider('pagedLists')]
    public function testPaginatedListsWalkAllPages(string $path, \Closure $list): void
    {
        $http = self::pagedHttp([['publicId' => 'a'], ['publicId' => 'b'], ['publicId' => 'c']], 1);
        self::assertSame(['a', 'b', 'c'], array_column($list($this->client($http)), 'publicId'));
        self::assertCount(3, $http->requests());
        self::assertSame('https://api.test' . $path . '?page=3&perPage=100&sortBy=createdAt&sortOrder=asc', $http->requests()[2]['url']);
    }

    public function testListsCanBeFilteredByCustomer(): void
    {
        $http = self::pagedHttp([['publicId' => 'a']], 100);
        $client = $this->client($http);
        $client->listWebsites('c1/x');
        $client->listCustomerDomains('c1');
        $client->listCustomerIps('c1');
        $client->listDnsMonitors(42);
        self::assertSame([
            'https://api.test/api/websites?page=1&perPage=100&sortBy=createdAt&sortOrder=asc&customerId=c1%2Fx',
            'https://api.test/api/customer-domains?page=1&perPage=100&sortBy=createdAt&sortOrder=asc&customerId=c1',
            'https://api.test/api/customer-ips?page=1&perPage=100&sortBy=createdAt&sortOrder=asc&customerId=c1',
            'https://api.test/api/dns-monitors?page=1&perPage=100&sortBy=createdAt&sortOrder=asc&customerId=42',
        ], array_column($http->requests(), 'url'));
    }

    public function testRecentIncidentsStopAfterMaxPagesAndSayWhetherTheListIsComplete(): void
    {
        $http = new class () extends FakeHttpClient {
            public function send(string $method, string $url, array $headers, ?string $body, float $timeout): HttpResponse
            {
                parent::on('GET', '/api/incidents', 200, []);
                parent::send($method, $url, $headers, $body, $timeout);
                parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
                $offset = (int) ($q['offset'] ?? 0);
                return new HttpResponse(200, [], (string) json_encode(['incidents' => [['id' => $offset + 1], ['id' => $offset + 2]], 'total' => 6, 'limit' => 500, 'offset' => $offset]));
            }
        };
        $recent = $this->client($http)->listRecentIncidents(2);
        self::assertSame([1, 2, 3, 4], array_column($recent['incidents'], 'id'));
        self::assertFalse($recent['complete']);
        self::assertCount(2, $http->requests(), 'never more than maxPages requests');

        $all = $this->client($http)->listRecentIncidents(3);
        self::assertSame([1, 2, 3, 4, 5, 6], array_column($all['incidents'], 'id'));
        self::assertTrue($all['complete']);
    }

    public function testEmptyPageStopsPagination(): void
    {
        $this->http->on('GET', '/api/websites', 200, ['items' => [], 'total' => 5, 'page' => 1, 'perPage' => 100]);
        self::assertSame([], $this->client()->listWebsites());
        self::assertCount(1, $this->http->requests());
    }

    public function testListIncidentsWalksOffsetsWithTotal(): void
    {
        $http = new class () extends FakeHttpClient {
            public function send(string $method, string $url, array $headers, ?string $body, float $timeout): HttpResponse
            {
                parent::on('GET', '/api/incidents', 200, []);
                parent::send($method, $url, $headers, $body, $timeout);
                parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
                $offset = (int) ($q['offset'] ?? 0);
                $items = $offset === 0 ? [['id' => 1], ['id' => 2]] : [['id' => 3]];
                return new HttpResponse(200, [], (string) json_encode(['incidents' => $items, 'total' => 3, 'limit' => 500, 'offset' => $offset]));
            }
        };
        self::assertSame([1, 2, 3], array_column($this->client($http)->listRecentIncidents(1000)['incidents'], 'id'));
        self::assertSame([
            'https://api.test/api/incidents?limit=500&includeTotal=1&offset=0',
            'https://api.test/api/incidents?limit=500&includeTotal=1&offset=2',
        ], array_column($http->requests(), 'url'));
    }

    public function test401MapsToUnauthorized(): void
    {
        $this->http->on('GET', '/api/organization', 401, ['statusCode' => 401, 'statusMessage' => 'Unauthorized', 'data' => ['code' => 'unauthorized']]);
        try {
            $this->client()->getOrganization();
            self::fail('expected UnauthorizedException');
        } catch (UnauthorizedException $e) {
            self::assertSame(401, $e->status);
            self::assertSame('unauthorized', $e->errorCode);
        }
    }

    public function testCodeless401ForRejectedTokenMapsToUnauthorized(): void
    {
        $this->http->on('GET', '/api/organization', 401, ['error' => true, 'statusCode' => 401, 'statusMessage' => 'Unauthorized', 'message' => 'Unauthorized']);
        $this->expectException(UnauthorizedException::class);
        $this->client()->getOrganization();
    }

    public function testCustomerScopedTokenIsDistinguishable(): void
    {
        $this->http->on('POST', '/api/customers', 403, ['statusCode' => 403, 'statusMessage' => 'Forbidden', 'data' => ['code' => 'customerScopedTokenForbidden']]);
        try {
            $this->client()->createCustomer('n', 'a@b.test', 'pro', ['whmcs_client_id' => '1']);
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertNotInstanceOf(QuotaExceededException::class, $e);
            self::assertNotInstanceOf(UnauthorizedException::class, $e);
            self::assertSame(403, $e->status);
            self::assertSame(Client::SCOPE_FORBIDDEN_CODE, $e->errorCode);
            self::assertSame('customerScopedTokenForbidden', $e->errorCode);
        }
    }

    public function testQuotaCodesMapToQuotaExceeded(): void
    {
        foreach (['activeMonitorLimitReached', 'monitorQuotaReached', 'monitorQuotaNoHigherTier'] as $code) {
            $http = (new FakeHttpClient())->on('POST', '/api/websites', 403, ['statusMessage' => 'x', 'data' => ['code' => $code]]);
            try {
                $this->client($http)->createWebsite('c', 'n', 'https://a.test', 'combined', 5, []);
                self::fail('expected QuotaExceededException for ' . $code);
            } catch (QuotaExceededException $e) {
                self::assertSame($code, $e->errorCode);
                self::assertSame(403, $e->status);
            }
        }
        self::assertSame(['activeMonitorLimitReached', 'monitorQuotaReached', 'monitorQuotaNoHigherTier', 'activeIpLimitReached'], Client::QUOTA_ERROR_CODES);
    }

    public function testIpLimitTextMapsToQuotaExceeded(): void
    {
        $this->http->on('POST', '/api/customers/c/ips', 403, [
            'statusCode' => 403,
            'statusMessage' => 'Active IP limit reached. Your organization IP quota allows maximum 1 active IPs for DNSBL monitoring. Please disable an IP or upgrade your package.',
            'data' => ['code' => 'generic'],
        ]);
        try {
            $this->client()->createCustomerIp('c', '203.0.113.1', 'srv');
            self::fail('expected QuotaExceededException');
        } catch (QuotaExceededException $e) {
            self::assertSame('activeIpLimitReached', $e->errorCode);
            self::assertSame(403, $e->status);
        }
    }

    public function testOtherLimitTextIsNotQuota(): void
    {
        $this->http->on('POST', '/api/customers/c/ips', 403, ['statusMessage' => 'Monitor limit reached somewhere', 'data' => ['code' => 'generic']]);
        try {
            $this->client()->createCustomerIp('c', '203.0.113.1', 'srv');
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertNotInstanceOf(QuotaExceededException::class, $e);
            self::assertSame('generic', $e->errorCode);
        }
    }

    public function testQuotaCodeOutsideDataIsIgnored(): void
    {
        $this->http->on('POST', '/api/websites', 403, ['statusMessage' => 'Forbidden', 'code' => 'monitorQuotaReached']);
        try {
            $this->client()->createWebsite('c', 'n', 'https://a.test', 'combined', 5, []);
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertNotInstanceOf(QuotaExceededException::class, $e);
            self::assertSame('', $e->errorCode);
        }
    }

    public function testGeneric403IsPlainApiException(): void
    {
        $this->http->on('POST', '/api/websites', 403, ['statusMessage' => 'Forbidden', 'data' => ['code' => 'forbidden']]);
        try {
            $this->client()->createWebsite('c', 'n', 'https://a.test', 'combined', 5, []);
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(ApiException::class, $e::class);
            self::assertSame('forbidden', $e->errorCode);
            self::assertStringContainsString('HTTP 403', $e->getMessage());
        }
    }

    public function testInvalidUrlKeepsErrorCode(): void
    {
        $this->http->on('POST', '/api/websites', 400, ['statusMessage' => 'URL resolves to a private address', 'data' => ['code' => 'invalidUrl']]);
        try {
            $this->client()->createWebsite('c', 'n', 'https://10.0.0.1', 'combined', 5, []);
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(ApiException::class, $e::class);
            self::assertSame(400, $e->status);
            self::assertSame('invalidUrl', $e->errorCode);
        }
    }

    public function testInvalidPackageTypeKeepsErrorCode(): void
    {
        $this->http->on('POST', '/api/customers', 400, ['statusMessage' => 'Invalid packageType for this organization', 'data' => ['code' => 'invalidPackageType']]);
        try {
            $this->client()->createCustomer('n', 'a@b.test', 'nope', []);
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(ApiException::class, $e::class);
            self::assertSame(400, $e->status);
            self::assertSame('invalidPackageType', $e->errorCode);
        }
    }

    public function test429CarriesRetryAfterHeader(): void
    {
        $this->http->on('GET', '/api/organization', 429, ['statusCode' => 429, 'statusMessage' => 'Too Many Requests', 'data' => ['retryAfter' => 40]], ['Retry-After' => '12']);
        try {
            $this->client()->getOrganization();
            self::fail('expected RateLimitedException');
        } catch (RateLimitedException $e) {
            self::assertSame(12, $e->retryAfterSeconds);
            self::assertSame(429, $e->status);
        }
    }

    public function test429WithoutHeaderUsesDataRetryAfter(): void
    {
        $this->http->on('GET', '/api/organization', 429, ['statusMessage' => 'Too Many Requests', 'data' => ['retryAfter' => 40]]);
        try {
            $this->client()->getOrganization();
            self::fail('expected RateLimitedException');
        } catch (RateLimitedException $e) {
            self::assertSame(40, $e->retryAfterSeconds);
        }
    }

    public function test404MapsToNotFound(): void
    {
        $this->http->on('GET', '/api/customers/gone', 404, ['statusMessage' => 'Customer not found', 'data' => ['code' => 'customerNotFound']]);
        try {
            $this->client()->getCustomer('gone');
            self::fail('expected NotFoundException');
        } catch (NotFoundException $e) {
            self::assertSame('customerNotFound', $e->errorCode);
        }
    }

    public function testCodeless404MapsToNotFound(): void
    {
        $this->http->on('DELETE', '/api/websites/w1', 404, ['statusCode' => 404, 'statusMessage' => 'Website not found']);
        $this->expectException(NotFoundException::class);
        $this->client()->deleteWebsite('w1');
    }

    public function testNonJsonErrorBodyIsTruncatedIntoMessage(): void
    {
        $this->http->on('GET', '/api/organization', 502, '<html>' . str_repeat('x', 5000) . '</html>');
        try {
            $this->client()->getOrganization();
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(502, $e->status);
            self::assertSame('', $e->errorCode);
            self::assertLessThan(400, strlen($e->getMessage()));
        }
    }

    public function testCreateCustomerBodyAndEnvelope(): void
    {
        $this->http->on('POST', '/api/customers', 200, ['success' => true, 'customer' => ['publicId' => 'c1', 'id' => 64]]);
        $customer = $this->client()->createCustomer('Acme', 'ops@acme.test', 'pro', ['whmcs_client_id' => '7']);
        self::assertSame(['publicId' => 'c1', 'id' => 64], $customer);
        self::assertSame(['name' => 'Acme', 'email' => 'ops@acme.test', 'packageType' => 'pro', 'customFields' => ['whmcs_client_id' => '7']], $this->http->lastJson());
        self::assertSame('application/json', $this->http->requests()[0]['headers']['Content-Type']);
    }

    public function testEmptyCustomFieldsAreSentAsObject(): void
    {
        $this->http->on('POST', '/api/websites', 200, ['publicId' => 'w1']);
        $this->client()->createWebsite('c', 'n', 'https://a.test', 'combined', 5, []);
        self::assertStringContainsString('"customFields":{}', (string) $this->http->requests()[0]['body']);
    }

    public function testPatchCustomerSendsFieldsAndReturnsPlainObject(): void
    {
        $this->http->on('PATCH', '/api/customers/c1', 200, ['publicId' => 'c1', 'customFields' => ['a' => '1', 'whmcs_client_id' => '7']]);
        $customer = $this->client()->patchCustomer('c1', ['customFields' => ['a' => '1', 'whmcs_client_id' => '7']]);
        self::assertSame('c1', $customer['publicId']);
        self::assertSame(['customFields' => ['a' => '1', 'whmcs_client_id' => '7']], $this->http->lastJson());
    }

    public function testCreateWebsiteBodyAndPlainResponse(): void
    {
        $this->http->on('POST', '/api/websites', 200, ['publicId' => 'w1', 'id' => 42]);
        $w = $this->client()->createWebsite('cust1', 'example.com', 'https://example.com', 'combined', 5, ['whmcs_hosting_id' => '7']);
        self::assertSame(['publicId' => 'w1', 'id' => 42], $w);
        self::assertSame([
            'customerId' => 'cust1',
            'name' => 'example.com',
            'url' => 'https://example.com',
            'monitoringType' => 'combined',
            'checkInterval' => 5,
            'customFields' => ['whmcs_hosting_id' => '7'],
        ], $this->http->lastJson());
    }

    public function testSetWebsiteStatusSendsPartialUpdate(): void
    {
        $this->http->on('PATCH', '/api/websites/w1', 200, ['publicId' => 'w1', 'status' => 'inactive']);
        self::assertSame('inactive', $this->client()->setWebsiteStatus('w1', 'inactive')['status']);
        self::assertSame(['status' => 'inactive'], $this->http->lastJson());
    }

    public function testUpdateWebsiteTargetSendsAPartialUpdateWithoutCustomerId(): void
    {
        $this->http->on('PATCH', '/api/websites/w1', 200, ['publicId' => 'w1', 'url' => 'https://new.example', 'name' => 'new.example', 'status' => 'inactive']);
        $row = $this->client()->updateWebsiteTarget('w1', 'https://new.example', 'new.example');
        self::assertSame(['https://new.example', 'inactive'], [$row['url'], $row['status']]);
        self::assertSame(['url' => 'https://new.example', 'name' => 'new.example'], $this->http->lastJson(), 'no customerId: that would be the full update resetting unsent settings');
    }

    public function testUpdateWebsiteTargetRejectedBySsrfGuardIsInvalidUrl(): void
    {
        $this->http->on('PATCH', '/api/websites/w1', 400, ['statusCode' => 400, 'statusMessage' => 'Monitor URL resolves to a private address', 'data' => ['code' => 'invalidUrl']]);
        try {
            $this->client()->updateWebsiteTarget('w1', 'https://intranet.example', 'intranet.example');
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(['invalidUrl', 400], [$e->errorCode, $e->status]);
        }
    }

    public function testSetCustomerIpStatusSendsStatusOnlyAndMapsTheIpLimit(): void
    {
        $this->http->on('PATCH', '/api/customer-ips/ip1', 200, ['publicId' => 'ip1', 'status' => 'disabled']);
        self::assertSame('disabled', $this->client()->setCustomerIpStatus('ip1', 'disabled')['status']);
        self::assertSame(['status' => 'disabled'], $this->http->lastJson());

        $limited = (new FakeHttpClient())->on('PATCH', '/api/customer-ips/ip1', 403, [
            'statusCode' => 403,
            'statusMessage' => 'Active IP limit reached. Your customer package allows maximum 1 active IPs for DNSBL monitoring.',
            'data' => ['code' => 'generic'],
        ]);
        try {
            $this->client($limited)->setCustomerIpStatus('ip1', 'active');
            self::fail('expected QuotaExceededException');
        } catch (QuotaExceededException $e) {
            self::assertSame(Client::IP_LIMIT_CODE, $e->errorCode);
        }
    }

    public function testDeleteSendsNoBody(): void
    {
        $this->http->on('DELETE', '/api/customer-ips/ip1', 200, ['success' => true]);
        $this->client()->deleteCustomerIp('ip1');
        self::assertSame('DELETE', $this->http->requests()[0]['method']);
        self::assertNull($this->http->requests()[0]['body']);
    }

    public function testGetUptimeStatsReturnsPlainObject(): void
    {
        $stats = ['day' => '100.00', 'month' => '99.90', 'year' => '99.95', 'dayAvgResponse' => 120, 'monthAvgResponse' => 118, 'yearAvgResponse' => 121];
        $this->http->on('GET', '/api/websites/w1/uptime-stats', 200, $stats);
        self::assertSame($stats, $this->client()->getUptimeStats('w1'));
    }

    public function testCreateCustomerDomainBodyAndDeduplicatedFlag(): void
    {
        $this->http->on('POST', '/api/customers/c1/domains', 200, ['publicId' => 'd1', 'id' => 3, 'deduplicated' => true]);
        $domain = $this->client()->createCustomerDomain('c1', 'example.com', 'WHMCS');
        self::assertTrue($domain['deduplicated']);
        self::assertSame(3, $domain['id']);
        self::assertSame(['domainName' => 'example.com', 'label' => 'WHMCS', 'expiryWarningDays' => 30, 'expiryErrorDays' => 7], $this->http->lastJson());
    }

    public function testCreateCustomerIpBody(): void
    {
        $this->http->on('POST', '/api/customers/c1/ips', 200, ['publicId' => 'ip1', 'deduplicated' => false]);
        self::assertSame('ip1', $this->client()->createCustomerIp('c1', '203.0.113.1', 'srv1')['publicId']);
        self::assertSame(['ipAddress' => '203.0.113.1', 'label' => 'srv1', 'status' => 'active'], $this->http->lastJson());
    }

    public function testCreateDnsMonitorBodyAndPlainResponse(): void
    {
        $config = ['rrtypes' => ['A'], 'matchMode' => 'exact', 'expectedValues' => ['A' => ['203.0.113.1']], 'triggerOn' => ['resolveError' => true, 'mismatch' => false]];
        $this->http->on('POST', '/api/dns-monitors', 200, ['publicId' => 'dns1', 'id' => 5]);
        self::assertSame('dns1', $this->client()->createDnsMonitor('c1', 'example.com', 'example.com', $config)['publicId']);
        self::assertSame(['customerId' => 'c1', 'name' => 'example.com', 'hostname' => 'example.com', 'dnsConfig' => $config], $this->http->lastJson());
    }

    public function testTagsListCreateAndAssign(): void
    {
        $this->http
            ->on('GET', '/api/tags', 200, [['id' => 4, 'name' => 'whmcs', 'color' => 'teal', 'customerId' => null]])
            ->on('POST', '/api/tags', 200, ['id' => 9, 'publicId' => 't9', 'name' => 'whmcs', 'color' => 'teal'])
            ->on('POST', '/api/monitor-tags', 200, ['id' => 1, 'tagId' => 9, 'monitorType' => 'website', 'monitorId' => 42]);
        $client = $this->client();
        self::assertSame([4], array_column($client->listTags(), 'id'));
        self::assertSame(9, $client->createTag('whmcs', 'teal')['id']);
        self::assertSame(['name' => 'whmcs', 'color' => 'teal'], $this->http->lastJson());
        $client->addMonitorTag('website', 42, 9);
        self::assertSame(['monitorType' => 'website', 'monitorId' => 42, 'tagId' => 9], $this->http->lastJson());
    }

    /**
     * Pages from a callback: fn(int $page): array|string (array is JSON-encoded).
     *
     * @param \Closure(int):(array<mixed>|string) $pageBody
     */
    private static function scriptedHttp(\Closure $pageBody): FakeHttpClient
    {
        return new class ($pageBody) extends FakeHttpClient {
            /** @param \Closure(int):(array<mixed>|string) $pageBody */
            public function __construct(private readonly \Closure $pageBody)
            {
            }

            public function send(string $method, string $url, array $headers, ?string $body, float $timeout): HttpResponse
            {
                parent::on($method, (string) parse_url($url, PHP_URL_PATH), 200, []);
                parent::send($method, $url, $headers, $body, $timeout);
                parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
                $result = ($this->pageBody)((int) ($q['page'] ?? 1));
                return new HttpResponse(200, [], is_array($result) ? (string) json_encode($result) : $result);
            }
        };
    }

    private function assertInvalidResponse(\Closure $call, string $reason): void
    {
        try {
            $call();
            self::fail('expected invalidResponse');
        } catch (ApiException $e) {
            self::assertSame(ApiException::class, $e::class);
            self::assertSame(Client::INVALID_RESPONSE_CODE, $e->errorCode);
            self::assertSame(0, $e->status);
            self::assertStringContainsString($reason, $e->getMessage());
        }
    }

    public function testNonJson2xxBodyIsInvalidResponse(): void
    {
        $this->http->on('GET', '/api/organization', 200, '<html>login</html>');
        $this->assertInvalidResponse(fn () => $this->client()->getOrganization(), 'not JSON');
    }

    public function testObjectEndpointRejectsEmptyBodyListAndScalar(): void
    {
        foreach (['', '[]', '"ok"', '[{"id":1}]'] as $body) {
            $http = (new FakeHttpClient())->on('GET', '/api/organization', 200, $body);
            $this->assertInvalidResponse(fn () => $this->client($http)->getOrganization(), 'expected a JSON object');
        }
    }

    public function testListEndpointRejectsObject(): void
    {
        $this->http->on('GET', '/api/tags', 200, ['items' => [['id' => 1]]]);
        $this->assertInvalidResponse(fn () => $this->client()->listTags(), 'expected a JSON array');
    }

    public function testListEndpointRejectsNonObjectEntries(): void
    {
        $this->http->on('GET', '/api/package-configs', 200, [['packageType' => 'pro'], 'oops']);
        $this->assertInvalidResponse(fn () => $this->client()->listPackageConfigs(), 'array of objects');
    }

    public function testCreateCustomerWithoutCustomerObjectIsInvalid(): void
    {
        $this->http->on('POST', '/api/customers', 200, ['success' => true]);
        $this->assertInvalidResponse(fn () => $this->client()->createCustomer('n', 'a@b.test', 'pro', []), 'no customer object');
    }

    public function testDeleteWithEmptyBodySucceeds(): void
    {
        $this->http->on('DELETE', '/api/websites/w1', 204, '');
        $this->client()->deleteWebsite('w1');
        self::assertCount(1, $this->http->requests());
    }

    public function testFirstPageWithoutItemsIsInvalid(): void
    {
        $http = self::scriptedHttp(static fn (int $page) => [['publicId' => 'c1']]);
        $this->assertInvalidResponse(fn () => $this->client($http)->listCustomers(), 'expected a JSON object');
        $http = self::scriptedHttp(static fn (int $page) => ['total' => 1]);
        $this->assertInvalidResponse(fn () => $this->client($http)->listWebsites(), 'missing items');
    }

    public function testMidLoopPageWithoutItemsDoesNotReturnPartialList(): void
    {
        $http = self::scriptedHttp(static fn (int $page) => $page === 1
            ? ['items' => [['publicId' => 'a'], ['publicId' => 'b']], 'total' => 4, 'page' => 1, 'perPage' => 2]
            : '<html>maintenance</html>');
        $this->assertInvalidResponse(fn () => $this->client($http)->listWebsites(), 'not JSON');

        $http = self::scriptedHttp(static fn (int $page) => $page === 1
            ? ['items' => [['publicId' => 'a'], ['publicId' => 'b']], 'total' => 4, 'page' => 1, 'perPage' => 2]
            : ['total' => 4]);
        $this->assertInvalidResponse(fn () => $this->client($http)->listCustomerIps(), 'missing items');
    }

    public function testPageWithoutTotalIsInvalid(): void
    {
        $http = self::scriptedHttp(static fn (int $page) => ['items' => [['publicId' => 'a']]]);
        $this->assertInvalidResponse(fn () => $this->client($http)->listDnsMonitors(), 'missing total');
    }

    public function testOverlappingPagesReturnUniqueRows(): void
    {
        // Rows shifting between pages (e.g. a delete-then-insert race or createdAt ties): b is on both pages.
        $http = self::scriptedHttp(static fn (int $page) => match ($page) {
            1 => ['items' => [['publicId' => 'a'], ['publicId' => 'b']], 'total' => 4, 'page' => 1, 'perPage' => 2],
            2 => ['items' => [['publicId' => 'b'], ['publicId' => 'c']], 'total' => 5, 'page' => 2, 'perPage' => 2],
            default => ['items' => [['publicId' => 'd']], 'total' => 5, 'page' => $page, 'perPage' => 2],
        });
        self::assertSame(['a', 'b', 'c', 'd'], array_column($this->client($http)->listWebsites(), 'publicId'));
        self::assertCount(3, $http->requests());
    }

    public function testOverlappingIncidentPagesReturnUniqueRows(): void
    {
        $http = new class () extends FakeHttpClient {
            public function send(string $method, string $url, array $headers, ?string $body, float $timeout): HttpResponse
            {
                parent::on('GET', '/api/incidents', 200, []);
                parent::send($method, $url, $headers, $body, $timeout);
                parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
                $offset = (int) ($q['offset'] ?? 0);
                // startedAt ties without a tiebreaker: id 2 appears on both pages.
                $items = $offset === 0 ? [['id' => 1], ['id' => 2]] : [['id' => 2], ['id' => 3]];
                return new HttpResponse(200, [], (string) json_encode(['incidents' => $items, 'total' => 4, 'limit' => 2, 'offset' => $offset]));
            }
        };
        self::assertSame([1, 2, 3], array_column($this->client($http)->listRecentIncidents(1000)['incidents'], 'id'));
        self::assertSame([
            'https://api.test/api/incidents?limit=500&includeTotal=1&offset=0',
            'https://api.test/api/incidents?limit=500&includeTotal=1&offset=2',
        ], array_column($http->requests(), 'url'));
    }

    public function testServerIgnoringPageFailsFast(): void
    {
        $http = self::scriptedHttp(static fn (int $page) => ['items' => [['publicId' => 'a'], ['publicId' => 'b']], 'total' => 5, 'page' => 1, 'perPage' => 2]);
        $this->assertInvalidResponse(fn () => $this->client($http)->listCustomerDomains(), 'duplicate publicId');
        self::assertCount(2, $http->requests());
    }

    public function testPaginationStopsAtMaxPages(): void
    {
        $http = self::scriptedHttp(static fn (int $page) => ['items' => [['publicId' => 'p' . $page]], 'total' => 1_000_000, 'page' => $page, 'perPage' => 1]);
        $this->assertInvalidResponse(fn () => $this->client($http)->listWebsites(), 'more than 1000 pages');
        self::assertCount(1000, $http->requests());
    }

    public function testIncidentsWithoutIncidentsKeyIsInvalid(): void
    {
        $this->http->on('GET', '/api/incidents', 200, [['id' => 1, 'status' => 'open']]);
        $this->assertInvalidResponse(fn () => $this->client()->listRecentIncidents(1000), 'expected a JSON object');
    }

    public function testIncidentsServerIgnoringOffsetFailsFast(): void
    {
        $this->http->on('GET', '/api/incidents', 200, ['incidents' => [['id' => 1], ['id' => 2]], 'total' => 9, 'limit' => 500, 'offset' => 0]);
        $this->assertInvalidResponse(fn () => $this->client()->listRecentIncidents(1000), 'duplicate id');
    }

    public function test429WithoutHeaderAndDataUsesDefault(): void
    {
        $this->http->on('GET', '/api/organization', 429, ['statusCode' => 429, 'statusMessage' => 'Too Many Requests']);
        try {
            $this->client()->getOrganization();
            self::fail('expected RateLimitedException');
        } catch (RateLimitedException $e) {
            self::assertSame(5, $e->retryAfterSeconds);
        }
    }

    public function test429WithHttpDateHeaderUsesDefault(): void
    {
        $this->http->on('GET', '/api/organization', 429, ['statusMessage' => 'Too Many Requests'], ['Retry-After' => 'Wed, 21 Oct 2026 07:28:00 GMT']);
        try {
            $this->client()->getOrganization();
            self::fail('expected RateLimitedException');
        } catch (RateLimitedException $e) {
            self::assertSame(5, $e->retryAfterSeconds);
        }
    }

    public function testUnencodableBodyBecomesApiExceptionWithoutRequest(): void
    {
        try {
            $this->client()->createCustomer("M\xFCller GmbH", 'a@b.test', 'pro', []);
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(0, $e->status);
            self::assertSame(Client::INVALID_REQUEST_CODE, $e->errorCode);
            self::assertInstanceOf(\JsonException::class, $e->getPrevious());
        }
        self::assertSame([], $this->http->requests());
    }

    public function testApiTokenPathAmbiguousKeepsErrorCode(): void
    {
        $this->http->on('GET', '/api/customers/a%2Fb', 403, ['statusCode' => 403, 'statusMessage' => 'Forbidden', 'data' => ['code' => 'apiTokenPathAmbiguous']]);
        try {
            $this->client()->getCustomer('a/b');
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(ApiException::class, $e::class);
            self::assertSame(403, $e->status);
            self::assertSame('apiTokenPathAmbiguous', $e->errorCode);
        }
    }

    public function testTransportErrorBecomesApiExceptionWithStatusZero(): void
    {
        $http = new class () extends FakeHttpClient {
            public function send(string $method, string $url, array $headers, ?string $body, float $timeout): HttpResponse
            {
                throw new TransportException('dns fail');
            }
        };
        try {
            $this->client($http)->getOrganization();
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(0, $e->status);
            self::assertSame('transport', $e->errorCode);
            self::assertStringContainsString('dns fail', $e->getMessage());
            self::assertInstanceOf(TransportException::class, $e->getPrevious());
        }
    }

    public function testTimeoutIsPassedToTransport(): void
    {
        $http = new class () extends FakeHttpClient {
            public float $seenTimeout = 0.0;

            public function send(string $method, string $url, array $headers, ?string $body, float $timeout): HttpResponse
            {
                $this->seenTimeout = $timeout;
                return new HttpResponse(200, [], '{"id":1}');
            }
        };
        $this->client($http)->getOrganization();
        self::assertSame(3.0, $http->seenTimeout);
    }
}
