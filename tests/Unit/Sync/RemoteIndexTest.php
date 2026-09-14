<?php

declare(strict_types=1);

namespace Uptimeify\Tests\Unit\Sync;

use PHPUnit\Framework\TestCase;
use Uptimeify\Tests\Fake\FakeHttpClient;
use WHMCS\Module\Addon\Uptimeify\Api\Client;
use WHMCS\Module\Addon\Uptimeify\Store\MonitorMap;
use WHMCS\Module\Addon\Uptimeify\Sync\RateLimitGuard;
use WHMCS\Module\Addon\Uptimeify\Sync\RemoteIndex;

final class RemoteIndexTest extends TestCase
{
    /**
     * @param list<array<string,mixed>> $items
     * @return array<string,mixed>
     */
    private static function page(array $items): array
    {
        return ['items' => $items, 'total' => count($items), 'page' => 1, 'perPage' => 100];
    }

    private static function guard(): RateLimitGuard
    {
        return new RateLimitGuard(function (int $s): void {
        });
    }

    public function testLoadsAndIndexesCustomersAndMonitors(): void
    {
        $http = (new FakeHttpClient())
            ->on('GET', '/api/customers', 200, self::page([
                ['publicId' => 'c1', 'name' => 'Acme', 'email' => 'Acme@Example.com', 'customFields' => ['whmcs_client_id' => '5']],
                ['publicId' => 'c2', 'name' => 'Beta', 'email' => 'beta@example.com', 'customFields' => null],
            ]))
            ->on('GET', '/api/websites', 200, self::page([
                ['publicId' => 'w1', 'id' => 11, 'url' => 'https://www.site.example/', 'customerId' => 1, 'customerPublicId' => 'c1', 'isDown' => false, 'status' => 'active'],
            ]))
            ->on('GET', '/api/customer-domains', 200, self::page([
                ['publicId' => 'd1', 'id' => 21, 'domainName' => 'Reg.Example', 'customerId' => 2, 'customerPublicId' => 'c2'],
            ]));
        $index = RemoteIndex::load(new Client($http, 'https://api.test', 'wsm_x'), self::guard(), [MonitorMap::TYPE_WEBSITE, MonitorMap::TYPE_CUSTOMER_DOMAIN]);

        self::assertSame('c1', $index->customerByWhmcsId(5)['publicId'] ?? null);
        self::assertSame('c1', $index->customerByEmail('acme@example.com')['publicId'] ?? null);
        self::assertSame('c2', $index->customerByName('beta')['publicId'] ?? null);
        self::assertSame('c2', $index->customerByPublicId('c2')['publicId'] ?? null);
        self::assertNull($index->customerByWhmcsId(6));
        self::assertCount(2, $index->customers());
        self::assertSame('w1', $index->monitor(MonitorMap::TYPE_WEBSITE, 'c1', 'site.example')['publicId'] ?? null);
        self::assertSame('d1', $index->monitor(MonitorMap::TYPE_CUSTOMER_DOMAIN, 'c2', 'reg.example')['publicId'] ?? null);
        self::assertNull($index->monitor(MonitorMap::TYPE_WEBSITE, 'c2', 'site.example'));
        self::assertSame([], $index->allMonitors(MonitorMap::TYPE_DNS), 'types not loaded are empty, not an error');
        $paths = array_map(fn (array $r): string => (string) parse_url($r['url'], PHP_URL_PATH), $http->requests());
        self::assertSame(['/api/customers', '/api/websites', '/api/customer-domains'], $paths, 'only the requested types are loaded');

        $index->addMonitor(MonitorMap::TYPE_WEBSITE, ['publicId' => 'w2', 'id' => 12, 'url' => 'https://new.example', 'customerPublicId' => 'c2']);
        self::assertSame('w2', $index->monitorByPublicId(MonitorMap::TYPE_WEBSITE, 'w2')['publicId'] ?? null);
        self::assertSame(['w2'], array_column($index->monitorsFor(MonitorMap::TYPE_WEBSITE, 'c2'), 'publicId'));
        $index->removeMonitor(MonitorMap::TYPE_WEBSITE, 'w2');
        self::assertNull($index->monitorByPublicId(MonitorMap::TYPE_WEBSITE, 'w2'));
        self::assertSame([], $index->monitorsFor(MonitorMap::TYPE_WEBSITE, 'c2'));
        $index->addCustomer(['publicId' => 'c3', 'name' => 'Gamma', 'email' => 'g@example.com', 'customFields' => ['whmcs_client_id' => 9]]);
        self::assertSame('c3', $index->customerByWhmcsId(9)['publicId'] ?? null, 'int and string ids compare equal');
    }

