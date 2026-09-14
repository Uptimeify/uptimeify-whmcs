<?php

declare(strict_types=1);

namespace Uptimeify\Tests\Unit\Sync;

use Uptimeify\Tests\Fake\FakeHttpClient;
use Uptimeify\Tests\Support\DbTestCase;
use WHMCS\Module\Addon\Uptimeify\Api\Client;
use WHMCS\Module\Addon\Uptimeify\Api\Exception\UnauthorizedException;
use WHMCS\Module\Addon\Uptimeify\Settings;
use WHMCS\Module\Addon\Uptimeify\Store\CustomerMap;
use WHMCS\Module\Addon\Uptimeify\Store\Schema;
use WHMCS\Module\Addon\Uptimeify\Sync\CustomerMirror;
use WHMCS\Module\Addon\Uptimeify\Sync\RateLimitGuard;
use WHMCS\Module\Addon\Uptimeify\Sync\ReconcileResult;
use WHMCS\Module\Addon\Uptimeify\Sync\RemoteIndex;
use WHMCS\Module\Addon\Uptimeify\Whmcs\ClientRow;

final class CustomerMirrorTest extends DbTestCase
{
    private FakeHttpClient $http;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::install();
        $this->http = new FakeHttpClient();
    }

    private static function guard(): RateLimitGuard
    {
        return new RateLimitGuard(function (int $s): void {
        });
    }

    /** @param list<array<string,mixed>> $customers */
    private function index(array $customers): RemoteIndex
    {
        $this->http->on('GET', '/api/customers', 200, ['items' => $customers, 'total' => count($customers), 'page' => 1, 'perPage' => 100]);
        return RemoteIndex::load(new Client($this->http, 'https://api.test', 'wsm_x'), self::guard(), []);
    }

    private function mirror(string $package = 'starter'): CustomerMirror
    {
        $s = new Settings();
        $s->set('default_package_type', $package);
        return new CustomerMirror(new Client($this->http, 'https://api.test', 'wsm_x'), $s, new CustomerMap(), self::guard());
    }

    /** @return list<array{method:string,url:string,headers:array<string,string>,body:?string}> */
    private function requests(string $method): array
    {
        return array_values(array_filter($this->http->requests(), fn (array $r): bool => $r['method'] === $method));
    }

    /** @return array<mixed> */
    private function lastBody(string $method): array
    {
        $requests = $this->requests($method);
        self::assertNotSame([], $requests, 'no ' . $method . ' request sent');
        $decoded = json_decode((string) $requests[count($requests) - 1]['body'], true);
        self::assertIsArray($decoded);
        return $decoded;
    }

    public function testStoredMappingWinsWhenCustomerStillExists(): void
    {
        $index = $this->index([['publicId' => 'c1', 'name' => 'Other', 'email' => 'o@example.com']]);
        (new CustomerMap())->set(5, 'c1', null, 'manual');
        self::assertSame('c1', $this->mirror()->resolve(new ClientRow(5, 'x@example.com', 'X', 'Active'), $index, true));
        self::assertCount(1, $this->http->requests(), 'no API write for a stored mapping');
        self::assertSame('manual', (new CustomerMap())->get(5)['source'] ?? null);
    }

    public function testMatchByWhmcsIdThenEmailThenNameAndPatchesId(): void
    {
        $index = $this->index([
            ['publicId' => 'cid', 'name' => 'A', 'email' => 'a@example.com', 'customFields' => ['whmcs_client_id' => '1']],
            ['publicId' => 'cmail', 'name' => 'B', 'email' => 'b@example.com', 'packageDisplayName' => 'Starter'],
            ['publicId' => 'cname', 'name' => 'Gamma GmbH', 'email' => 'other@example.com', 'customFields' => ['contract' => 'K-1']],
        ]);
        $this->http->on('PATCH', '#^/api/customers/(cmail|cname)$#', 200, ['id' => 2, 'publicId' => 'x', 'customFields' => []]);
        $m = $this->mirror();
        self::assertSame('cid', $m->resolve(new ClientRow(1, 'zzz@example.com', 'ZZZ', 'Active'), $index, true));
        self::assertSame('cmail', $m->resolve(new ClientRow(2, 'b@example.com', 'Nope', 'Active'), $index, true));
        self::assertSame('cname', $m->resolve(new ClientRow(3, 'c@example.com', 'gamma gmbh', 'Active'), $index, true));
        $map = new CustomerMap();
        self::assertSame('matched_id', $map->get(1)['source'] ?? null);
        self::assertSame('matched_email', $map->get(2)['source'] ?? null);
        self::assertSame('Starter', $map->get(2)['package_type'] ?? null);
        self::assertSame('matched_name', $map->get(3)['source'] ?? null);
        $patches = $this->requests('PATCH');
        self::assertCount(2, $patches, 'no PATCH for a customer matched by whmcs_client_id');
        self::assertSame(['customFields' => ['whmcs_client_id' => '2']], json_decode((string) $patches[0]['body'], true), 'package is never sent');
        self::assertSame(['customFields' => ['contract' => 'K-1', 'whmcs_client_id' => '3']], json_decode((string) $patches[1]['body'], true), 'existing custom fields are kept');
        self::assertSame('cmail', $index->customerByWhmcsId(2)['publicId'] ?? null, 'index learns the id');
        self::assertSame('cname', $index->customerByWhmcsId(3)['publicId'] ?? null);
        self::assertSame([], $this->requests('POST'));
    }

    public function testCreatesWithDefaultPackageOrReturnsNull(): void
    {
        $index = $this->index([]);
        $this->http->on('POST', '/api/customers', 200, ['success' => true, 'customer' => ['publicId' => 'cnew', 'name' => 'New', 'email' => 'n@example.com', 'packageType' => 'starter', 'customFields' => ['whmcs_client_id' => '7']]]);
        $m = $this->mirror();
        self::assertNull($m->resolve(new ClientRow(7, 'n@example.com', 'New', 'Active'), $index, false));
        $before = (new CustomerMap())->get(7);
        self::assertNull($before, 'nothing is mapped without a match');
        self::assertSame('cnew', $m->resolve(new ClientRow(7, 'n@example.com', 'New', 'Active'), $index, true));
        $sent = $this->lastBody('POST');
        self::assertSame('New', $sent['name']);
        self::assertSame('n@example.com', $sent['email']);
        self::assertSame('starter', $sent['packageType']);
        self::assertSame(['whmcs_client_id' => '7'], $sent['customFields']);
        self::assertSame('created', (new CustomerMap())->get(7)['source'] ?? null);
        self::assertSame('starter', (new CustomerMap())->get(7)['package_type'] ?? null);
        self::assertSame('cnew', $index->customerByWhmcsId(7)['publicId'] ?? null);
        self::assertSame('cnew', $m->resolve(new ClientRow(7, 'n@example.com', 'New', 'Active'), $index, true));
        self::assertCount(1, $this->requests('POST'), 'never created twice');
        self::assertStringContainsString('cnew', $GLOBALS['uptimeify_test']['activity'][0][0] ?? '');
    }

    public function testCreateUsesNameFallbackAndEmptyEmailWhenClientHasNone(): void
    {
        $index = $this->index([['publicId' => 'cblank', 'name' => '', 'email' => '']]);
        $this->http->on('POST', '/api/customers', 200, ['success' => true, 'customer' => ['publicId' => 'c', 'customFields' => []]]);
        self::assertSame('c', $this->mirror()->resolve(new ClientRow(8, '', '', 'Active'), $index, true));
        $sent = $this->lastBody('POST');
        self::assertSame('whmcs-client-8', $sent['name']);
        self::assertSame('', $sent['email'], 'no made-up address that would bounce');
        self::assertSame('c', $index->customerByWhmcsId(8)['publicId'] ?? null, 'id is indexed even if the response omits it');
    }

    public function testCreateWithoutDefaultPackageThrows(): void
    {
        $index = $this->index([]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('default package not configured');
        $this->mirror('  ')->resolve(new ClientRow(9, 'p@example.com', 'P', 'Active'), $index, true);
    }

    public function testStoredCustomerMissingFromListIsConfirmedByDirectGetBeforeAnythingElse(): void
    {
        $index = $this->index([['publicId' => 'cother', 'name' => 'X', 'email' => 'x@example.com']]);
        (new CustomerMap())->set(5, 'cskipped', 'pro', 'manual');
        $this->http->on('GET', '/api/customers/cskipped', 200, ['id' => 3, 'publicId' => 'cskipped', 'name' => 'Skipped', 'email' => 's@example.com', 'customFields' => []]);
        $m = $this->mirror();
        self::assertSame('cskipped', $m->resolve(new ClientRow(5, 'x@example.com', 'X', 'Active'), $index, true), 'a row skipped by offset paging does not lose its mapping');
        self::assertSame('manual', (new CustomerMap())->get(5)['source'] ?? null);
        self::assertSame('cskipped', $index->customerByPublicId('cskipped')['publicId'] ?? null, 'index learns the customer');
        self::assertSame([], $this->requests('POST'));
        self::assertSame([], $this->requests('PATCH'));
        self::assertSame('cskipped', $m->resolve(new ClientRow(5, 'x@example.com', 'X', 'Active'), $index, true));
        self::assertCount(2, $this->requests('GET'), 'confirmed once, then served from the index');
    }

    public function testStoredCustomerGoneFallsThroughToMatching(): void
    {
        $index = $this->index([['publicId' => 'cmail', 'name' => 'B', 'email' => 'b@example.com', 'customFields' => ['whmcs_client_id' => '5']]]);
        (new CustomerMap())->set(5, 'cgone', null, 'created');
        $this->http->on('GET', '/api/customers/cgone', 404, ['statusCode' => 404, 'statusMessage' => 'Customer not found']);
        self::assertSame('cmail', $this->mirror()->resolve(new ClientRow(5, 'b@example.com', 'B', 'Active'), $index, true));
        self::assertSame('matched_id', (new CustomerMap())->get(5)['source'] ?? null);
        self::assertSame([], $this->requests('POST'));
    }

    public function testStoredCustomerGoneWithoutMatchCreatesOnceOrUnmaps(): void
    {
        $index = $this->index([]);
        (new CustomerMap())->set(5, 'cgone', null, 'created');
        $this->http
            ->on('GET', '/api/customers/cgone', 404, ['statusCode' => 404, 'statusMessage' => 'Customer not found'])
            ->on('POST', '/api/customers', 200, ['success' => true, 'customer' => ['publicId' => 'cnew', 'customFields' => ['whmcs_client_id' => '5']]]);
        $m = $this->mirror();
        self::assertNull($m->resolve(new ClientRow(5, 'n@example.com', 'N', 'Active'), $index, false));
        self::assertNull((new CustomerMap())->get(5), 'a confirmed-gone customer leaves the client without mapping');

        (new CustomerMap())->set(5, 'cgone', null, 'created');
        self::assertSame('cnew', $m->resolve(new ClientRow(5, 'n@example.com', 'N', 'Active'), $index, true));
        self::assertSame('cnew', $m->resolve(new ClientRow(5, 'n@example.com', 'N', 'Active'), $index, true));
        self::assertCount(1, $this->requests('POST'));
        self::assertSame('created', (new CustomerMap())->get(5)['source'] ?? null);
    }

    public function testEmailOrNameMatchSkipsCustomerTaggedForAnotherClient(): void
    {
        $index = $this->index([
            ['publicId' => 'ctaken', 'name' => 'Same Name', 'email' => 'same@example.com', 'customFields' => ['whmcs_client_id' => '4']],
        ]);
        $this->http->on('POST', '/api/customers', 200, ['success' => true, 'customer' => ['publicId' => 'cown', 'customFields' => ['whmcs_client_id' => '6']]]);
        self::assertSame('cown', $this->mirror()->resolve(new ClientRow(6, 'same@example.com', 'Same Name', 'Active'), $index, true));
        self::assertSame([], $this->requests('PATCH'));
    }

    public function testStoredEmailOrNameMatchRetriesTheBackFill(): void
    {
        $index = $this->index([
            ['publicId' => 'c1', 'name' => 'B', 'email' => 'b@example.com', 'customFields' => ['a' => 1]],
            ['publicId' => 'c2', 'name' => 'M', 'email' => 'm@example.com'],
        ]);
        (new CustomerMap())->set(2, 'c1', null, 'matched_email');
        (new CustomerMap())->set(3, 'c2', null, 'manual');
        $this->http->on('PATCH', '/api/customers/c1', 200, ['id' => 1, 'publicId' => 'c1']);
        $m = $this->mirror();
        self::assertSame('c1', $m->resolve(new ClientRow(2, 'b@example.com', 'B', 'Active'), $index, true));
        self::assertSame(['customFields' => ['a' => 1, 'whmcs_client_id' => '2']], $this->lastBody('PATCH'), 'an earlier failed back-fill is retried');
        self::assertSame('c1', $m->resolve(new ClientRow(2, 'b@example.com', 'B', 'Active'), $index, true));
        self::assertSame('c2', $m->resolve(new ClientRow(3, 'm@example.com', 'M', 'Active'), $index, true));
        self::assertCount(1, $this->requests('PATCH'), 'once tagged, and never for a manual mapping');

        $index->addCustomer(['publicId' => 'c4', 'name' => 'S', 'email' => 's@example.com']);
        (new CustomerMap())->set(4, 'c4', null, 'matched_email');
        (new CustomerMap())->set(5, 'c4', null, 'manual');
        self::assertSame('c4', $m->resolve(new ClientRow(4, 's@example.com', 'S', 'Active'), $index, true));
        self::assertCount(1, $this->requests('PATCH'), 'a customer also mapped to another client is not tagged for either');
    }

    public function testEmailOrNameMatchSkipsCustomerHeldByAnotherClientsManualMapping(): void
    {
        $index = $this->index([
            ['publicId' => 'cheld', 'name' => 'Same Name', 'email' => 'same@example.com'],
        ]);
        (new CustomerMap())->set(3, 'cheld', null, 'manual');
        $this->http->on('POST', '/api/customers', 200, ['success' => true, 'customer' => ['publicId' => 'cown', 'customFields' => ['whmcs_client_id' => '6']]]);
        $m = $this->mirror();
        self::assertNull($m->resolve(new ClientRow(6, 'same@example.com', 'Same Name', 'Active'), $index, false), 'no match and no auto-create');
        self::assertSame('cown', $m->resolve(new ClientRow(6, 'same@example.com', 'Same Name', 'Active'), $index, true));
        self::assertSame([], $this->requests('PATCH'), 'a customer held by another client is never tagged');
        self::assertSame([3], (new CustomerMap())->clientIdsFor('cheld'));
        self::assertSame([6], (new CustomerMap())->clientIdsFor('cown'));
    }

    public function testEmailMatchSkipsCustomerWhoseBackFillFailedForAnotherClient(): void
    {
        $index = $this->index([
            ['publicId' => 'cx', 'name' => 'Twin', 'email' => 'twin@example.com'],
        ]);
        $this->http
            ->on('PATCH', '/api/customers/cx', 500, ['statusCode' => 500, 'statusMessage' => 'Server Error', 'data' => ['code' => 'generic']])
            ->on('POST', '/api/customers', 200, ['success' => true, 'customer' => ['publicId' => 'ctwin', 'customFields' => ['whmcs_client_id' => '7']]]);
        $m = $this->mirror();
        self::assertSame('cx', $m->resolve(new ClientRow(2, 'twin@example.com', 'Twin', 'Active'), $index, true));
        self::assertNull($index->customerByWhmcsId(2), 'back-fill failed, customer stays untagged');
        self::assertSame('ctwin', $m->resolve(new ClientRow(7, 'twin@example.com', 'Twin', 'Active'), $index, true), 'second client gets its own customer');
        self::assertCount(1, $this->requests('PATCH'), 'cx is not tagged for client 7');
        self::assertSame([2], (new CustomerMap())->clientIdsFor('cx'));
    }

    public function testCreatedCustomerIsCountedInTheResult(): void
    {
        $index = $this->index([]);
        $this->http->on('POST', '/api/customers', 200, ['success' => true, 'customer' => ['publicId' => 'cnew', 'customFields' => ['whmcs_client_id' => '7']]]);
        $result = new ReconcileResult();
        $m = $this->mirror();
        self::assertSame('cnew', $m->resolve(new ClientRow(7, 'n@example.com', 'New', 'Active'), $index, true, $result));
        self::assertSame('cnew', $m->resolve(new ClientRow(7, 'n@example.com', 'New', 'Active'), $index, true, $result));
        self::assertSame(1, $result->customersCreated);
    }

    public function testIdTagOfCustomerHeldByAnotherClientIsAConflictNotAMatch(): void
    {
        $index = $this->index([
            ['publicId' => 'cheld', 'name' => 'Same', 'email' => 'same@example.com', 'customFields' => ['whmcs_client_id' => '6']],
        ]);
        (new CustomerMap())->set(3, 'cheld', null, 'manual');
        $result = new ReconcileResult();
        self::assertNull($this->mirror()->resolve(new ClientRow(6, 'same@example.com', 'Same', 'Active'), $index, false, $result));
        self::assertNull((new CustomerMap())->get(6), 'client 6 is not mapped to the customer client 3 holds');
        self::assertSame([3], (new CustomerMap())->clientIdsFor('cheld'));
        $log = implode("\n", array_column($GLOBALS['uptimeify_test']['activity'], 0));
        self::assertStringContainsString('conflict', strtolower($log));
        self::assertStringContainsString('cheld', $log);
        self::assertCount(1, $result->messages);
    }

    public function testPatchFailureIsLoggedAndIgnoredButUnauthorizedPropagates(): void
    {
        $index = $this->index([
            ['publicId' => 'c1', 'name' => 'B', 'email' => 'b@example.com'],
            ['publicId' => 'c2', 'name' => 'C', 'email' => 'c@example.com'],
        ]);
        $this->http
            ->on('PATCH', '/api/customers/c1', 500, ['statusCode' => 500, 'statusMessage' => 'Server Error', 'data' => ['code' => 'generic']])
            ->on('PATCH', '/api/customers/c2', 401, ['statusCode' => 401, 'statusMessage' => 'Unauthorized']);
        $m = $this->mirror();
        self::assertSame('c1', $m->resolve(new ClientRow(2, 'b@example.com', 'B', 'Active'), $index, true));
        self::assertSame('matched_email', (new CustomerMap())->get(2)['source'] ?? null);
        self::assertNull($index->customerByWhmcsId(2), 'index does not learn an id the API did not store');
        self::assertStringContainsString('c1', $GLOBALS['uptimeify_test']['activity'][0][0] ?? '');

        $this->expectException(UnauthorizedException::class);
        $m->resolve(new ClientRow(3, 'c@example.com', 'C', 'Active'), $index, true);
    }
}