    public function testDnsAndIpListsUseTheirVerifiedCustomerKeys(): void
    {
        $http = (new FakeHttpClient())
            ->on('GET', '/api/customers', 200, self::page([['publicId' => 'c1', 'name' => 'Acme']]))
            ->on('GET', '/api/dns-monitors', 200, self::page([
                ['publicId' => 'n1', 'id' => 31, 'hostname' => 'Zone.Example.', 'customerId' => 1, 'customerPublicId' => 'c1'],
            ]))
            ->on('GET', '/api/customer-ips', 200, self::page([
                ['publicId' => 'i1', 'id' => 41, 'ipAddress' => '2001:db8:0:0::1', 'customerId' => 1, 'customer' => ['id' => 1, 'publicId' => 'c1']],
            ]));
        $index = RemoteIndex::load(new Client($http, 'https://api.test', 'wsm_x'), self::guard(), [MonitorMap::TYPE_DNS, MonitorMap::TYPE_CUSTOMER_IP]);

        self::assertSame('n1', $index->monitor(MonitorMap::TYPE_DNS, 'c1', 'zone.example')['publicId'] ?? null);
        self::assertSame('i1', $index->monitor(MonitorMap::TYPE_CUSTOMER_IP, 'c1', '2001:db8::1')['publicId'] ?? null, 'IPs normalized like the planner');
        self::assertSame('c1', RemoteIndex::customerIdOf(['customer' => ['publicId' => 'c1'], 'customerId' => 1]));
        self::assertSame('', RemoteIndex::customerIdOf(['customerId' => 1]), 'the numeric customerId is not a public id');
    }

    public function testDuplicateTargetsAndUnknownCustomerKeys(): void
    {
        $index = RemoteIndex::load(new Client((new FakeHttpClient())->on('GET', '/api/customers', 200, self::page([])), 'https://api.test', 'wsm_x'), self::guard(), []);
        $index->addMonitor(MonitorMap::TYPE_WEBSITE, ['publicId' => 'w1', 'url' => 'https://a.example', 'customerPublicId' => 'c1']);
        $index->addMonitor(MonitorMap::TYPE_WEBSITE, ['publicId' => 'w2', 'url' => 'https://www.a.example/x', 'customerPublicId' => 'c1']);
        self::assertSame('w1', $index->monitor(MonitorMap::TYPE_WEBSITE, 'c1', 'a.example')['publicId'] ?? null, 'first monitor for a target wins');
        $index->removeMonitor(MonitorMap::TYPE_WEBSITE, 'w1');
        self::assertSame('w2', $index->monitor(MonitorMap::TYPE_WEBSITE, 'c1', 'a.example')['publicId'] ?? null, 'removing one duplicate keeps the other');

        $index->addMonitor(MonitorMap::TYPE_WEBSITE, ['publicId' => 'w3', 'url' => 'https://b.example', 'customerId' => 7]);
        self::assertNotNull($index->monitorByPublicId(MonitorMap::TYPE_WEBSITE, 'w3'));
        self::assertNull($index->monitor(MonitorMap::TYPE_WEBSITE, '', 'b.example'), 'a row without customer public id is not matched by target');
        self::assertCount(2, $index->allMonitors(MonitorMap::TYPE_WEBSITE));

        $index->addMonitor(MonitorMap::TYPE_WEBSITE, ['publicId' => 'w2', 'url' => 'https://c.example', 'customerPublicId' => 'c1']);
        self::assertNull($index->monitor(MonitorMap::TYPE_WEBSITE, 'c1', 'a.example'), 're-adding a monitor drops its old target');
        self::assertSame('w2', $index->monitor(MonitorMap::TYPE_WEBSITE, 'c1', 'c.example')['publicId'] ?? null);
    }

    public function testCustomerLookupsAreNormalizedAndSkipCustomersOfOtherClients(): void
    {
        $index = RemoteIndex::load(new Client((new FakeHttpClient())->on('GET', '/api/customers', 200, self::page([
            ['publicId' => 'c1', 'name' => 'Shared GmbH', 'email' => 'shared@example.com', 'customFields' => ['whmcs_client_id' => '4']],
            ['publicId' => 'c2', 'name' => ' SHARED gmbh ', 'email' => 'SHARED@example.com ', 'customFields' => ['note' => 'x']],
            ['publicId' => 'c3', 'name' => 'Odd', 'customFields' => ['whmcs_client_id' => '4.5']],
        ])), 'https://api.test', 'wsm_x'), self::guard(), []);

        self::assertSame('c1', $index->customerByEmail(' Shared@Example.com')['publicId'] ?? null);
        self::assertSame('c2', $index->customerByEmail('shared@example.com', 5)['publicId'] ?? null, 'customer tagged for client 4 is not a candidate for client 5');
        self::assertSame('c1', $index->customerByName('shared gmbh', 4)['publicId'] ?? null);
        self::assertSame('c2', $index->customerByName('shared gmbh', 5)['publicId'] ?? null);
        self::assertNull(RemoteIndex::whmcsIdOf($index->customerByPublicId('c3') ?? []), 'non-integer ids are ignored');
        self::assertSame(4, RemoteIndex::whmcsIdOf(['customFields' => ['whmcs_client_id' => 4]]));

        $index->addCustomer(['publicId' => 'c1', 'name' => 'Renamed', 'email' => 'new@example.com', 'customFields' => ['whmcs_client_id' => '6']]);
        self::assertNull($index->customerByWhmcsId(4), 're-adding a customer drops its old keys');
        self::assertSame('c2', $index->customerByEmail('shared@example.com')['publicId'] ?? null);
        self::assertSame('c1', $index->customerByWhmcsId(6)['publicId'] ?? null);
    }

    public function testUnknownTypeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RemoteIndex::load(new Client((new FakeHttpClient())->on('GET', '/api/customers', 200, self::page([])), 'https://api.test', 'wsm_x'), self::guard(), ['icmp']);
    }
}
