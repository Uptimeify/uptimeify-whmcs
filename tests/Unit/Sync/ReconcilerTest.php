<?php

declare(strict_types=1);

namespace Uptimeify\Tests\Unit\Sync;

use Uptimeify\Tests\Fake\FakeHttpClient;
use Uptimeify\Tests\Support\DbTestCase;
use WHMCS\Module\Addon\Uptimeify\Api\Client;
use WHMCS\Module\Addon\Uptimeify\Http\HttpResponse;
use WHMCS\Module\Addon\Uptimeify\Settings;
use WHMCS\Module\Addon\Uptimeify\Store\CustomerMap;
use WHMCS\Module\Addon\Uptimeify\Store\DetachedMonitors;
use WHMCS\Module\Addon\Uptimeify\Store\MonitorMap;
use WHMCS\Module\Addon\Uptimeify\Store\Queue;
use WHMCS\Module\Addon\Uptimeify\Store\Schema;
use WHMCS\Module\Addon\Uptimeify\Store\StatusCache;
use WHMCS\Module\Addon\Uptimeify\Store\SyncLock;
use WHMCS\Module\Addon\Uptimeify\Sync\CustomerFilter;
use WHMCS\Module\Addon\Uptimeify\Sync\CustomerMirror;
use WHMCS\Module\Addon\Uptimeify\Sync\IncidentWindow;
use WHMCS\Module\Addon\Uptimeify\Sync\MonitorPlanner;
use WHMCS\Module\Addon\Uptimeify\Sync\PlannedMonitor;
use WHMCS\Module\Addon\Uptimeify\Sync\RateLimitGuard;
use WHMCS\Module\Addon\Uptimeify\Sync\Reconciler;
use WHMCS\Module\Addon\Uptimeify\Sync\ReconcileResult;
use WHMCS\Module\Addon\Uptimeify\Sync\RemoteIndex;
use WHMCS\Module\Addon\Uptimeify\Whmcs\ClientRepository;
use WHMCS\Module\Addon\Uptimeify\Whmcs\DomainRepository;
use WHMCS\Module\Addon\Uptimeify\Whmcs\HostingRepository;
use WHMCS\Module\Addon\Uptimeify\Whmcs\ServerRepository;

final class ReconcilerTest extends DbTestCase
{
    private FakeHttpClient $http;
    private Settings $settings;
    private int $client;
    /** @var list<int> */
    private array $sleeps = [];

    protected function setUp(): void
    {
        parent::setUp();
        Schema::install();
        $this->settings = new Settings();
        $this->settings->set('default_package_type', 'starter');
        $this->client = $this->insert('tblclients', ['companyname' => 'Acme', 'email' => 'acme@example.com']);
        $this->http = new FakeHttpClient();
    }

    // ---- fixtures -----------------------------------------------------------------

    /**
     * @param list<array<string,mixed>> $items
     * @return array<string,mixed>
     */
    private static function page(array $items): array
    {
        return ['items' => $items, 'total' => count($items), 'page' => 1, 'perPage' => 100];
    }

    /** @return array<string,mixed> */
    private static function customer(string $publicId, ?int $whmcsId, string $name = 'Acme', string $email = 'acme@example.com'): array
    {
        return ['id' => 1, 'publicId' => $publicId, 'name' => $name, 'email' => $email, 'customFields' => $whmcsId === null ? [] : ['whmcs_client_id' => (string) $whmcsId]];
    }

    /**
     * @param array<string,mixed> $more
     * @return array<string,mixed> a GET /api/websites item
     */
    private static function website(string $publicId, int $id, string $host, string $customer, string $status = 'active', array $more = []): array
    {
        return $more + ['id' => $id, 'publicId' => $publicId, 'customerId' => 1, 'customerPublicId' => $customer, 'name' => $host, 'url' => 'https://' . $host, 'status' => $status, 'isDown' => false];
    }

    /**
     * @param array<string,mixed> $more
     * @return array<string,mixed> a GET /api/customer-domains item
     */
    private static function domain(string $publicId, int $id, string $host, string $customer, array $more = []): array
    {
        return $more + ['id' => $id, 'publicId' => $publicId, 'customerId' => 1, 'customerPublicId' => $customer, 'label' => $host, 'domainName' => $host, 'status' => 'active', 'expiry' => null];
    }

    /**
     * A fresh fake with every list the reconcile may read. FakeHttpClient answers with the first matching route,
     * so a test that changes the remote state between runs calls this again.
     *
     * @param list<array<string,mixed>> $customers
     * @param list<array<string,mixed>> $websites
     * @param list<array<string,mixed>> $domains
     * @param list<array<string,mixed>> $dns
     * @param list<array<string,mixed>> $ips
     * @param list<array<string,mixed>> $tags
     * @param list<array<string,mixed>> $incidents
     */
    private function remote(array $customers, array $websites = [], array $domains = [], array $dns = [], array $ips = [], array $tags = [['id' => 1, 'publicId' => 't1', 'name' => 'whmcs', 'color' => 'teal', 'customerId' => null]], array $incidents = []): FakeHttpClient
    {
        return $this->http = (new FakeHttpClient())
            ->on('GET', '/api/organization', 200, ['id' => 7, 'publicId' => 'o1', 'name' => 'Acme Org'])
            ->on('GET', '/api/customers', 200, self::page($customers))
            ->on('GET', '/api/websites', 200, self::page($websites))
            ->on('GET', '/api/customer-domains', 200, self::page($domains))
            ->on('GET', '/api/dns-monitors', 200, self::page($dns))
            ->on('GET', '/api/customer-ips', 200, self::page($ips))
            ->on('GET', '/api/tags', 200, $tags)
            ->on('POST', '/api/monitor-tags', 200, ['id' => 1, 'tagId' => 1, 'monitorType' => 'website', 'monitorId' => 1])
            ->on('GET', '/api/incidents', 200, ['incidents' => $incidents, 'total' => count($incidents), 'limit' => 500, 'offset' => 0]);
    }

    /**
     * Wraps the current fake: $handler may answer a request itself (return a response) or pass it on (null).
     *
     * @param callable(string,string,array<mixed>):?HttpResponse $handler (method, path, decoded body)
     */
    private function intercept(callable $handler): void
    {
        $this->http = new class ($this->http, $handler) extends FakeHttpClient {
            /** @var callable(string,string,array<mixed>):?HttpResponse */
            private $handler;
            /** @var list<array{method:string,url:string,headers:array<string,string>,body:?string}> */
            private array $seen = [];

            public function __construct(private readonly FakeHttpClient $inner, callable $handler)
            {
                $this->handler = $handler;
            }

            public function on(string $method, string $pathOrRegex, int $status, array|string $body, array $headers = []): FakeHttpClient
            {
                $this->inner->on($method, $pathOrRegex, $status, $body, $headers);
                return $this;
            }

            public function send(string $method, string $url, array $headers, ?string $body, float $timeout): HttpResponse
            {
                $this->seen[] = ['method' => strtoupper($method), 'url' => $url, 'headers' => $headers, 'body' => $body];
                $decoded = $body === null ? [] : json_decode($body, true);
                $response = ($this->handler)(strtoupper($method), (string) parse_url($url, PHP_URL_PATH), is_array($decoded) ? $decoded : []);
                return $response ?? $this->inner->send($method, $url, $headers, $body, $timeout);
            }

            public function requests(): array
            {
                return $this->seen;
            }
        };
    }

    /** POST /api/websites answers with a new plain website row per call (w1, w2, … with numeric ids 101, 102, …). */
    private function createsWebsites(): void
    {
        $n = 0;
        $this->intercept(function (string $method, string $path, array $body) use (&$n): ?HttpResponse {
            if ($method !== 'POST' || $path !== '/api/websites') {
                return null;
            }
            $n++;
            return new HttpResponse(200, [], (string) json_encode(['id' => 100 + $n, 'publicId' => 'w' . $n, 'customerId' => 1, 'name' => $body['name'] ?? '', 'url' => $body['url'] ?? '', 'status' => 'active', 'customFields' => $body['customFields'] ?? []]));
        });
    }

    /** @param (callable():float)|null $clock */
    private function reconciler(?callable $clock = null): Reconciler
    {
        $api = new Client($this->http, 'https://api.test', 'wsm_x');
        $guard = new RateLimitGuard(function (int $seconds): void {
            $this->sleeps[] = $seconds;
        });
        $customerMap = new CustomerMap();
        return new Reconciler(
            $api,
            $this->settings,
            $customerMap,
            new MonitorMap(),
            new MonitorPlanner($this->settings, new CustomerFilter($this->settings), new ClientRepository(), new HostingRepository(), new DomainRepository(), new ServerRepository()),
            new CustomerMirror($api, $this->settings, $customerMap, $guard),
            new StatusCache(),
            new ClientRepository(),
            new HostingRepository(),
            new SyncLock(),
            $guard,
            $clock,
        );
    }

    /** @return list<array{method:string,url:string,headers:array<string,string>,body:?string}> */
    private function sent(string $method, string $path): array
    {
        $isRegex = str_starts_with($path, '#');
        return array_values(array_filter(
            $this->http->requests(),
            fn (array $r): bool => $r['method'] === $method
                && ($isRegex ? preg_match($path, (string) parse_url($r['url'], PHP_URL_PATH)) === 1 : parse_url($r['url'], PHP_URL_PATH) === $path),
        ));
    }

    /**
     * @param array{method:string,url:string,headers:array<string,string>,body:?string} $request
     * @return array<mixed>
     */
    private static function body(array $request): array
    {
        $decoded = json_decode((string) $request['body'], true);
        self::assertIsArray($decoded);
        return $decoded;
    }

    /** @param array<string,mixed> $row */
    private static function mapRow(array $row): void
    {
        (new MonitorMap())->upsert($row + ['state' => MonitorMap::STATE_ACTIVE, 'customer_public_id' => 'c1']);
    }

    /** @return array<string,mixed> */
    private static function row(string $kind, int $refId, string $type): array
    {
        $row = (new MonitorMap())->find($kind, $refId, $type);
        self::assertNotNull($row, "no map row {$kind}:{$refId}:{$type}");
        return $row;
    }

    private function mapClient(string $customer = 'c1', ?int $clientId = null): void
    {
        (new CustomerMap())->set($clientId ?? $this->client, $customer, null, 'manual');
    }

    // ---- §8.3 step 6: missing monitors, tags ------------------------------------------

    public function testCreatesCustomerAndMissingMonitorsAndTagsThemWithNumericIds(): void
    {
        $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'site.example', 'domainstatus' => 'Active']);
        $this->insert('tbldomains', ['userid' => $this->client, 'domain' => 'reg.example', 'status' => 'Active']);
        $this->remote([])
            ->on('POST', '/api/customers', 200, ['success' => true, 'customer' => ['id' => 1, 'publicId' => 'c1', 'packageType' => 'starter', 'customFields' => ['whmcs_client_id' => (string) $this->client]]])
            ->on('POST', '/api/websites', 200, ['id' => 11, 'publicId' => 'w1', 'customerId' => 1, 'name' => 'site.example', 'url' => 'https://site.example', 'status' => 'active'])
            ->on('POST', '/api/customers/c1/domains', 200, ['id' => 21, 'publicId' => 'd1', 'customerId' => 1, 'domainName' => 'reg.example', 'label' => 'reg.example', 'status' => 'active', 'deduplicated' => false]);

        $result = $this->reconciler()->run();

        self::assertSame(1, $result->customersCreated);
        self::assertSame(2, $result->monitorsCreated);
        self::assertSame(0, $result->errors);
        self::assertFalse($result->aborted);
        $website = self::row(MonitorMap::KIND_HOSTING, 1, MonitorMap::TYPE_WEBSITE);
        self::assertSame(['w1', 11, 'c1', 'site.example', MonitorMap::STATE_ACTIVE], [$website['monitor_public_id'], $website['monitor_numeric_id'], $website['customer_public_id'], $website['target'], $website['state']]);
        self::assertSame('d1', self::row(MonitorMap::KIND_DOMAIN, 1, MonitorMap::TYPE_CUSTOMER_DOMAIN)['monitor_public_id']);

        self::assertSame(
            ['customerId' => 'c1', 'name' => 'site.example', 'url' => 'https://site.example', 'monitoringType' => 'combined', 'checkInterval' => 5, 'customFields' => ['whmcs_hosting_id' => '1']],
            self::body($this->sent('POST', '/api/websites')[0]),
        );
        self::assertSame('reg.example', self::body($this->sent('POST', '/api/customers/c1/domains')[0])['domainName']);
        $tags = array_map(fn (array $r): array => self::body($r), $this->sent('POST', '/api/monitor-tags'));
        self::assertSame([['monitorType' => 'website', 'monitorId' => 11, 'tagId' => 1], ['monitorType' => 'customer_domain', 'monitorId' => 21, 'tagId' => 1]], $tags);
        self::assertSame([], $this->sent('POST', '/api/tags'), 'the existing whmcs tag is reused');

        $last = (new StatusCache())->value('last_reconcile');
        self::assertSame($result->summary(), $last['summary'] ?? null);
        self::assertStringContainsString('customers=1 created=2', $result->summary());
        $activity = array_column($GLOBALS['uptimeify_test']['activity'], 0);
        self::assertContains('Uptimeify: Reconcile finished: ' . $result->summary(), $activity);
    }

    public function testCreatesTheWhmcsTagOnceWhenOnlyACustomerScopedOneExists(): void
    {
        $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'a.example', 'domainstatus' => 'Active']);
        $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'b.example', 'domainstatus' => 'Active']);
        $this->mapClient();
        $this->remote([self::customer('c1', $this->client)], tags: [['id' => 3, 'publicId' => 't3', 'name' => 'whmcs', 'color' => 'red', 'customerId' => 7]])
            ->on('POST', '/api/tags', 200, ['id' => 9, 'publicId' => 't9', 'name' => 'whmcs', 'color' => 'teal', 'customerId' => null]);
        $this->createsWebsites();

        $result = $this->reconciler()->run();

        self::assertSame(2, $result->monitorsCreated);
        self::assertCount(1, $this->sent('POST', '/api/tags'));
        self::assertSame(['name' => 'whmcs', 'color' => 'teal'], self::body($this->sent('POST', '/api/tags')[0]));
        self::assertSame([9, 9], array_map(fn (array $r): mixed => self::body($r)['tagId'], $this->sent('POST', '/api/monitor-tags')));
    }

    public function testTagFailuresNeverFailTheMonitor(): void
    {
        $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'a.example', 'domainstatus' => 'Active']);
        $this->mapClient();
        $this->remote([self::customer('c1', $this->client)]);
        $this->createsWebsites();
        $this->intercept(fn (string $method, string $path): ?HttpResponse => $method === 'GET' && $path === '/api/tags'
            ? new HttpResponse(500, [], (string) json_encode(['statusCode' => 500, 'statusMessage' => 'boom', 'data' => ['code' => 'generic']]))
            : null);

        $result = $this->reconciler()->run();

        self::assertSame([1, 0], [$result->monitorsCreated, $result->errors]);
        self::assertSame(MonitorMap::STATE_ACTIVE, self::row('hosting', 1, 'website')['state']);
        self::assertSame([], $this->sent('POST', '/api/monitor-tags'));
        self::assertNotSame([], array_filter(array_column($GLOBALS['uptimeify_test']['activity'], 0), fn (string $m): bool => str_contains($m, 'whmcs tag')));

        $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'b.example', 'domainstatus' => 'Active']);
        $this->remote([self::customer('c1', $this->client)], [self::website('w1', 101, 'a.example', 'c1')]);
        $this->createsWebsites();
        $this->intercept(fn (string $method, string $path): ?HttpResponse => $method === 'POST' && $path === '/api/monitor-tags'
            ? new HttpResponse(422, [], (string) json_encode(['statusCode' => 422, 'statusMessage' => 'too many tags', 'data' => ['code' => 'tagLimitReached']]))
            : null);

        $result = $this->reconciler()->run();

        self::assertSame([1, 0], [$result->monitorsCreated, $result->errors]);
        self::assertCount(1, $this->sent('POST', '/api/monitor-tags'));
        self::assertSame(MonitorMap::STATE_ACTIVE, self::row('hosting', 2, 'website')['state']);
        self::assertNotSame([], array_filter(array_column($GLOBALS['uptimeify_test']['activity'], 0), fn (string $m): bool => str_contains($m, 'tagLimitReached')));
    }

    public function testResumeNotFoundOrphansAndResumeQuotaSkipsTheCustomer(): void
    {
        foreach (['gone', 'limit', 'skipped'] as $i => $name) {
            $h = $this->insert('tblhosting', ['userid' => $this->client, 'domain' => $name . '.example', 'domainstatus' => 'Active']);
            self::mapRow(['kind' => 'hosting', 'whmcs_ref_id' => $h, 'monitor_type' => 'website', 'monitor_public_id' => 'w-' . $name, 'target' => $name . '.example', 'state' => MonitorMap::STATE_PAUSED]);
        }
        $this->mapClient();
        $this->remote([self::customer('c1', $this->client)], [
            self::website('w-gone', 1, 'gone.example', 'c1', 'inactive'),
            self::website('w-limit', 2, 'limit.example', 'c1', 'inactive'),
            self::website('w-skipped', 3, 'skipped.example', 'c1', 'inactive'),
        ])
            ->on('PATCH', '/api/websites/w-gone', 404, ['statusCode' => 404, 'statusMessage' => 'Website not found', 'data' => ['code' => 'websiteNotFound']])
            ->on('PATCH', '/api/websites/w-limit', 403, ['statusCode' => 403, 'statusMessage' => 'limit', 'data' => ['code' => 'activeMonitorLimitReached']]);

        $result = $this->reconciler()->run();

        self::assertSame(MonitorMap::STATE_ORPHANED, self::row('hosting', 1, 'website')['state']);
        foreach ([2, 3] as $ref) {
            $row = self::row('hosting', $ref, 'website');
            self::assertSame([MonitorMap::STATE_ERROR, 'activeMonitorLimitReached', 'w-' . ($ref === 2 ? 'limit' : 'skipped')], [$row['state'], $row['last_error'], $row['monitor_public_id']]);
        }
        self::assertSame([], $this->sent('PATCH', '/api/websites/w-skipped'), 'after the quota error the customer is skipped without a request');
        self::assertSame([1, 2, 0, 0], [$result->orphaned, $result->quotaSkipped, $result->resumed, $result->errors]);
    }

    public function testConflictIsLoggedOncePerClientPerRun(): void
    {
        $this->settings->setBool('auto_create_customers', false);
        $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'a.example', 'domainstatus' => 'Active']);
        $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'b.example', 'domainstatus' => 'Active']);
        $this->mapClient('cheld', 99);
        $this->remote([self::customer('cheld', $this->client)]);

        $this->reconciler()->run();

        $conflicts = array_filter(array_column($GLOBALS['uptimeify_test']['activity'], 0), fn (string $m): bool => str_contains($m, 'Conflict'));
        self::assertCount(1, $conflicts);
        self::assertSame([], $this->sent('POST', '#.#'));
    }

    // ---- §8.3 step 5: adoption --------------------------------------------------------

    public function testAdoptsMatchingRemoteMonitorsInsteadOfCreating(): void
    {
        $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'www.site.example', 'domainstatus' => 'Active']);
        $this->insert('tbldomains', ['userid' => $this->client, 'domain' => 'Reg.Example', 'status' => 'Active']);
        $this->remote(
            [self::customer('c1', $this->client)],
            [self::website('w-old', 9, 'site.example/', 'c1')],
            [self::domain('d-old', 8, 'reg.example', 'c1')],
        );

        $result = $this->reconciler()->run();

        self::assertSame(2, $result->adopted);
        self::assertSame(0, $result->monitorsCreated);
        self::assertSame('w-old', self::row(MonitorMap::KIND_HOSTING, 1, MonitorMap::TYPE_WEBSITE)['monitor_public_id']);
        self::assertSame(9, self::row(MonitorMap::KIND_HOSTING, 1, MonitorMap::TYPE_WEBSITE)['monitor_numeric_id']);
        self::assertSame('d-old', self::row(MonitorMap::KIND_DOMAIN, 1, MonitorMap::TYPE_CUSTOMER_DOMAIN)['monitor_public_id']);
        self::assertSame([], $this->sent('POST', '/api/websites'));
        self::assertSame([], $this->sent('POST', '#^/api/customers/c1/domains$#'));
        self::assertSame([], $this->sent('PATCH', '#^/api/websites/#'), 'an active monitor is not touched');
    }

    public function testHostingIdOnlyPicksAmongWebsitesWithThePlannedTarget(): void
    {
        $edited = $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'site.example', 'domainstatus' => 'Active']);
        $twin = $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'other.example', 'domainstatus' => 'Active']);
        $this->mapClient();
        $this->remote([self::customer('c1', $this->client)], [
            self::website('w-edited', 7, 'edited.example', 'c1', 'active', ['customFields' => ['whmcs_hosting_id' => (string) $edited]]),
            self::website('w-first', 8, 'other.example', 'c1', 'active', ['customFields' => []]),
            self::website('w-tagged', 9, 'other.example', 'c1', 'active', ['customFields' => ['whmcs_hosting_id' => (string) $twin]]),
        ]);
        $this->createsWebsites();

        $result = $this->reconciler()->run();

        self::assertSame(['https://site.example'], array_map(fn (array $r): mixed => self::body($r)['url'], $this->sent('POST', '/api/websites')), 'a website tagged for the hosting but monitoring another host is not adopted');
        self::assertSame('w1', self::row('hosting', $edited, 'website')['monitor_public_id']);
        self::assertSame('w-tagged', self::row('hosting', $twin, 'website')['monitor_public_id'], 'among same-target websites the one tagged with the hosting id wins');
        self::assertSame([1, 1], [$result->monitorsCreated, $result->adopted]);
    }

    public function testMissingDefaultPackageMarksRowsWithoutAbort(): void
    {
        $this->settings->set('default_package_type', null);
        $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'a.example', 'domainstatus' => 'Active']);
        $this->remote([]);

        $result = $this->reconciler()->run();

        self::assertSame('noDefaultPackage', self::row('hosting', 1, 'website')['last_error']);
        self::assertSame(1, $result->errors);
        self::assertFalse($result->aborted);
    }

    public function testAdoptionIgnoresSameTargetOfAnotherCustomer(): void
    {
        $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'site.example', 'domainstatus' => 'Active']);
        $this->mapClient();
        $this->remote([self::customer('c1', $this->client), self::customer('c2', null, 'Other', 'o@example.com')], [self::website('w-foreign', 9, 'site.example', 'c2')]);
        $this->createsWebsites();

        $result = $this->reconciler()->run();

        self::assertSame(0, $result->adopted);
        self::assertSame(1, $result->monitorsCreated);
        self::assertSame('w1', self::row(MonitorMap::KIND_HOSTING, 1, MonitorMap::TYPE_WEBSITE)['monitor_public_id']);
    }

    public function testSameTargetPlannedTwiceSharesOneRemoteMonitor(): void
    {
        $this->settings->setBool('sync_hosting_domain_expiry', true);
        $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'site.example', 'domainstatus' => 'Active']);
        $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'WWW.site.example', 'domainstatus' => 'Active']);
        $this->insert('tbldomains', ['userid' => $this->client, 'domain' => 'site.example', 'status' => 'Active']);
        $this->mapClient();
        $this->remote([self::customer('c1', $this->client)])
            ->on('POST', '/api/customers/c1/domains', 200, ['id' => 21, 'publicId' => 'd1', 'customerId' => 1, 'domainName' => 'site.example', 'label' => 'site.example', 'status' => 'active', 'deduplicated' => false]);
        $this->createsWebsites();

        $result = $this->reconciler()->run();

        self::assertCount(1, $this->sent('POST', '/api/websites'));
        self::assertCount(1, $this->sent('POST', '/api/customers/c1/domains'));
        self::assertSame(2, $result->monitorsCreated);
        self::assertSame(3, $result->adopted);
        self::assertSame('w1', self::row(MonitorMap::KIND_HOSTING, 2, MonitorMap::TYPE_WEBSITE)['monitor_public_id']);
        self::assertSame('d1', self::row(MonitorMap::KIND_HOSTING, 2, MonitorMap::TYPE_CUSTOMER_DOMAIN)['monitor_public_id']);
        self::assertSame('d1', self::row(MonitorMap::KIND_DOMAIN, 1, MonitorMap::TYPE_CUSTOMER_DOMAIN)['monitor_public_id']);
    }

    // ---- §8.3 step 7: quota, invalidUrl, failures ---------------------------------------

    public function testQuotaSkipsOnlyThatCustomer(): void
    {
        $other = $this->insert('tblclients', ['companyname' => 'Beta', 'email' => 'beta@example.com']);
        $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'a.example', 'domainstatus' => 'Active']);
        $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'b.example', 'domainstatus' => 'Active']);
        $this->insert('tblhosting', ['userid' => $other, 'domain' => 'c.example', 'domainstatus' => 'Active']);
        $this->remote([self::customer('c1', $this->client), self::customer('c2', $other, 'Beta', 'beta@example.com')]);
        $this->createsWebsites();
        $this->intercept(function (string $method, string $path, array $body): ?HttpResponse {
            if ($method === 'POST' && $path === '/api/websites' && ($body['customerId'] ?? null) === 'c1') {
                return new HttpResponse(403, [], (string) json_encode(['statusCode' => 403, 'statusMessage' => 'limit', 'data' => ['code' => 'activeMonitorLimitReached']]));
            }
            return null;
        });

        $result = $this->reconciler()->run();

        self::assertSame(1, $result->monitorsCreated, 'Beta still gets its monitor');
        self::assertSame(2, $result->quotaSkipped);
        self::assertSame(0, $result->errors);
        self::assertFalse($result->aborted);
        self::assertCount(2, $this->sent('POST', '/api/websites'), 'after the first quota error Acme is skipped without a request');
        foreach ([1, 2] as $hosting) {
            $row = self::row(MonitorMap::KIND_HOSTING, $hosting, MonitorMap::TYPE_WEBSITE);
            self::assertSame([MonitorMap::STATE_ERROR, 'activeMonitorLimitReached', 'c1'], [$row['state'], $row['last_error'], $row['customer_public_id']]);
        }
        self::assertSame(MonitorMap::STATE_ACTIVE, self::row(MonitorMap::KIND_HOSTING, 3, MonitorMap::TYPE_WEBSITE)['state']);
    }

    /**
     * Round 2 review I1: the API's URL check resolves the host, so a domain whose DNS is not set up yet
     * gets the same 400 `invalidUrl` as a private target. The daily reconcile tries such a row again (once per run), a
     * budgeted admin run does not.
     */
    private function refusesUrl(string $url): void
    {
        $this->intercept(fn (string $method, string $path, array $body): ?HttpResponse => in_array($method, ['POST', 'PATCH'], true) && ($body['url'] ?? '') === $url
            ? new HttpResponse(400, [], (string) json_encode(['statusCode' => 400, 'statusMessage' => 'Monitor URL could not be resolved', 'data' => ['code' => 'invalidUrl']]))
            : null);
    }

    public function testInvalidUrlMarksErrorWithoutAbortAndTheNextDailyRunRetriesItOnce(): void
    {
        $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'new-shop.example', 'domainstatus' => 'Active']);
        $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'ok.example', 'domainstatus' => 'Active']);
        $this->mapClient();
        $this->remote([self::customer('c1', $this->client)]);
        $this->createsWebsites();
        $this->refusesUrl('https://new-shop.example');

        $result = $this->reconciler()->run();

        self::assertSame(1, $result->errors);
        self::assertSame(1, $result->monitorsCreated, 'the run continues');
        self::assertFalse($result->aborted);
        $row = self::row(MonitorMap::KIND_HOSTING, 1, MonitorMap::TYPE_WEBSITE);
        self::assertSame([MonitorMap::STATE_ERROR, 'invalidUrl', null], [$row['state'], $row['last_error'], $row['monitor_public_id']]);
        self::assertNotSame([], array_filter($result->messages, fn (string $m): bool => str_contains($m, 'invalidUrl')));

        // "Reconcile now" (budgeted admin run): not sent again from an admin request.
        $this->remote([self::customer('c1', $this->client)], [self::website('w1', 101, 'ok.example', 'c1')]);
        $result = $this->reconciler()->run(20.0);
        self::assertSame([], $this->sent('POST', '/api/websites'), 'a budgeted run does not retry a refused target');
        self::assertSame([0, []], [$result->errors, $result->rowErrors]);
        self::assertSame('invalidUrl', self::row(MonitorMap::KIND_HOSTING, 1, MonitorMap::TYPE_WEBSITE)['last_error']);

        // The daily reconcile: tried again once; still refused.
        $this->remote([self::customer('c1', $this->client)], [self::website('w1', 101, 'ok.example', 'c1')]);
        $this->createsWebsites();
        $this->refusesUrl('https://new-shop.example');
        $result = $this->reconciler()->run();
        self::assertCount(1, $this->sent('POST', '/api/websites'), 'retried once per daily run');
        self::assertSame(['hosting:1:website' => 'invalidUrl'], $result->rowErrors);

        // The domain resolves now: the next daily run creates the monitor and clears the error.
        $this->remote([self::customer('c1', $this->client)], [self::website('w1', 101, 'ok.example', 'c1')])
            ->on('POST', '/api/websites', 200, ['id' => 102, 'publicId' => 'w2', 'customerId' => 1, 'name' => 'new-shop.example', 'url' => 'https://new-shop.example', 'status' => 'active']);
        $result = $this->reconciler()->run();
        self::assertSame([1, 0], [$result->monitorsCreated, $result->errors]);
        $row = self::row(MonitorMap::KIND_HOSTING, 1, MonitorMap::TYPE_WEBSITE);
        self::assertSame([MonitorMap::STATE_ACTIVE, null, 'w2'], [$row['state'], $row['last_error'], $row['monitor_public_id']]);
    }

    public function testARefusedTargetIsNotSentTwiceAgainstTheSameLoad(): void
    {
        $h = $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'new-shop.example', 'domainstatus' => 'Active']);
        $this->mapClient();
        $this->remote([self::customer('c1', $this->client)]);
        $this->refusesUrl('https://new-shop.example');
        $reconciler = $this->reconciler();
        $index = RemoteIndex::load(new Client($this->http, 'https://api.test', 'wsm_x'), new RateLimitGuard(fn (int $s) => null), Reconciler::ALL_TYPES);
        $rows = fn (): array => (new MonitorMap())->forKindRef('hosting', $h);
        $belongs = fn (): bool => true;

        $first = new ReconcileResult();
        $reconciler->reconcileObject([$this->client], $belongs, $rows(), $index, $first);
        $second = new ReconcileResult();
        $reconciler->reconcileObject([$this->client], $belongs, $rows(), $index, $second);

        self::assertCount(1, $this->sent('POST', '/api/websites'), 'one attempt per load (a worker batch shares the index)');
        self::assertSame(['hosting:' . $h . ':website' => 'invalidUrl'], $first->rowErrors);
        self::assertSame([[], 0], [$second->rowErrors, $second->errors], 'the unchanged error it did not try again is not the second call\'s failure');
    }

    public function testAKnownQuotaErrorThatWasNotTriedAgainIsNotThisCallsError(): void
    {
        $h1 = $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'one.example', 'domainstatus' => 'Active']);
        $h2 = $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'two.example', 'domainstatus' => 'Active']);
        self::mapRow(['kind' => 'hosting', 'whmcs_ref_id' => $h2, 'monitor_type' => 'website', 'target' => 'two.example', 'state' => MonitorMap::STATE_ERROR, 'last_error' => 'activeMonitorLimitReached']);
        $this->mapClient();
        $this->remote([self::customer('c1', $this->client)])
            ->on('POST', '/api/websites', 403, ['statusCode' => 403, 'statusMessage' => 'Active monitor limit reached', 'data' => ['code' => 'activeMonitorLimitReached']]);
        $index = RemoteIndex::load(new Client($this->http, 'https://api.test', 'wsm_x'), new RateLimitGuard(fn (int $s) => null), Reconciler::ALL_TYPES);
        $reconciler = $this->reconciler();

        $first = new ReconcileResult();
        $reconciler->reconcileObject([$this->client], fn (PlannedMonitor $p): bool => $p->refId === $h1, (new MonitorMap())->forKindRef('hosting', $h1), $index, $first);
        $second = new ReconcileResult();
        $reconciler->reconcileObject([$this->client], fn (PlannedMonitor $p): bool => $p->refId === $h2, (new MonitorMap())->forKindRef('hosting', $h2), $index, $second);

        self::assertCount(1, $this->sent('POST', '/api/websites'));
        self::assertSame(['hosting:' . $h1 . ':website' => 'activeMonitorLimitReached'], $first->rowErrors);
        self::assertSame([], $second->rowErrors, 'hosting 2 already had that quota error and was not tried again');
        self::assertSame([MonitorMap::STATE_ERROR, 'activeMonitorLimitReached'], [self::row('hosting', $h2, 'website')['state'], self::row('hosting', $h2, 'website')['last_error']]);
    }

    public function testFailedCustomerResolutionIsTriedOncePerClient(): void
    {
        $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'a.example', 'domainstatus' => 'Active']);
        $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'b.example', 'domainstatus' => 'Active']);
        $this->remote([])->on('POST', '/api/customers', 403, ['statusCode' => 403, 'statusMessage' => 'Forbidden', 'data' => ['code' => 'billingSetupRequired']]);

        $result = $this->reconciler()->run();

        self::assertCount(1, $this->sent('POST', '/api/customers'));
        self::assertSame(1, $result->errors);
        self::assertFalse($result->aborted);
        self::assertSame('billingSetupRequired', self::row(MonitorMap::KIND_HOSTING, 2, MonitorMap::TYPE_WEBSITE)['last_error']);
    }

    public function testCustomerHeldByTwoClientsGetsNoMonitors(): void
    {
        $other = $this->insert('tblclients', ['companyname' => 'Beta', 'email' => 'beta@example.com']);
        $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'a.example', 'domainstatus' => 'Active']);
        $this->mapClient('c1');
        $this->mapClient('c1', $other);
        $this->remote([self::customer('c1', null)]);

        $result = $this->reconciler()->run();

        self::assertSame([], $this->sent('POST', '/api/websites'));
        self::assertSame('customerConflict', self::row(MonitorMap::KIND_HOSTING, 1, MonitorMap::TYPE_WEBSITE)['last_error']);
        self::assertSame(1, $result->errors);
    }

    // ---- §8.5 state transitions -------------------------------------------------------

    public function testActiveHostingAgainResumesPausedWebsite(): void
    {
        $h = $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'site.example', 'domainstatus' => 'Active']);
        self::mapRow(['kind' => 'hosting', 'whmcs_ref_id' => $h, 'monitor_type' => 'website', 'monitor_public_id' => 'w1', 'monitor_numeric_id' => 11, 'target' => 'site.example', 'state' => MonitorMap::STATE_PAUSED]);
        $this->mapClient();
        $this->remote([self::customer('c1', $this->client)], [self::website('w1', 11, 'site.example', 'c1', 'inactive')])
            ->on('PATCH', '/api/websites/w1', 200, self::website('w1', 11, 'site.example', 'c1'));

        $result = $this->reconciler()->run();

        self::assertSame(1, $result->resumed);
        self::assertSame(['status' => 'active'], self::body($this->sent('PATCH', '/api/websites/w1')[0]));
        self::assertSame(MonitorMap::STATE_ACTIVE, self::row('hosting', $h, 'website')['state']);
        self::assertSame([], $this->sent('POST', '/api/websites'));
    }

    public function testSuspendedHostingPausesWebsiteAndKeepsDomainMonitor(): void
    {
        $this->settings->setBool('sync_hosting_domain_expiry', true);
        $h = $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'sus.example', 'domainstatus' => 'Suspended']);
        self::mapRow(['kind' => 'hosting', 'whmcs_ref_id' => $h, 'monitor_type' => 'website', 'monitor_public_id' => 'ws', 'target' => 'sus.example']);
        self::mapRow(['kind' => 'hosting', 'whmcs_ref_id' => $h, 'monitor_type' => 'customer_domain', 'monitor_public_id' => 'ds', 'target' => 'sus.example']);
        $this->mapClient();
        $this->remote([self::customer('c1', $this->client)], [self::website('ws', 1, 'sus.example', 'c1')], [self::domain('ds', 2, 'sus.example', 'c1')])
            ->on('PATCH', '/api/websites/ws', 200, self::website('ws', 1, 'sus.example', 'c1', 'inactive'));

        $result = $this->reconciler()->run();

        self::assertSame(1, $result->paused);
        self::assertSame(['status' => 'inactive'], self::body($this->sent('PATCH', '/api/websites/ws')[0]));
        self::assertSame(MonitorMap::STATE_PAUSED, self::row('hosting', $h, 'website')['state']);
        self::assertSame(MonitorMap::STATE_ACTIVE, self::row('hosting', $h, 'customer_domain')['state'], 'domain monitor stays');
        self::assertSame([], $this->sent('DELETE', '#.#'));

        $this->remote([self::customer('c1', $this->client)], [self::website('ws', 1, 'sus.example', 'c1', 'inactive')], [self::domain('ds', 2, 'sus.example', 'c1')]);
        $result = $this->reconciler()->run();
        self::assertSame(0, $result->paused);
        self::assertSame([], $this->sent('PATCH', '#.#'), 'an already paused monitor is not patched again');
    }

    public function testTerminatedOrDeletedHostingFollowsOnTerminateAndDeletesDomainMonitor(): void
    {
        $this->settings->setBool('sync_hosting_domain_expiry', true);
        $term = $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'term.example', 'domainstatus' => 'Terminated']);
        $cancelled = $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'cancel.example', 'domainstatus' => 'Cancelled']);
        $deleted = 99;
        self::mapRow(['origin' => 'created', 'kind' => 'hosting', 'whmcs_ref_id' => $term, 'monitor_type' => 'website', 'monitor_public_id' => 'wt', 'target' => 'term.example']);
        self::mapRow(['origin' => 'created', 'kind' => 'hosting', 'whmcs_ref_id' => $term, 'monitor_type' => 'customer_domain', 'monitor_public_id' => 'dt', 'target' => 'term.example']);
        self::mapRow(['origin' => 'created', 'kind' => 'hosting', 'whmcs_ref_id' => $cancelled, 'monitor_type' => 'website', 'monitor_public_id' => 'wc', 'target' => 'cancel.example']);
        self::mapRow(['origin' => 'created', 'kind' => 'hosting', 'whmcs_ref_id' => $deleted, 'monitor_type' => 'website', 'monitor_public_id' => 'wd', 'target' => 'deleted.example']);
        $this->mapClient();
        $websites = fn (string $status): array => [self::website('wt', 1, 'term.example', 'c1', $status), self::website('wc', 2, 'cancel.example', 'c1', $status), self::website('wd', 3, 'deleted.example', 'c1', $status)];
        $this->remote([self::customer('c1', $this->client)], $websites('active'), [self::domain('dt', 4, 'term.example', 'c1')])
            ->on('PATCH', '#^/api/websites/w[tcd]$#', 200, self::website('wt', 1, 'term.example', 'c1', 'inactive'))
            ->on('DELETE', '/api/customer-domains/dt', 200, ['success' => true]);

        $result = $this->reconciler()->run();

        self::assertSame(3, $result->paused, 'default on_terminate=pause');
        self::assertSame(1, $result->deleted);
        self::assertCount(1, $this->sent('DELETE', '/api/customer-domains/dt'));
        foreach ([$term, $cancelled, $deleted] as $ref) {
            self::assertSame(MonitorMap::STATE_PAUSED, self::row('hosting', $ref, 'website')['state']);
        }
        self::assertNull((new MonitorMap())->find('hosting', $term, 'customer_domain'));

        $this->settings->set('on_terminate', 'delete');
        $this->remote([self::customer('c1', $this->client)], $websites('inactive'))
            ->on('DELETE', '#^/api/websites/w[tcd]$#', 200, ['success' => true]);
        $result = $this->reconciler()->run();
        self::assertSame(3, $result->deleted);
        self::assertCount(3, $this->sent('DELETE', '#^/api/websites/#'));
        self::assertSame([], (new MonitorMap())->all());
    }

    public function testActiveDomainCreatesAndEndedDomainDeletesDomainMonitors(): void
    {
        $this->settings->setBool('sync_domain_dns', true);
        $this->insert('tbldomains', ['userid' => $this->client, 'domain' => 'new.example', 'status' => 'Active']);
        $expired = $this->insert('tbldomains', ['userid' => $this->client, 'domain' => 'expired.example', 'status' => 'Expired']);
        $away = $this->insert('tbldomains', ['userid' => $this->client, 'domain' => 'away.example', 'status' => 'Transferred Away']);
        $grace = $this->insert('tbldomains', ['userid' => $this->client, 'domain' => 'grace.example', 'status' => 'Grace']);
        self::mapRow(['origin' => 'created', 'kind' => 'domain', 'whmcs_ref_id' => $expired, 'monitor_type' => 'customer_domain', 'monitor_public_id' => 'de', 'target' => 'expired.example']);
        self::mapRow(['origin' => 'created', 'kind' => 'domain', 'whmcs_ref_id' => $expired, 'monitor_type' => 'dns', 'monitor_public_id' => 'ne', 'target' => 'expired.example']);
        self::mapRow(['origin' => 'created', 'kind' => 'domain', 'whmcs_ref_id' => $away, 'monitor_type' => 'customer_domain', 'monitor_public_id' => 'da', 'target' => 'away.example']);
        self::mapRow(['origin' => 'created', 'kind' => 'domain', 'whmcs_ref_id' => 77, 'monitor_type' => 'customer_domain', 'monitor_public_id' => 'dx', 'target' => 'deleted.example']);
        self::mapRow(['origin' => 'created', 'kind' => 'domain', 'whmcs_ref_id' => $grace, 'monitor_type' => 'customer_domain', 'monitor_public_id' => 'dg', 'target' => 'grace.example']);
        $this->mapClient();
        $this->remote(
            [self::customer('c1', $this->client)],
            [],
            [self::domain('de', 1, 'expired.example', 'c1'), self::domain('da', 2, 'away.example', 'c1'), self::domain('dx', 3, 'deleted.example', 'c1'), self::domain('dg', 4, 'grace.example', 'c1')],
            [['id' => 5, 'publicId' => 'ne', 'customerId' => 1, 'customerPublicId' => 'c1', 'name' => 'expired.example', 'hostname' => 'expired.example', 'status' => 'active']],
        )
            ->on('POST', '/api/customers/c1/domains', 200, ['id' => 21, 'publicId' => 'dn', 'customerId' => 1, 'domainName' => 'new.example', 'label' => 'new.example', 'status' => 'active', 'deduplicated' => false])
            ->on('POST', '/api/dns-monitors', 200, ['id' => 31, 'publicId' => 'nn', 'customerId' => 1, 'name' => 'new.example', 'hostname' => 'new.example', 'status' => 'active'])
            ->on('DELETE', '#^/api/customer-domains/d[eax]$#', 200, ['success' => true])
            ->on('DELETE', '/api/dns-monitors/ne', 200, ['success' => true]);

        $result = $this->reconciler()->run();

        self::assertSame(2, $result->monitorsCreated);
        self::assertSame(
            ['customerId' => 'c1', 'name' => 'new.example', 'hostname' => 'new.example', 'dnsConfig' => MonitorPlanner::DNS_CONFIG],
            self::body($this->sent('POST', '/api/dns-monitors')[0]),
        );
        self::assertSame(4, $result->deleted);
        self::assertCount(3, $this->sent('DELETE', '#^/api/customer-domains/#'));
        self::assertCount(1, $this->sent('DELETE', '/api/dns-monitors/ne'));
        self::assertSame(['dg', 'dn', 'nn'], self::sorted(array_column((new MonitorMap())->all(), 'monitor_public_id')));
        self::assertSame(MonitorMap::STATE_ACTIVE, self::row('domain', $grace, 'customer_domain')['state'], 'a domain in grace keeps its monitor');
    }

    public function testClosedClientPausesWebsitesKeepsOtherMonitorsAndGetsNothingNew(): void
    {
        $this->settings->setBool('sync_server_ips', true);
        $this->closeClient();
        $h = $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'site.example', 'domainstatus' => 'Active', 'server' => 1]);
        $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'unmonitored.example', 'domainstatus' => 'Active', 'server' => 1]);
        $d = $this->insert('tbldomains', ['userid' => $this->client, 'domain' => 'reg.example', 'status' => 'Active']);
        $this->insert('tblservers', ['name' => 'web1', 'ipaddress' => '203.0.113.10']);
        $ipRef = MonitorPlanner::serverIpRefId(1, $this->client);
        self::mapRow(['kind' => 'hosting', 'whmcs_ref_id' => $h, 'monitor_type' => 'website', 'monitor_public_id' => 'w1', 'target' => 'site.example']);
        self::mapRow(['kind' => 'domain', 'whmcs_ref_id' => $d, 'monitor_type' => 'customer_domain', 'monitor_public_id' => 'd1', 'target' => 'reg.example']);
        self::mapRow(['kind' => 'server_ip', 'whmcs_ref_id' => $ipRef, 'monitor_type' => 'customer_ip', 'monitor_public_id' => 'i1', 'target' => '203.0.113.10']);
        $this->mapClient();
        $this->remote(
            [self::customer('c1', $this->client)],
            [self::website('w1', 1, 'site.example', 'c1')],
            [self::domain('d1', 2, 'reg.example', 'c1')],
            [],
            [['id' => 3, 'publicId' => 'i1', 'customerId' => 1, 'customer' => ['publicId' => 'c1'], 'ipAddress' => '203.0.113.10', 'label' => 'web1', 'status' => 'active']],
        )->on('PATCH', '/api/websites/w1', 200, self::website('w1', 1, 'site.example', 'c1', 'inactive'));

        $result = $this->reconciler()->run();

        self::assertSame(1, $result->paused);
        self::assertSame(MonitorMap::STATE_PAUSED, self::row('hosting', $h, 'website')['state']);
        self::assertSame(MonitorMap::STATE_ACTIVE, self::row('domain', $d, 'customer_domain')['state']);
        self::assertSame(MonitorMap::STATE_ACTIVE, self::row('server_ip', $ipRef, 'customer_ip')['state']);
        self::assertSame([], $this->sent('DELETE', '#.#'));
        self::assertSame([], $this->sent('POST', '#^/api/(websites|customers)#'), 'a closed client gets no new monitors');
        self::assertSame(0, $result->resumed);
    }

    public function testDeletedClientIsTreatedLikeTerminated(): void
    {
        $gone = 42;
        $ipRef = MonitorPlanner::serverIpRefId(1, $gone);
        self::mapRow(['origin' => 'created', 'kind' => 'hosting', 'whmcs_ref_id' => 5, 'monitor_type' => 'website', 'monitor_public_id' => 'w1', 'target' => 'site.example']);
        self::mapRow(['origin' => 'created', 'kind' => 'domain', 'whmcs_ref_id' => 6, 'monitor_type' => 'customer_domain', 'monitor_public_id' => 'd1', 'target' => 'reg.example']);
        self::mapRow(['origin' => 'created', 'kind' => 'server_ip', 'whmcs_ref_id' => $ipRef, 'monitor_type' => 'customer_ip', 'monitor_public_id' => 'i1', 'target' => '203.0.113.10']);
        $this->mapClient('c1', $gone);
        $this->remote(
            [self::customer('c1', $gone)],
            [self::website('w1', 1, 'site.example', 'c1')],
            [self::domain('d1', 2, 'reg.example', 'c1')],
            [],
            [['id' => 3, 'publicId' => 'i1', 'customerId' => 1, 'customer' => ['publicId' => 'c1'], 'ipAddress' => '203.0.113.10', 'label' => 'web1', 'status' => 'active']],
        )
            ->on('PATCH', '/api/websites/w1', 200, self::website('w1', 1, 'site.example', 'c1', 'inactive'))
            ->on('DELETE', '/api/customer-domains/d1', 200, ['success' => true])
            ->on('DELETE', '/api/customer-ips/i1', 200, ['success' => true]);

        $result = $this->reconciler()->run();

        self::assertSame(1, $result->paused, 'website follows on_terminate (default pause)');
        self::assertSame(2, $result->deleted);
        self::assertSame(['w1'], array_column((new MonitorMap())->all(), 'monitor_public_id'));
    }

    /** @return array<string,array{0:string,1:?string}> object kind, its WHMCS status (null: the row is gone) */
    public static function endEvents(): array
    {
        return [
            'hosting Terminated' => ['hosting', 'Terminated'],
            'hosting Cancelled' => ['hosting', 'Cancelled'],
            'hosting deleted' => ['hosting', null],
            'domain Expired' => ['domain', 'Expired'],
            'domain Transferred Away' => ['domain', 'Transferred Away'],
            'domain Cancelled' => ['domain', 'Cancelled'],
            'domain deleted' => ['domain', null],
            'client deleted' => ['client', null],
        ];
    }

    /**
     * Spec §8.5 end events × origin (round 2, D1): only monitors the addon created are deleted (`on_terminate=delete`
     * here); an adopted website is paused, every other adopted monitor detached and left untouched.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('endEvents')]
    public function testEndEventsDeleteOnlyCreatedMonitorsAndPauseOrDetachAdoptedOnes(string $object, ?string $status): void
    {
        $this->settings->set('on_terminate', 'delete');
        foreach (['sync_hosting_domain_expiry', 'sync_domain_dns', 'sync_server_ips'] as $key) {
            $this->settings->setBool($key, true);
        }
        $clientId = $object === 'client' ? 42 : $this->client;
        $this->mapClient('c1', $clientId);
        $websites = $domains = $dns = $ips = [];
        $expected = ['deleted' => [], 'paused' => [], 'detached' => []];
        foreach (['created' => 1, 'adopted' => 2] as $origin => $n) {
            $host = $origin . '.example';
            $ip = '203.0.113.' . $n;
            $server = $this->insert('tblservers', ['name' => 'web' . $n, 'ipaddress' => $ip]);
            $rows = [];
            if ($object !== 'domain') {
                $ref = $status === null && $object === 'hosting' ? 900 + $n
                    : $this->insert('tblhosting', ['userid' => $clientId, 'domain' => $host, 'domainstatus' => $status ?? 'Active', 'server' => $server]);
                $rows[] = ['hosting', $ref, 'website', 'w-' . $origin];
                $rows[] = ['hosting', $ref, 'customer_domain', 'hd-' . $origin];
                $rows[] = ['server_ip', MonitorPlanner::serverIpRefId($server, $clientId), 'customer_ip', 'i-' . $origin];
                $websites[] = self::website('w-' . $origin, $n, $host, 'c1');
                $domains[] = self::domain('hd-' . $origin, 10 + $n, $host, 'c1');
                $ips[] = ['id' => 20 + $n, 'publicId' => 'i-' . $origin, 'customerId' => 1, 'customer' => ['publicId' => 'c1'], 'ipAddress' => $ip, 'label' => 'web' . $n, 'status' => 'active'];
            }
            if ($object !== 'hosting') {
                $reg = 'reg-' . $host;
                $ref = $status === null && $object === 'domain' ? 900 + $n
                    : $this->insert('tbldomains', ['userid' => $clientId, 'domain' => $reg, 'status' => $status ?? 'Active']);
                $rows[] = ['domain', $ref, 'customer_domain', 'rd-' . $origin];
                $rows[] = ['domain', $ref, 'dns', 'n-' . $origin];
                $domains[] = self::domain('rd-' . $origin, 30 + $n, $reg, 'c1');
                $dns[] = ['id' => 40 + $n, 'publicId' => 'n-' . $origin, 'customerId' => 1, 'customerPublicId' => 'c1', 'name' => $reg, 'hostname' => $reg, 'status' => 'active'];
            }
            foreach ($rows as [$kind, $ref, $type, $publicId]) {
                $target = $type === 'customer_ip' ? $ip : ($kind === 'domain' ? 'reg-' . $host : $host);
                self::mapRow(['origin' => $origin, 'kind' => $kind, 'whmcs_ref_id' => $ref, 'monitor_type' => $type, 'monitor_public_id' => $publicId, 'target' => $target]);
                $expected[$origin === 'created' ? 'deleted' : ($type === 'website' ? 'paused' : 'detached')][] = $publicId;
            }
        }
        $this->remote([self::customer('c1', $clientId)], $websites, $domains, $dns, $ips)
            ->on('PATCH', '#^/api/websites/w-adopted$#', 200, self::website('w-adopted', 2, 'adopted.example', 'c1', 'inactive'))
            ->on('DELETE', '#^/api/(websites|customer-domains|dns-monitors|customer-ips)/[a-z]+-created$#', 200, ['success' => true]);

        $result = $this->reconciler()->run();

        $deleted = array_map(fn (array $r): string => basename((string) parse_url($r['url'], PHP_URL_PATH)), $this->sent('DELETE', '#.#'));
        self::assertSame(self::sorted($expected['deleted']), self::sorted($deleted), 'only created monitors are deleted');
        self::assertSame(array_map(fn (string $id): string => '/api/websites/' . $id, $expected['paused']), array_map(fn (array $r): string => (string) parse_url($r['url'], PHP_URL_PATH), $this->sent('PATCH', '#.#')));
        self::assertSame([count($expected['deleted']), count($expected['paused']), count($expected['detached'])], [$result->deleted, $result->paused, $result->detached]);
        $left = array_column((new MonitorMap())->all(), 'state', 'monitor_public_id');
        self::assertSame(array_fill_keys($expected['paused'], MonitorMap::STATE_PAUSED), $left, 'adopted websites stay mapped and paused, detached rows are gone');
        self::assertSame(0, $result->errors);
    }

    /** @return array<string,array{0:\Closure(Settings,int):void}> */
    public static function configExclusions(): array
    {
        return [
            'client filter Never' => [static fn (Settings $s, int $client) => $s->setCustomerFilter([$client => 'skip'])],
            'whitelist without Always' => [static fn (Settings $s, int $client) => $s->set('filter_mode', 'whitelist')],
            'type switches off' => [static function (Settings $s): void {
                foreach (['sync_hosting_website', 'sync_hosting_domain_expiry', 'sync_domain_expiry', 'sync_domain_dns', 'sync_server_ips'] as $key) {
                    $s->setBool($key, false);
                }
            }],
            'ignored targets' => [static fn (Settings $s) => $s->setIgnoredTargets(['site.example', 'reg.example', '203.0.113.10'])],
        ];
    }

    /**
     * Round 2, D1: a monitor the WHMCS state still wants (Active hosting, Active domain, Active hosting on the server) but
     * the configuration excludes is detached, even when the addon created it: no pause, no delete, whatever on_terminate.
     *
     * @param \Closure(Settings,int):void $exclude
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('configExclusions')]
    public function testConfigurationExclusionDetachesEvenCreatedMonitorsAndNeverTouchesThem(\Closure $exclude): void
    {
        $this->settings->set('on_terminate', 'delete');
        foreach (['sync_hosting_domain_expiry', 'sync_domain_dns', 'sync_server_ips'] as $key) {
            $this->settings->setBool($key, true);
        }
        $server = $this->insert('tblservers', ['name' => 'web1', 'ipaddress' => '203.0.113.10']);
        $h = $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'site.example', 'domainstatus' => 'Active', 'server' => $server]);
        $d = $this->insert('tbldomains', ['userid' => $this->client, 'domain' => 'reg.example', 'status' => 'Active']);
        $ipRef = MonitorPlanner::serverIpRefId($server, $this->client);
        foreach ([['hosting', $h, 'website', 'w1', 'site.example'], ['hosting', $h, 'customer_domain', 'hd1', 'site.example'], ['domain', $d, 'customer_domain', 'rd1', 'reg.example'], ['domain', $d, 'dns', 'n1', 'reg.example'], ['server_ip', $ipRef, 'customer_ip', 'i1', '203.0.113.10']] as [$kind, $ref, $type, $publicId, $target]) {
            self::mapRow(['origin' => 'created', 'kind' => $kind, 'whmcs_ref_id' => $ref, 'monitor_type' => $type, 'monitor_public_id' => $publicId, 'target' => $target]);
        }
        $this->mapClient();
        $exclude($this->settings, $this->client);
        $this->remote(
            [self::customer('c1', $this->client)],
            [self::website('w1', 1, 'site.example', 'c1')],
            [self::domain('hd1', 2, 'site.example', 'c1'), self::domain('rd1', 3, 'reg.example', 'c1')],
            [['id' => 4, 'publicId' => 'n1', 'customerId' => 1, 'customerPublicId' => 'c1', 'name' => 'reg.example', 'hostname' => 'reg.example', 'status' => 'active']],
            [['id' => 5, 'publicId' => 'i1', 'customerId' => 1, 'customer' => ['publicId' => 'c1'], 'ipAddress' => '203.0.113.10', 'label' => 'web1', 'status' => 'active']],
        );

        $result = $this->reconciler()->run();

        self::assertSame([], $this->sent('DELETE', '#.#'));
        self::assertSame([], $this->sent('PATCH', '#.#'));
        self::assertSame([], $this->sent('POST', '#^/api/(websites|customers|dns-monitors)#'));
        self::assertSame([5, 0, 0, 0], [$result->detached, $result->deleted, $result->paused, $result->errors]);
        self::assertSame([], (new MonitorMap())->all());
        self::assertStringContainsString('detached=5', $result->summary());
        $activity = implode("\n", array_column($GLOBALS['uptimeify_test']['activity'], 0));
        self::assertStringContainsString('excluded by configuration', $activity);
        self::assertStringContainsString('monitor w1 left untouched', $activity);
    }

    public function testDisabledServerDetachesTheServerIpMonitor(): void
    {
        $this->settings->setBool('sync_server_ips', true);
        $this->settings->setBool('sync_hosting_website', false);
        $server = $this->insert('tblservers', ['name' => 'web1', 'ipaddress' => '203.0.113.10', 'disabled' => 1]);
        $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'site.example', 'domainstatus' => 'Active', 'server' => $server]);
        $ipRef = MonitorPlanner::serverIpRefId($server, $this->client);
        self::mapRow(['origin' => 'created', 'kind' => 'server_ip', 'whmcs_ref_id' => $ipRef, 'monitor_type' => 'customer_ip', 'monitor_public_id' => 'i1', 'target' => '203.0.113.10']);
        $this->mapClient();
        $this->remote([self::customer('c1', $this->client)], [], [], [], [['id' => 5, 'publicId' => 'i1', 'customerId' => 1, 'customer' => ['publicId' => 'c1'], 'ipAddress' => '203.0.113.10', 'status' => 'active']]);

        $result = $this->reconciler()->run();

        self::assertSame([], $this->sent('DELETE', '#.#'));
        self::assertSame([1, 0], [$result->detached, $result->deleted]);
        self::assertStringContainsString('server_unavailable', implode("\n", $result->messages));
    }

    public function testDetachInASharedGroupLeavesTheMonitorEvenWhenAnotherRowEnds(): void
    {
        $this->settings->set('on_terminate', 'delete');
        $this->settings->setBool('sync_hosting_domain_expiry', true);
        $this->settings->setBool('sync_domain_expiry', false);
        $h = $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'site.example', 'domainstatus' => 'Terminated']);
        $d = $this->insert('tbldomains', ['userid' => $this->client, 'domain' => 'site.example', 'status' => 'Active']);
        self::mapRow(['origin' => 'created', 'kind' => 'hosting', 'whmcs_ref_id' => $h, 'monitor_type' => 'customer_domain', 'monitor_public_id' => 'd1', 'target' => 'site.example']);
        self::mapRow(['origin' => 'created', 'kind' => 'domain', 'whmcs_ref_id' => $d, 'monitor_type' => 'customer_domain', 'monitor_public_id' => 'd1', 'target' => 'site.example']);
        $this->mapClient();
        $this->settings->setBool('sync_hosting_website', false);
        $this->remote([self::customer('c1', $this->client)], [], [self::domain('d1', 1, 'site.example', 'c1')]);

        $result = $this->reconciler()->run();

        self::assertSame([], $this->sent('DELETE', '#.#'), 'the registrar domain still wants d1, only its switch is off');
        self::assertSame([0, 1], [$result->deleted, $result->detached]);
        self::assertSame([], (new MonitorMap())->all());
    }

    // ---- fix round 1: what a detached row knew survives until the monitor is adopted again ----

    public function testTypeToggleOffAndOnKeepsCreatedSoTheEndEventStillDeletes(): void
    {
        $this->settings->setBool('sync_domain_dns', true);
        $this->settings->setBool('sync_domain_expiry', false);
        $d = $this->insert('tbldomains', ['userid' => $this->client, 'domain' => 'reg.example', 'status' => 'Active']);
        self::mapRow(['origin' => 'created', 'kind' => 'domain', 'whmcs_ref_id' => $d, 'monitor_type' => 'dns', 'monitor_public_id' => 'n1', 'target' => 'reg.example']);
        $this->mapClient();
        $dns = [['id' => 4, 'publicId' => 'n1', 'customerId' => 1, 'customerPublicId' => 'c1', 'name' => 'reg.example', 'hostname' => 'reg.example', 'status' => 'active']];

        $this->settings->setBool('sync_domain_dns', false);
        $this->remote([self::customer('c1', $this->client)], [], [], $dns);
        self::assertSame(1, $this->reconciler()->run()->detached);
        self::assertSame(['origin' => 'created', 'admin_paused' => false], (new DetachedMonitors())->find('dns', 'n1'));

        $this->settings->setBool('sync_domain_dns', true);
        $this->remote([self::customer('c1', $this->client)], [], [], $dns);
        self::assertSame(1, $this->reconciler()->run()->adopted);
        self::assertSame('created', self::row('domain', $d, 'dns')['origin'], 'adopting it again restores what the addon knew');
        self::assertNull((new DetachedMonitors())->find('dns', 'n1'), 'the record moved into the row');

        \WHMCS\Database\Capsule::table('tbldomains')->where('id', $d)->update(['status' => 'Expired']);
        $this->remote([self::customer('c1', $this->client)], [], [], $dns)->on('DELETE', '/api/dns-monitors/n1', 200, ['success' => true]);
        $result = $this->reconciler()->run();
        self::assertCount(1, $this->sent('DELETE', '/api/dns-monitors/n1'));
        self::assertSame(1, $result->deleted);
    }

    public function testFilterNeverAndBackKeepsCreatedAndOnTerminateDelete(): void
    {
        $this->settings->set('on_terminate', 'delete');
        $h = $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'site.example', 'domainstatus' => 'Active']);
        self::mapRow(['origin' => 'created', 'kind' => 'hosting', 'whmcs_ref_id' => $h, 'monitor_type' => 'website', 'monitor_public_id' => 'w1', 'target' => 'site.example']);
        $this->mapClient();
        $site = [self::website('w1', 1, 'site.example', 'c1')];

        $this->settings->setCustomerFilter([$this->client => 'skip']);
        $this->remote([self::customer('c1', $this->client)], $site);
        $this->reconciler()->run();
        $this->settings->setCustomerFilter([]);
        $this->remote([self::customer('c1', $this->client)], $site);
        $this->reconciler()->run();

        \WHMCS\Database\Capsule::table('tblhosting')->where('id', $h)->update(['domainstatus' => 'Terminated']);
        $this->remote([self::customer('c1', $this->client)], $site)->on('DELETE', '/api/websites/w1', 200, ['success' => true]);
        $this->reconciler()->run();

        self::assertCount(1, $this->sent('DELETE', '/api/websites/w1'), 'on_terminate=delete did not silently become a pause');
        self::assertSame([], $this->sent('PATCH', '#.#'));
    }

    public function testDetachedAdminPausedWebsiteAndCustomerIpStayPausedWhenAdoptedAgain(): void
    {
        $this->settings->setBool('sync_server_ips', true);
        $server = $this->insert('tblservers', ['name' => 'web1', 'ipaddress' => '203.0.113.10']);
        $h = $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'site.example', 'domainstatus' => 'Active', 'server' => $server]);
        $ipRef = MonitorPlanner::serverIpRefId($server, $this->client);
        self::mapRow(['admin_paused' => true, 'kind' => 'hosting', 'whmcs_ref_id' => $h, 'monitor_type' => 'website', 'monitor_public_id' => 'w1', 'target' => 'site.example', 'state' => MonitorMap::STATE_PAUSED]);
        self::mapRow(['admin_paused' => true, 'kind' => 'server_ip', 'whmcs_ref_id' => $ipRef, 'monitor_type' => 'customer_ip', 'monitor_public_id' => 'i1', 'target' => '203.0.113.10', 'state' => MonitorMap::STATE_PAUSED]);
        $this->mapClient();
        $remote = fn () => $this->remote(
            [self::customer('c1', $this->client)],
            [self::website('w1', 1, 'site.example', 'c1', 'inactive')],
            [],
            [],
            [['id' => 5, 'publicId' => 'i1', 'customerId' => 1, 'customer' => ['publicId' => 'c1'], 'ipAddress' => '203.0.113.10', 'label' => 'web1', 'status' => 'disabled']],
        );

        $this->settings->setCustomerFilter([$this->client => 'skip']);
        $remote();
        self::assertSame(2, $this->reconciler()->run()->detached);
        $this->settings->setCustomerFilter([]);
        $remote();
        $result = $this->reconciler()->run();

        self::assertSame([], $this->sent('PATCH', '#.#'), 'neither is resumed after being adopted again');
        self::assertSame([2, 0], [$result->adopted, $result->resumed]);
        foreach ([['hosting', $h, 'website'], ['server_ip', $ipRef, 'customer_ip']] as [$kind, $ref, $type]) {
            $row = self::row($kind, $ref, $type);
            self::assertSame([MonitorMap::STATE_PAUSED, true], [$row['state'], $row['admin_paused']], $type);
        }
    }

    public function testDeduplicatedCreateRestoresTheDetachedRecord(): void
    {
        $this->settings->setBool('sync_server_ips', true);
        $this->settings->setBool('sync_hosting_website', false);
        $server = $this->insert('tblservers', ['name' => 'web1', 'ipaddress' => '203.0.113.10']);
        $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'site.example', 'domainstatus' => 'Active', 'server' => $server]);
        $ipRef = MonitorPlanner::serverIpRefId($server, $this->client);
        (new DetachedMonitors())->remember('customer_ip', 'i1', MonitorMap::ORIGIN_CREATED, false);
        $this->mapClient();
        $this->remote([self::customer('c1', $this->client)])
            ->on('POST', '/api/customers/c1/ips', 200, ['id' => 5, 'publicId' => 'i1', 'customerId' => 1, 'ipAddress' => '203.0.113.10', 'label' => 'web1', 'status' => 'active', 'deduplicated' => true]);

        $this->reconciler()->run();

        self::assertSame('created', self::row('server_ip', $ipRef, 'customer_ip')['origin']);
        self::assertNull((new DetachedMonitors())->find('customer_ip', 'i1'));
    }

    public function testDeletingARemoteMonitorForgetsItsDetachedRecord(): void
    {
        $this->settings->setBool('sync_hosting_domain_expiry', false);
        $d = $this->insert('tbldomains', ['userid' => $this->client, 'domain' => 'site.example', 'status' => 'Expired']);
        self::mapRow(['origin' => 'created', 'kind' => 'domain', 'whmcs_ref_id' => $d, 'monitor_type' => 'customer_domain', 'monitor_public_id' => 'd1', 'target' => 'site.example']);
        (new DetachedMonitors())->remember('customer_domain', 'd1', MonitorMap::ORIGIN_CREATED, false); // another object's row was detached earlier
        $this->mapClient();
        $this->remote([self::customer('c1', $this->client)], [], [self::domain('d1', 1, 'site.example', 'c1')])
            ->on('DELETE', '/api/customer-domains/d1', 200, ['success' => true]);

        $this->reconciler()->run();

        self::assertCount(1, $this->sent('DELETE', '/api/customer-domains/d1'));
        self::assertNull((new DetachedMonitors())->find('customer_domain', 'd1'));
    }

    public function testSuspendedHostingWithItsTypeSwitchedOffIsDetachedNotPaused(): void
    {
        $h = $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'site.example', 'domainstatus' => 'Suspended']);
        self::mapRow(['origin' => 'created', 'kind' => 'hosting', 'whmcs_ref_id' => $h, 'monitor_type' => 'website', 'monitor_public_id' => 'w1', 'target' => 'site.example']);
        $this->mapClient();
        $this->settings->setBool('sync_hosting_website', false);
        $this->remote([self::customer('c1', $this->client)], [self::website('w1', 1, 'site.example', 'c1')]);

        $result = $this->reconciler()->run();

        self::assertSame([], $this->sent('PATCH', '#.#'), 'not paused first and left paused and unmapped after the unsuspend');
        self::assertSame([1, 0], [$result->detached, $result->paused]);
        self::assertSame([], (new MonitorMap())->all());
    }

    public function testReplacedAdminPausedWebsiteHandsThePauseToTheAdoptedMonitor(): void
    {
        $h = $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'new.example', 'domainstatus' => 'Active']);
        self::mapRow(['origin' => 'created', 'admin_paused' => true, 'kind' => 'hosting', 'whmcs_ref_id' => $h, 'monitor_type' => 'website', 'monitor_public_id' => 'w-old', 'target' => 'old.example', 'state' => MonitorMap::STATE_PAUSED]);
        $this->mapClient();
        $this->remote([self::customer('c1', $this->client)], [self::website('w-old', 1, 'old.example', 'c1', 'inactive'), self::website('w-new', 2, 'new.example', 'c1')])
            ->on('PATCH', '/api/websites/w-new', 200, self::website('w-new', 2, 'new.example', 'c1', 'inactive'));

        $result = $this->reconciler()->run();

        self::assertSame([['status' => 'inactive']], array_map(fn (array $r): array => self::body($r), $this->sent('PATCH', '/api/websites/w-new')), 'the admin pause follows the object to its new monitor');
        self::assertSame(0, $result->resumed);
        $row = self::row('hosting', $h, 'website');
        self::assertSame(['w-new', MonitorMap::STATE_PAUSED, true], [$row['monitor_public_id'], $row['state'], $row['admin_paused']]);
    }

    public function testReplacedAdminPausedWebsiteHandsThePauseToTheCreatedMonitor(): void
    {
        $h1 = $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'new.example', 'domainstatus' => 'Active']);
        $h2 = $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'old.example', 'domainstatus' => 'Active']);
        foreach ([$h1, $h2] as $h) {
            self::mapRow(['origin' => 'created', 'admin_paused' => true, 'kind' => 'hosting', 'whmcs_ref_id' => $h, 'monitor_type' => 'website', 'monitor_public_id' => 'w-old', 'target' => 'old.example', 'state' => MonitorMap::STATE_PAUSED]);
        }
        $this->mapClient();
        $this->remote([self::customer('c1', $this->client)], [self::website('w-old', 1, 'old.example', 'c1', 'inactive')])
            ->on('PATCH', '/api/websites/w1', 200, self::website('w1', 101, 'new.example', 'c1', 'inactive'));
        $this->createsWebsites();

        $this->reconciler()->run();

        self::assertSame([['status' => 'inactive']], array_map(fn (array $r): array => self::body($r), $this->sent('PATCH', '/api/websites/w1')), 'created, then paused');
        $row = self::row('hosting', $h1, 'website');
        self::assertSame(['w1', MonitorMap::STATE_PAUSED, true], [$row['monitor_public_id'], $row['state'], $row['admin_paused']]);
        self::assertTrue(self::row('hosting', $h2, 'website')['admin_paused']);
    }

    public function testAdminPausedCustomerIpStaysDisabledWhileTheHostingIsActive(): void
    {
        $this->settings->setBool('sync_server_ips', true);
        $this->settings->setBool('sync_hosting_website', false);
        $server = $this->insert('tblservers', ['name' => 'web1', 'ipaddress' => '203.0.113.10']);
        $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'site.example', 'domainstatus' => 'Active', 'server' => $server]);
        $ipRef = MonitorPlanner::serverIpRefId($server, $this->client);
        self::mapRow(['admin_paused' => true, 'kind' => 'server_ip', 'whmcs_ref_id' => $ipRef, 'monitor_type' => 'customer_ip', 'monitor_public_id' => 'i1', 'target' => '203.0.113.10', 'state' => MonitorMap::STATE_PAUSED]);
        $this->mapClient();
        $this->remote([self::customer('c1', $this->client)], [], [], [], [['id' => 5, 'publicId' => 'i1', 'customerId' => 1, 'customer' => ['publicId' => 'c1'], 'ipAddress' => '203.0.113.10', 'status' => 'disabled']]);

        $result = $this->reconciler()->run();

        self::assertSame([], $this->sent('PATCH', '#.#'));
        self::assertSame(0, $result->resumed);
        self::assertSame(MonitorMap::STATE_PAUSED, self::row('server_ip', $ipRef, 'customer_ip')['state']);
    }

    // ---- round 2 D5: customer IP of a client whose hostings on the server are all suspended ----

    public function testSuspendedHostingsPauseTheCustomerIpAndUnsuspendResumesIt(): void
    {
        $this->settings->setBool('sync_server_ips', true);
        $this->settings->setBool('sync_hosting_website', false);
        $server = $this->insert('tblservers', ['name' => 'web1', 'ipaddress' => '203.0.113.10']);
        $h = $this->insert('tblhosting', ['userid' => $this->client, 'domain' => '', 'domainstatus' => 'Suspended', 'server' => $server]);
        $ipRef = MonitorPlanner::serverIpRefId($server, $this->client);
        self::mapRow(['origin' => 'created', 'kind' => 'server_ip', 'whmcs_ref_id' => $ipRef, 'monitor_type' => 'customer_ip', 'monitor_public_id' => 'i1', 'monitor_numeric_id' => 5, 'target' => '203.0.113.10']);
        $this->mapClient();
        $ip = fn (string $status): array => ['id' => 5, 'publicId' => 'i1', 'customerId' => 1, 'customer' => ['publicId' => 'c1'], 'ipAddress' => '203.0.113.10', 'label' => 'web1', 'status' => $status];
        $this->remote([self::customer('c1', $this->client)], [], [], [], [$ip('active')])
            ->on('PATCH', '/api/customer-ips/i1', 200, $ip('disabled'));

        $result = $this->reconciler()->run();

        self::assertSame([['status' => 'disabled']], array_map(fn (array $r): array => self::body($r), $this->sent('PATCH', '/api/customer-ips/i1')));
        self::assertSame([], $this->sent('DELETE', '#.#'), 'suspended is not an end: the IP monitor is paused, not deleted');
        self::assertSame([1, 0], [$result->paused, $result->deleted]);
        self::assertSame(MonitorMap::STATE_PAUSED, self::row('server_ip', $ipRef, 'customer_ip')['state']);

        \WHMCS\Database\Capsule::table('tblhosting')->where('id', $h)->update(['domainstatus' => 'Active']);
        $this->remote([self::customer('c1', $this->client)], [], [], [], [$ip('disabled')])
            ->on('PATCH', '/api/customer-ips/i1', 200, $ip('active'));
        $result = $this->reconciler()->run();

        self::assertSame([['status' => 'active']], array_map(fn (array $r): array => self::body($r), $this->sent('PATCH', '/api/customer-ips/i1')));
        self::assertSame(1, $result->resumed);
        self::assertSame(MonitorMap::STATE_ACTIVE, self::row('server_ip', $ipRef, 'customer_ip')['state']);

        \WHMCS\Database\Capsule::table('tblhosting')->where('id', $h)->update(['domainstatus' => 'Terminated']);
        $this->remote([self::customer('c1', $this->client)], [], [], [], [$ip('active')])
            ->on('DELETE', '/api/customer-ips/i1', 200, ['success' => true]);
        $result = $this->reconciler()->run();

        self::assertCount(1, $this->sent('DELETE', '/api/customer-ips/i1'), 'no Active or Suspended hosting left: the end-event rule applies');
        self::assertSame(1, $result->deleted);
    }

    public function testResumingACustomerIpAtTheIpLimitMarksTheRowQuota(): void
    {
        $this->settings->setBool('sync_server_ips', true);
        $this->settings->setBool('sync_hosting_website', false);
        $server = $this->insert('tblservers', ['name' => 'web1', 'ipaddress' => '203.0.113.10']);
        $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'site.example', 'domainstatus' => 'Active', 'server' => $server]);
        $ipRef = MonitorPlanner::serverIpRefId($server, $this->client);
        self::mapRow(['kind' => 'server_ip', 'whmcs_ref_id' => $ipRef, 'monitor_type' => 'customer_ip', 'monitor_public_id' => 'i1', 'target' => '203.0.113.10', 'state' => MonitorMap::STATE_PAUSED]);
        $this->mapClient();
        $this->remote([self::customer('c1', $this->client)], [], [], [], [['id' => 5, 'publicId' => 'i1', 'customerId' => 1, 'customer' => ['publicId' => 'c1'], 'ipAddress' => '203.0.113.10', 'status' => 'disabled']])
            ->on('PATCH', '/api/customer-ips/i1', 403, ['statusCode' => 403, 'statusMessage' => 'Active IP limit reached. Your customer package allows maximum 1 active IPs for DNSBL monitoring.', 'data' => ['code' => 'generic']]);

        $result = $this->reconciler()->run();

        self::assertSame(1, $result->quotaSkipped);
        $row = self::row('server_ip', $ipRef, 'customer_ip');
        self::assertSame([MonitorMap::STATE_ERROR, Client::IP_LIMIT_CODE], [$row['state'], $row['last_error']]);
    }

    // ---- round 2 D7: persistent admin pause ------------------------------------------------

    public function testAdminPausedMonitorIsNeverResumedAndPausedAgainWhenResumedOutside(): void
    {
        $h = $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'site.example', 'domainstatus' => 'Active']);
        self::mapRow(['admin_paused' => true, 'kind' => 'hosting', 'whmcs_ref_id' => $h, 'monitor_type' => 'website', 'monitor_public_id' => 'w1', 'monitor_numeric_id' => 1, 'target' => 'site.example', 'state' => MonitorMap::STATE_PAUSED]);
        $this->mapClient();
        $this->remote([self::customer('c1', $this->client)], [self::website('w1', 1, 'site.example', 'c1', 'inactive')]);

        $result = $this->reconciler()->run();

        self::assertSame([], $this->sent('PATCH', '#.#'), 'an active hosting does not resume an admin pause');
        self::assertSame(0, $result->resumed);
        self::assertSame([MonitorMap::STATE_PAUSED, true], [self::row('hosting', $h, 'website')['state'], self::row('hosting', $h, 'website')['admin_paused']]);

        $this->remote([self::customer('c1', $this->client)], [self::website('w1', 1, 'site.example', 'c1', 'active')])
            ->on('PATCH', '/api/websites/w1', 200, self::website('w1', 1, 'site.example', 'c1', 'inactive'));
        $result = $this->reconciler()->run();

        self::assertSame([['status' => 'inactive']], array_map(fn (array $r): array => self::body($r), $this->sent('PATCH', '/api/websites/w1')), 'kept inactive');
        self::assertSame(1, $result->paused);
    }

    public function testAdminPauseIsInheritedByARowAdoptingTheSameMonitor(): void
    {
        $h1 = $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'site.example', 'domainstatus' => 'Active']);
        $h2 = $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'www.site.example', 'domainstatus' => 'Active']);
        self::mapRow(['admin_paused' => true, 'kind' => 'hosting', 'whmcs_ref_id' => $h1, 'monitor_type' => 'website', 'monitor_public_id' => 'w1', 'target' => 'site.example', 'state' => MonitorMap::STATE_PAUSED]);
        $this->mapClient();
        $this->remote([self::customer('c1', $this->client)], [self::website('w1', 1, 'site.example', 'c1', 'inactive')]);

        $this->reconciler()->run();

        self::assertSame([], $this->sent('PATCH', '#.#'));
        $row = self::row('hosting', $h2, 'website');
        self::assertSame(['w1', MonitorMap::STATE_PAUSED, true], [$row['monitor_public_id'], $row['state'], $row['admin_paused']]);
    }

    public function testEndEventStillAppliesToAnAdminPausedMonitor(): void
    {
        $this->settings->set('on_terminate', 'delete');
        $h = $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'site.example', 'domainstatus' => 'Terminated']);
        self::mapRow(['origin' => 'created', 'admin_paused' => true, 'kind' => 'hosting', 'whmcs_ref_id' => $h, 'monitor_type' => 'website', 'monitor_public_id' => 'w1', 'target' => 'site.example', 'state' => MonitorMap::STATE_PAUSED]);
        $this->mapClient();
        $this->remote([self::customer('c1', $this->client)], [self::website('w1', 1, 'site.example', 'c1', 'inactive')])
            ->on('DELETE', '/api/websites/w1', 200, ['success' => true]);

        $result = $this->reconciler()->run();

        self::assertCount(1, $this->sent('DELETE', '/api/websites/w1'));
        self::assertSame(1, $result->deleted);
    }

    public function testTimeBudgetDoesNotQueueAnAdminPausedMonitorThatIsPaused(): void
    {
        $h = $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'site.example', 'domainstatus' => 'Active']);
        self::mapRow(['admin_paused' => true, 'kind' => 'hosting', 'whmcs_ref_id' => $h, 'monitor_type' => 'website', 'monitor_public_id' => 'w1', 'target' => 'site.example', 'state' => MonitorMap::STATE_PAUSED]);
        $this->mapClient();
        $this->remote([self::customer('c1', $this->client)], [self::website('w1', 1, 'site.example', 'c1', 'inactive')]);
        $t = 0.0;

        $result = $this->reconciler(function () use (&$t): float {
            return $t += 1.0;
        })->run(0.5);

        self::assertSame('time_budget', $result->abortReason);
        self::assertSame(0, $result->queued, 'already as the admin wants it');
    }

    // ---- round 2 D1: origin -----------------------------------------------------------------

    public function testOriginIsCreatedForPostedMonitorsAndAdoptedOtherwise(): void
    {
        $this->settings->setBool('sync_hosting_domain_expiry', true);
        $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'new.example', 'domainstatus' => 'Active']);
        $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'old.example', 'domainstatus' => 'Active']);
        $this->insert('tbldomains', ['userid' => $this->client, 'domain' => 'new.example', 'status' => 'Active']);
        $this->mapClient();
        $this->remote([self::customer('c1', $this->client)], [self::website('w-old', 1, 'old.example', 'c1')], [self::domain('d-old', 2, 'old.example', 'c1')]);
        $this->createsWebsites();
        $n = 0;
        $this->intercept(function (string $method, string $path) use (&$n): ?HttpResponse {
            if ($method !== 'POST' || $path !== '/api/customers/c1/domains') {
                return null;
            }
            $n++; // a second POST for the same name would be answered as deduplicated, like the API does
            return new HttpResponse(200, [], (string) json_encode(['id' => 21, 'publicId' => 'd-new', 'customerId' => 1, 'domainName' => 'new.example', 'label' => 'new.example', 'status' => 'active', 'deduplicated' => $n > 1]));
        });

        $this->reconciler()->run();

        $origins = [];
        foreach ((new MonitorMap())->all() as $row) {
            $origins[$row['kind'] . ':' . $row['monitor_type'] . ':' . $row['target']] = $row['origin'];
        }
        ksort($origins);
        self::assertSame([
            'domain:customer_domain:new.example' => 'created', // shares the monitor the addon just created for the hosting
            'hosting:customer_domain:new.example' => 'created',
            'hosting:customer_domain:old.example' => 'adopted',
            'hosting:website:new.example' => 'created',
            'hosting:website:old.example' => 'adopted',
        ], $origins);
        self::assertSame(1, $n, 'the registrar domain adopts the hosting\'s new monitor instead of posting again');
    }

    public function testDeduplicatedCreateAnswerIsAnAdoptedMonitorThatAnEndEventOnlyDetaches(): void
    {
        $this->settings->setBool('sync_server_ips', true);
        $this->settings->setBool('sync_hosting_website', false);
        $server = $this->insert('tblservers', ['name' => 'web1', 'ipaddress' => '203.0.113.10']);
        $h = $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'site.example', 'domainstatus' => 'Active', 'server' => $server]);
        $ipRef = MonitorPlanner::serverIpRefId($server, $this->client);
        $this->mapClient();
        $ip = ['id' => 5, 'publicId' => 'i1', 'customerId' => 1, 'ipAddress' => '203.0.113.10', 'label' => 'web1', 'status' => 'active'];
        $this->remote([self::customer('c1', $this->client)])
            ->on('POST', '/api/customers/c1/ips', 200, $ip + ['deduplicated' => true]);

        $this->reconciler()->run();

        self::assertSame('adopted', self::row('server_ip', $ipRef, 'customer_ip')['origin'], 'the API already had it: the addon did not create it');

        \WHMCS\Database\Capsule::table('tblhosting')->where('id', $h)->update(['domainstatus' => 'Terminated']);
        $this->remote([self::customer('c1', $this->client)], [], [], [], [$ip + ['customer' => ['publicId' => 'c1']]]);
        $result = $this->reconciler()->run();

        self::assertSame([], $this->sent('DELETE', '#.#'));
        self::assertSame(1, $result->detached);
    }

    // ---- reference counting and ownership ---------------------------------------------

    public function testSharedMonitorIsOnlyPausedOrDeletedWhenNoRowReferencesItAnyMore(): void
    {
        $this->settings->set('on_terminate', 'delete');
        $h1 = $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'site.example', 'domainstatus' => 'Terminated']);
        $h2 = $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'www.site.example', 'domainstatus' => 'Active']);
        self::mapRow(['origin' => 'created', 'kind' => 'hosting', 'whmcs_ref_id' => $h1, 'monitor_type' => 'website', 'monitor_public_id' => 'w1', 'target' => 'site.example']);
        self::mapRow(['origin' => 'created', 'kind' => 'hosting', 'whmcs_ref_id' => $h2, 'monitor_type' => 'website', 'monitor_public_id' => 'w1', 'target' => 'site.example']);
        $this->mapClient();
        $this->remote([self::customer('c1', $this->client)], [self::website('w1', 1, 'site.example', 'c1')]);

        $result = $this->reconciler()->run();

        self::assertSame([], $this->sent('DELETE', '#.#'), 'hosting 2 still uses the monitor');
        self::assertSame(0, $result->deleted);
        self::assertNull((new MonitorMap())->find('hosting', $h1, 'website'));
        self::assertSame('w1', self::row('hosting', $h2, 'website')['monitor_public_id']);

        self::mapRow(['origin' => 'created', 'kind' => 'hosting', 'whmcs_ref_id' => $h1, 'monitor_type' => 'website', 'monitor_public_id' => 'w1', 'target' => 'site.example']);
        \WHMCS\Database\Capsule::table('tblhosting')->whereIn('id', [$h1, $h2])->update(['domainstatus' => 'Suspended']);
        $this->remote([self::customer('c1', $this->client)], [self::website('w1', 1, 'site.example', 'c1')])
            ->on('PATCH', '/api/websites/w1', 200, self::website('w1', 1, 'site.example', 'c1', 'inactive'));
        $result = $this->reconciler()->run();
        self::assertCount(1, $this->sent('PATCH', '/api/websites/w1'), 'paused once, when the last active reference goes');
        self::assertSame(1, $result->paused);
        self::assertSame(MonitorMap::STATE_PAUSED, self::row('hosting', $h1, 'website')['state']);
        self::assertSame(MonitorMap::STATE_PAUSED, self::row('hosting', $h2, 'website')['state']);
    }

    /** @return list<array{0:bool}> */
    public static function suspendedFirst(): array
    {
        return [[true], [false]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('suspendedFirst')]
    public function testSharedMonitorDecisionDoesNotDependOnRowOrder(bool $suspendedFirst): void
    {
        $this->settings->set('on_terminate', 'delete');
        $statuses = $suspendedFirst ? ['Suspended', 'Terminated'] : ['Terminated', 'Suspended'];
        $first = $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'site.example', 'domainstatus' => $statuses[0]]);
        $second = $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'site.example', 'domainstatus' => $statuses[1]]);
        [$suspended, $terminated] = $suspendedFirst ? [$first, $second] : [$second, $first];
        foreach ([$first, $second] as $ref) {
            self::mapRow(['origin' => 'created', 'kind' => 'hosting', 'whmcs_ref_id' => $ref, 'monitor_type' => 'website', 'monitor_public_id' => 'w1', 'target' => 'site.example']);
        }
        $this->mapClient();
        $this->remote([self::customer('c1', $this->client)], [self::website('w1', 1, 'site.example', 'c1')])
            ->on('PATCH', '/api/websites/w1', 200, self::website('w1', 1, 'site.example', 'c1', 'inactive'));

        $result = $this->reconciler()->run();

        self::assertCount(1, $this->sent('PATCH', '/api/websites/w1'), 'the suspended hosting still needs the monitor: paused, not left active');
        self::assertSame([], $this->sent('DELETE', '#.#'), 'and not deleted');
        self::assertSame([1, 0], [$result->paused, $result->deleted]);
        self::assertSame(MonitorMap::STATE_PAUSED, self::row('hosting', $suspended, 'website')['state']);
        self::assertNull((new MonitorMap())->find('hosting', $terminated, 'website'));
    }

    public function testStandaloneRetireNeverDeletesWithoutConfirmedOwnership(): void
    {
        $this->settings->set('on_terminate', 'delete');
        $h = $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'a.example', 'domainstatus' => 'Terminated']);
        $h2 = $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'b.example', 'domainstatus' => 'Terminated']);
        self::mapRow(['kind' => 'hosting', 'whmcs_ref_id' => $h, 'monitor_type' => 'website', 'monitor_public_id' => 'w-unlisted', 'target' => 'a.example']);
        self::mapRow(['kind' => 'hosting', 'whmcs_ref_id' => $h2, 'monitor_type' => 'website', 'monitor_public_id' => 'w-nocustomer', 'target' => 'b.example', 'customer_public_id' => null]);
        $index = new RemoteIndex();
        $index->addMonitor(MonitorMap::TYPE_WEBSITE, self::website('w-nocustomer', 2, 'b.example', 'c1'));
        $result = new ReconcileResult();
        $reconciler = $this->reconciler();

        $reconciler->retire(self::row('hosting', $h, 'website'), $index, $result);
        $reconciler->retire(self::row('hosting', $h2, 'website'), $index, $result);

        self::assertSame([], $this->http->requests(), 'no DELETE for a monitor the index does not confirm');
        self::assertSame(MonitorMap::STATE_ORPHANED, self::row('hosting', $h, 'website')['state']);
        self::assertSame(MonitorMap::STATE_ACTIVE, self::row('hosting', $h2, 'website')['state']);
        self::assertSame(0, $result->deleted);
    }

    public function testRetiringNeverTouchesAMonitorOfAnotherCustomer(): void
    {
        $this->settings->set('on_terminate', 'delete');
        $h = $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'site.example', 'domainstatus' => 'Terminated']);
        self::mapRow(['kind' => 'hosting', 'whmcs_ref_id' => $h, 'monitor_type' => 'website', 'monitor_public_id' => 'w1', 'target' => 'site.example']);
        $this->mapClient();
        $this->remote([self::customer('c1', $this->client), self::customer('c2', null, 'Other', 'o@example.com')], [self::website('w1', 1, 'site.example', 'c2')]);

        $this->reconciler()->run();

        self::assertSame([], $this->sent('DELETE', '#.#'));
        self::assertSame([], $this->sent('PATCH', '#.#'));
        self::assertNull((new MonitorMap())->find('hosting', $h, 'website'), 'only the local row goes');
    }

    public function testPlannedRowPointingAtAnotherCustomersMonitorIsDetachedNotTouched(): void
    {
        $h = $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'site.example', 'domainstatus' => 'Active']);
        self::mapRow(['kind' => 'hosting', 'whmcs_ref_id' => $h, 'monitor_type' => 'website', 'monitor_public_id' => 'w-foreign', 'target' => 'site.example', 'customer_public_id' => 'c2']);
        $this->mapClient('c1');
        $this->remote([self::customer('c1', $this->client), self::customer('c2', null, 'Other', 'o@example.com')], [self::website('w-foreign', 1, 'site.example', 'c2', 'inactive')]);
        $this->createsWebsites();

        $result = $this->reconciler()->run();

        self::assertSame([], $this->sent('PATCH', '#.#'), 'the other customer\'s paused monitor is not resumed');
        self::assertSame(1, $result->monitorsCreated);
        $row = self::row('hosting', $h, 'website');
        self::assertSame(['w1', 'c1'], [$row['monitor_public_id'], $row['customer_public_id']]);
    }

    public function testChangedHostingDomainUpdatesACreatedWebsiteInPlace(): void
    {
        $h = $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'new.example', 'domainstatus' => 'Active']);
        self::mapRow(['origin' => 'created', 'kind' => 'hosting', 'whmcs_ref_id' => $h, 'monitor_type' => 'website', 'monitor_public_id' => 'w-old', 'monitor_numeric_id' => 1, 'target' => 'old.example']);
        $this->mapClient();
        $old = self::website('w-old', 1, 'old.example', 'c1', 'active', ['customFields' => ['whmcs_hosting_id' => (string) $h]]);
        $this->remote([self::customer('c1', $this->client)], [$old])
            ->on('PATCH', '/api/websites/w-old', 200, ['id' => 1, 'publicId' => 'w-old', 'customerId' => 1, 'name' => 'new.example', 'url' => 'https://new.example', 'status' => 'active']);

        $result = $this->reconciler()->run();

        self::assertSame([['url' => 'https://new.example', 'name' => 'new.example']], array_map(fn (array $r): array => self::body($r), $this->sent('PATCH', '/api/websites/w-old')));
        self::assertSame([], $this->sent('POST', '/api/websites'), 'no duplicate');
        self::assertSame([], $this->sent('DELETE', '#.#'), 'no delete, the history stays');
        self::assertSame([1, 0, 0, 0, 0], [$result->updated, $result->monitorsCreated, $result->paused, $result->deleted, $result->orphaned]);
        $row = self::row('hosting', $h, 'website');
        self::assertSame(['w-old', 'new.example', MonitorMap::STATE_ACTIVE, 'created'], [$row['monitor_public_id'], $row['target'], $row['state'], $row['origin']]);
        self::assertStringContainsString('updated=1', $result->summary());

        $this->remote([self::customer('c1', $this->client)], [self::website('w-old', 1, 'new.example', 'c1')]);
        $this->reconciler()->run();
        self::assertSame([], $this->sent('PATCH', '#.#'), 'in sync on the next run');
        self::assertSame([], $this->sent('POST', '#.#'));
    }

    public function testChangedHostingDomainDetachesAnAdoptedWebsiteAndCreatesANewOne(): void
    {
        $this->settings->set('on_terminate', 'delete');
        $h = $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'new.example', 'domainstatus' => 'Active']);
        self::mapRow(['origin' => 'adopted', 'kind' => 'hosting', 'whmcs_ref_id' => $h, 'monitor_type' => 'website', 'monitor_public_id' => 'w-old', 'target' => 'old.example']);
        $this->mapClient();
        $old = self::website('w-old', 1, 'old.example', 'c1', 'active', ['customFields' => ['whmcs_hosting_id' => (string) $h]]);
        $this->remote([self::customer('c1', $this->client)], [$old]);
        $this->createsWebsites();

        $result = $this->reconciler()->run();

        self::assertSame([], $this->sent('PATCH', '#.#'), 'an adopted website is never rewritten or paused');
        self::assertSame([], $this->sent('DELETE', '#.#'), 'nor deleted, even with on_terminate=delete');
        self::assertSame(['https://new.example'], array_map(fn (array $r): mixed => self::body($r)['url'], $this->sent('POST', '/api/websites')));
        self::assertSame([1, 1, 0], [$result->detached, $result->monitorsCreated, $result->adopted]);
        $row = self::row('hosting', $h, 'website');
        self::assertSame(['w1', 'new.example', 'created'], [$row['monitor_public_id'], $row['target'], $row['origin']]);

        // next run: the old website (still tagged with the hosting id) stays untouched and unmapped
        $this->remote([self::customer('c1', $this->client)], [$old, self::website('w1', 101, 'new.example', 'c1')]);
        $this->reconciler()->run();
        self::assertSame([], $this->sent('PATCH', '#.#'));
        self::assertSame([], $this->sent('DELETE', '#.#'));
        self::assertSame([], $this->sent('POST', '/api/websites'));
    }

    public function testChangedDomainOfACreatedWebsiteWithAMonitorOnTheNewTargetRetiresTheOldOneAndAdopts(): void
    {
        $this->settings->set('on_terminate', 'delete');
        $h = $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'new.example', 'domainstatus' => 'Active']);
        self::mapRow(['origin' => 'created', 'kind' => 'hosting', 'whmcs_ref_id' => $h, 'monitor_type' => 'website', 'monitor_public_id' => 'w-old', 'target' => 'old.example']);
        $this->mapClient();
        $this->remote([self::customer('c1', $this->client)], [self::website('w-old', 1, 'old.example', 'c1'), self::website('w-new', 2, 'new.example', 'c1')])
            ->on('DELETE', '/api/websites/w-old', 200, ['success' => true]);

        $result = $this->reconciler()->run();

        self::assertSame([], $this->sent('PATCH', '#.#'), 'pointing w-old at new.example would duplicate w-new');
        self::assertCount(1, $this->sent('DELETE', '/api/websites/w-old'));
        self::assertSame([1, 1, 0], [$result->deleted, $result->adopted, $result->monitorsCreated]);
        self::assertSame(['w-new', 'adopted'], [self::row('hosting', $h, 'website')['monitor_public_id'], self::row('hosting', $h, 'website')['origin']]);
    }

    public function testChangedDomainNeverRewritesAWebsiteAnotherRowStillUses(): void
    {
        $h1 = $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'new.example', 'domainstatus' => 'Active']);
        $h2 = $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'old.example', 'domainstatus' => 'Active']);
        self::mapRow(['origin' => 'created', 'kind' => 'hosting', 'whmcs_ref_id' => $h1, 'monitor_type' => 'website', 'monitor_public_id' => 'w-old', 'target' => 'old.example']);
        self::mapRow(['origin' => 'created', 'kind' => 'hosting', 'whmcs_ref_id' => $h2, 'monitor_type' => 'website', 'monitor_public_id' => 'w-old', 'target' => 'old.example']);
        $this->mapClient();
        $this->remote([self::customer('c1', $this->client)], [self::website('w-old', 1, 'old.example', 'c1')]);
        $this->createsWebsites();

        $result = $this->reconciler()->run();

        self::assertSame([], $this->sent('PATCH', '#.#'), 'hosting 2 still watches old.example through w-old');
        self::assertSame([], $this->sent('DELETE', '#.#'));
        self::assertSame(['https://new.example'], array_map(fn (array $r): mixed => self::body($r)['url'], $this->sent('POST', '/api/websites')));
        self::assertSame([0, 1], [$result->updated, $result->monitorsCreated]);
        self::assertSame('w1', self::row('hosting', $h1, 'website')['monitor_public_id']);
        self::assertSame(['w-old', MonitorMap::STATE_ACTIVE], [self::row('hosting', $h2, 'website')['monitor_public_id'], self::row('hosting', $h2, 'website')['state']]);
    }

    public function testRejectedUrlOnTheUpdateInPlaceMarksTheRowAndTheDailyRunRetriesIt(): void
    {
        $h = $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'moved.example', 'domainstatus' => 'Active']);
        self::mapRow(['origin' => 'created', 'kind' => 'hosting', 'whmcs_ref_id' => $h, 'monitor_type' => 'website', 'monitor_public_id' => 'w-old', 'target' => 'old.example']);
        $this->mapClient();
        $remote = fn () => $this->remote([self::customer('c1', $this->client)], [self::website('w-old', 1, 'old.example', 'c1')])
            ->on('PATCH', '/api/websites/w-old', 400, ['statusCode' => 400, 'statusMessage' => 'Monitor URL could not be resolved', 'data' => ['code' => 'invalidUrl']]);
        $remote();

        $result = $this->reconciler()->run();

        self::assertCount(1, $this->sent('PATCH', '/api/websites/w-old'));
        self::assertSame(['invalidUrl' => 1], array_count_values($result->rowErrors));
        $row = self::row('hosting', $h, 'website');
        self::assertSame(['w-old', 'moved.example', MonitorMap::STATE_ERROR, 'invalidUrl'], [$row['monitor_public_id'], $row['target'], $row['state'], $row['last_error']]);
        self::assertSame([], $this->sent('POST', '#.#'));
        self::assertFalse($result->aborted);

        $remote();
        $result = $this->reconciler()->run(20.0);
        self::assertSame([], $this->sent('PATCH', '#.#'), 'a budgeted admin run does not send it again');
        self::assertSame([], $result->rowErrors);

        $remote();
        $result = $this->reconciler()->run();
        self::assertCount(1, $this->sent('PATCH', '/api/websites/w-old'), 'the daily run tries the update in place again');
        self::assertSame(['hosting:' . $h . ':website' => 'invalidUrl'], $result->rowErrors);

        $this->remote([self::customer('c1', $this->client)], [self::website('w-old', 1, 'old.example', 'c1')])
            ->on('PATCH', '/api/websites/w-old', 200, ['id' => 1, 'publicId' => 'w-old', 'name' => 'moved.example', 'url' => 'https://moved.example', 'status' => 'active']);
        $result = $this->reconciler()->run();
        self::assertSame([1, []], [$result->updated, $result->rowErrors], 'resolves now: updated in place');
        $row = self::row('hosting', $h, 'website');
        self::assertSame(['w-old', 'moved.example', MonitorMap::STATE_ACTIVE, null], [$row['monitor_public_id'], $row['target'], $row['state'], $row['last_error']]);
    }

    public function testUpdateInPlaceKeepsTheAdminPause(): void
    {
        $h = $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'new.example', 'domainstatus' => 'Active']);
        self::mapRow(['origin' => 'created', 'admin_paused' => true, 'kind' => 'hosting', 'whmcs_ref_id' => $h, 'monitor_type' => 'website', 'monitor_public_id' => 'w-old', 'target' => 'old.example', 'state' => MonitorMap::STATE_PAUSED]);
        $this->mapClient();
        $this->remote([self::customer('c1', $this->client)], [self::website('w-old', 1, 'old.example', 'c1', 'inactive')])
            ->on('PATCH', '/api/websites/w-old', 200, ['id' => 1, 'publicId' => 'w-old', 'customerId' => 1, 'name' => 'new.example', 'url' => 'https://new.example', 'status' => 'inactive']);

        $result = $this->reconciler()->run();

        self::assertSame([['url' => 'https://new.example', 'name' => 'new.example']], array_map(fn (array $r): array => self::body($r), $this->sent('PATCH', '/api/websites/w-old')), 'no status change');
        self::assertSame([1, 0], [$result->updated, $result->resumed]);
        $row = self::row('hosting', $h, 'website');
        self::assertSame(['new.example', MonitorMap::STATE_PAUSED, true], [$row['target'], $row['state'], $row['admin_paused']]);
    }

    public function testChangedTargetOfACreatedDomainMonitorDeletesTheOldOneAndOfAnAdoptedOneDetachesIt(): void
    {
        $this->settings->setBool('sync_hosting_website', false);
        $this->settings->setBool('sync_hosting_domain_expiry', true);
        $created = $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'new-a.example', 'domainstatus' => 'Active']);
        $adopted = $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'new-b.example', 'domainstatus' => 'Active']);
        self::mapRow(['origin' => 'created', 'kind' => 'hosting', 'whmcs_ref_id' => $created, 'monitor_type' => 'customer_domain', 'monitor_public_id' => 'd-a', 'target' => 'old-a.example']);
        self::mapRow(['origin' => 'adopted', 'kind' => 'hosting', 'whmcs_ref_id' => $adopted, 'monitor_type' => 'customer_domain', 'monitor_public_id' => 'd-b', 'target' => 'old-b.example']);
        $this->mapClient();
        $this->remote([self::customer('c1', $this->client)], [], [self::domain('d-a', 1, 'old-a.example', 'c1'), self::domain('d-b', 2, 'old-b.example', 'c1')])
            ->on('DELETE', '/api/customer-domains/d-a', 200, ['success' => true])
            ->on('POST', '#^/api/customers/c1/domains$#', 200, ['id' => 21, 'publicId' => 'd-new', 'customerId' => 1, 'domainName' => 'new.example', 'label' => 'new.example', 'status' => 'active', 'deduplicated' => false]);

        $result = $this->reconciler()->run();

        self::assertCount(1, $this->sent('DELETE', '/api/customer-domains/d-a'));
        self::assertSame([], $this->sent('DELETE', '/api/customer-domains/d-b'), 'adopted: never deleted');
        self::assertSame([1, 1, 2], [$result->deleted, $result->detached, $result->monitorsCreated]);
    }

    public function testChangedTargetWithUnconfirmedOwnerLeavesOldMonitorAloneAndCreatesNew(): void
    {
        $h = $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'new.example', 'domainstatus' => 'Active']);
        self::mapRow(['kind' => 'hosting', 'whmcs_ref_id' => $h, 'monitor_type' => 'website', 'monitor_public_id' => 'w-old', 'target' => 'old.example']);
        $this->mapClient();
        $this->remote([self::customer('c1', $this->client)], [self::website('w-old', 1, 'old.example', '')])
            ->on('PATCH', '/api/websites/w-old', 200, self::website('w-old', 1, 'old.example', '', 'inactive'));
        $this->createsWebsites();

        $result = $this->reconciler()->run();

        self::assertSame([], $this->sent('PATCH', '/api/websites/w-old'), 'no pause without a confirmed owner');
        self::assertSame([], $this->sent('DELETE', '#.#'));
        self::assertSame(['https://new.example'], array_map(fn (array $r): mixed => self::body($r)['url'], $this->sent('POST', '/api/websites')));
        self::assertSame([0, 0, 1], [$result->paused, $result->deleted, $result->monitorsCreated]);
        $row = self::row('hosting', $h, 'website');
        self::assertSame(['w1', 'new.example', MonitorMap::STATE_ACTIVE], [$row['monitor_public_id'], $row['target'], $row['state']]);
    }

    public function testChangedTargetWithUnconfirmedOwnerNeverDeletesAndAdoptsTheNewTarget(): void
    {
        $d = $this->insert('tbldomains', ['userid' => $this->client, 'domain' => 'new.example', 'status' => 'Active']);
        self::mapRow(['kind' => 'domain', 'whmcs_ref_id' => $d, 'monitor_type' => 'customer_domain', 'monitor_public_id' => 'd-old', 'target' => 'old.example']);
        $this->mapClient();
        $this->remote([self::customer('c1', $this->client)], [], [
            self::domain('d-old', 2, 'old.example', ''),
            self::domain('d-new', 3, 'new.example', 'c1'),
        ])->on('DELETE', '/api/customer-domains/d-old', 200, ['success' => true]);

        $result = $this->reconciler()->run();

        self::assertSame([], $this->sent('DELETE', '#.#'), 'no delete without a confirmed owner');
        self::assertSame(0, $result->deleted);
        self::assertSame(1, $result->adopted);
        self::assertSame('d-new', self::row('domain', $d, 'customer_domain')['monitor_public_id'], 'the new target is still adopted');
    }

    public function testUnconfirmedOwnerDetachRemembersOriginAndAdminPause(): void
    {
        $h = $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'new.example', 'domainstatus' => 'Active']);
        self::mapRow(['origin' => 'created', 'admin_paused' => true, 'kind' => 'hosting', 'whmcs_ref_id' => $h, 'monitor_type' => 'website', 'monitor_public_id' => 'w-old', 'target' => 'old.example', 'state' => MonitorMap::STATE_PAUSED]);
        $this->mapClient();
        $this->remote([self::customer('c1', $this->client)], [self::website('w-old', 1, 'old.example', '', 'inactive')])
            ->on('PATCH', '#^/api/websites/w\d+$#', 200, ['status' => 'inactive']);
        $this->createsWebsites();

        $this->reconciler()->run();

        self::assertSame([], $this->sent('PATCH', '/api/websites/w-old'));
        self::assertSame(['origin' => 'created', 'admin_paused' => true], (new DetachedMonitors())->find('website', 'w-old'), 'a later re-adoption keeps created and the admin pause');
    }

    public function testMappedMonitorWithoutAListedOwnerIsNeitherResumedNorPausedAgain(): void
    {
        $h1 = $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'one.example', 'domainstatus' => 'Active']);
        $h2 = $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'two.example', 'domainstatus' => 'Active']);
        self::mapRow(['origin' => 'created', 'kind' => 'hosting', 'whmcs_ref_id' => $h1, 'monitor_type' => 'website', 'monitor_public_id' => 'w1', 'target' => 'one.example', 'state' => MonitorMap::STATE_PAUSED]);
        self::mapRow(['origin' => 'created', 'admin_paused' => true, 'kind' => 'hosting', 'whmcs_ref_id' => $h2, 'monitor_type' => 'website', 'monitor_public_id' => 'w2', 'target' => 'two.example', 'state' => MonitorMap::STATE_PAUSED]);
        $this->mapClient();
        $this->remote([self::customer('c1', $this->client)], [self::website('w1', 1, 'one.example', '', 'inactive'), self::website('w2', 2, 'two.example', '', 'active')])
            ->on('PATCH', '#^/api/websites/#', 200, ['status' => 'active']);

        $result = $this->reconciler()->run();

        self::assertSame([], $this->sent('PATCH', '#.#'), 'no status change without a confirmed owner');
        self::assertSame([0, 0], [$result->resumed, $result->paused]);
        self::assertSame(MonitorMap::STATE_PAUSED, self::row('hosting', $h1, 'website')['state']);
        self::assertTrue(self::row('hosting', $h2, 'website')['admin_paused'], 'the admin pause stays recorded');
    }

    public function testPausingRowNextToADetachedRowIsNotStoredPausedWhileTheMonitorStaysActive(): void
    {
        $this->settings->set('on_terminate', 'pause');
        $ended = $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'site.example', 'domainstatus' => 'Terminated']);
        $live = $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'site.example', 'domainstatus' => 'Active']);
        self::mapRow(['origin' => 'created', 'kind' => 'hosting', 'whmcs_ref_id' => $ended, 'monitor_type' => 'website', 'monitor_public_id' => 'w1', 'target' => 'site.example']);
        self::mapRow(['origin' => 'created', 'kind' => 'hosting', 'whmcs_ref_id' => $live, 'monitor_type' => 'website', 'monitor_public_id' => 'w1', 'target' => 'site.example']);
        $this->mapClient();
        $this->settings->setBool('sync_hosting_website', false);
        $this->remote([self::customer('c1', $this->client)], [self::website('w1', 1, 'site.example', 'c1')]);

        $result = $this->reconciler()->run();

        self::assertSame([], $this->sent('PATCH', '#.#'), 'the detached row leaves the monitor untouched for the group');
        self::assertSame(1, $result->detached);
        self::assertSame(MonitorMap::STATE_ACTIVE, self::row('hosting', $ended, 'website')['state'], 'not shown paused while the monitor is active');
    }

    public function testWebsiteUrlEditedInUptimeifyIsPointedBackInPlace(): void
    {
        $h = $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'new.example', 'domainstatus' => 'Active']);
        self::mapRow(['origin' => 'created', 'kind' => 'hosting', 'whmcs_ref_id' => $h, 'monitor_type' => 'website', 'monitor_public_id' => 'w-old', 'target' => 'new.example']);
        $this->mapClient();
        $this->remote([self::customer('c1', $this->client)], [self::website('w-old', 1, 'edited.example', 'c1')])
            ->on('PATCH', '/api/websites/w-old', 200, ['id' => 1, 'publicId' => 'w-old', 'name' => 'new.example', 'url' => 'https://new.example', 'status' => 'active']);
        $this->createsWebsites();

        $this->reconciler()->run();

        self::assertSame([['url' => 'https://new.example', 'name' => 'new.example']], array_map(fn (array $r): array => self::body($r), $this->sent('PATCH', '/api/websites/w-old')), 'the map target alone is not trusted, the remote URL is');
        self::assertSame([], $this->sent('POST', '#.#'));
        self::assertSame('w-old', self::row('hosting', $h, 'website')['monitor_public_id']);
    }

    public function testChangedHostingDomainKeepsOldDomainMonitorPlannedByARegistrarDomain(): void
    {
        $this->settings->setBool('sync_hosting_domain_expiry', true);
        $h = $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'new.example', 'domainstatus' => 'Active']);
        $d = $this->insert('tbldomains', ['userid' => $this->client, 'domain' => 'old.example', 'status' => 'Active']);
        self::mapRow(['origin' => 'created', 'kind' => 'hosting', 'whmcs_ref_id' => $h, 'monitor_type' => 'customer_domain', 'monitor_public_id' => 'd-old', 'target' => 'old.example']);
        self::mapRow(['origin' => 'created', 'kind' => 'hosting', 'whmcs_ref_id' => $h, 'monitor_type' => 'website', 'monitor_public_id' => 'w-new', 'target' => 'new.example']);
        $this->mapClient();
        $this->remote([self::customer('c1', $this->client)], [self::website('w-new', 1, 'new.example', 'c1')], [self::domain('d-old', 2, 'old.example', 'c1')])
            ->on('POST', '/api/customers/c1/domains', 200, ['id' => 21, 'publicId' => 'd-new', 'customerId' => 1, 'domainName' => 'new.example', 'label' => 'new.example', 'status' => 'active', 'deduplicated' => false]);

        $result = $this->reconciler()->run();

        self::assertSame([], $this->sent('DELETE', '#.#'), 'the registrar domain old.example still wants that monitor');
        self::assertSame('d-new', self::row('hosting', $h, 'customer_domain')['monitor_public_id']);
        self::assertSame('d-old', self::row('domain', $d, 'customer_domain')['monitor_public_id'], 'adopted by the registrar domain');
        self::assertSame([1, 1], [$result->monitorsCreated, $result->adopted]);
    }

    // ---- §8.3 step 4: orphans -----------------------------------------------------------

    public function testMissingRemoteMonitorIsOrphanedFirstAndOnlyRemovedOrRecreatedInTheNextRun(): void
    {
        $planned = $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'gone.example', 'domainstatus' => 'Active']);
        $ended = $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'ended.example', 'domainstatus' => 'Terminated']);
        $back = $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'back.example', 'domainstatus' => 'Active']);
        self::mapRow(['kind' => 'hosting', 'whmcs_ref_id' => $planned, 'monitor_type' => 'website', 'monitor_public_id' => 'wgone', 'target' => 'gone.example']);
        self::mapRow(['kind' => 'hosting', 'whmcs_ref_id' => $ended, 'monitor_type' => 'website', 'monitor_public_id' => 'wended', 'target' => 'ended.example']);
        self::mapRow(['kind' => 'hosting', 'whmcs_ref_id' => $back, 'monitor_type' => 'website', 'monitor_public_id' => 'wback', 'target' => 'back.example', 'state' => MonitorMap::STATE_ORPHANED]);
        $this->mapClient();
        $this->remote([self::customer('c1', $this->client)], [self::website('wback', 3, 'back.example', 'c1')]);
        $this->createsWebsites();

        $result = $this->reconciler()->run();

        self::assertSame(2, $result->orphaned);
        self::assertSame(0, $result->monitorsCreated, 'a row missing in one list walk is not recreated yet (offset paging can skip rows)');
        self::assertSame([], $this->sent('POST', '/api/websites'));
        self::assertSame([], $this->sent('PATCH', '#.#'));
        self::assertSame(MonitorMap::STATE_ORPHANED, self::row('hosting', $planned, 'website')['state']);
        self::assertSame(MonitorMap::STATE_ORPHANED, self::row('hosting', $ended, 'website')['state']);
        self::assertSame(MonitorMap::STATE_ACTIVE, self::row('hosting', $back, 'website')['state'], 'an orphan that is listed again is active again');

        $this->remote([self::customer('c1', $this->client)], [self::website('wback', 3, 'back.example', 'c1')]);
        $this->createsWebsites();
        $result = $this->reconciler()->run();

        self::assertSame(1, $result->monitorsCreated, 'still missing in the second run: recreated');
        self::assertSame('w1', self::row('hosting', $planned, 'website')['monitor_public_id']);
        self::assertNull((new MonitorMap())->find('hosting', $ended, 'website'), 'still missing in the second run: removed');
        self::assertSame([], $this->sent('DELETE', '#.#'));
        self::assertSame([], $this->sent('PATCH', '#.#'));
    }

    public function testNotFoundOnPauseMarksRowOrphanedNotError(): void
    {
        $h = $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'sus.example', 'domainstatus' => 'Suspended']);
        self::mapRow(['kind' => 'hosting', 'whmcs_ref_id' => $h, 'monitor_type' => 'website', 'monitor_public_id' => 'ws', 'target' => 'sus.example']);
        $this->mapClient();
        $this->remote([self::customer('c1', $this->client)], [self::website('ws', 1, 'sus.example', 'c1')])
            ->on('PATCH', '/api/websites/ws', 404, ['statusCode' => 404, 'statusMessage' => 'Website not found', 'data' => ['code' => 'websiteNotFound']]);

        $result = $this->reconciler()->run();

        self::assertSame(MonitorMap::STATE_ORPHANED, self::row('hosting', $h, 'website')['state']);
        self::assertSame(1, $result->orphaned);
        self::assertSame(0, $result->errors);
    }

    // ---- §8.3 steps 1, 8 and failures that abort -----------------------------------------

    public function testRateLimitSleepsRetryAfterCappedThenAbortsAndLeavesTheRest(): void
    {
        $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'a.example', 'domainstatus' => 'Active']);
        $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'b.example', 'domainstatus' => 'Active']);
        $this->mapClient();
        $this->remote([self::customer('c1', $this->client)])
            ->on('POST', '/api/websites', 429, ['statusCode' => 429, 'statusMessage' => 'Too Many Requests', 'data' => ['retryAfter' => 120]], ['Retry-After' => '120']);

        $result = $this->reconciler()->run();

        self::assertTrue($result->aborted);
        self::assertSame('rate_limited', $result->abortReason);
        self::assertSame([60, 60, 60], $this->sleeps);
        self::assertCount(4, $this->sent('POST', '/api/websites'), 'one call plus three retries, then the second hosting is left for the next run');
        self::assertSame(0, (new Queue())->counts()['pending'], 'a rate-limited run enqueues nothing');
        self::assertTrue((new StatusCache())->value('last_reconcile')['aborted'] ?? false);
        self::assertTrue((new SyncLock())->acquire(), 'lock released');
        (new SyncLock())->release();
    }

    public function testUnauthorizedResetsConnectionAndAbortsCleanly(): void
    {
        $this->settings->setBool('connection_validated', true);
        $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'a.example', 'domainstatus' => 'Active']);
        $this->mapClient();
        $this->remote([self::customer('c1', $this->client)])
            ->on('POST', '/api/websites', 401, ['statusCode' => 401, 'statusMessage' => 'Unauthorized']);

        $result = $this->reconciler()->run();

        self::assertTrue($result->aborted);
        self::assertSame('unauthorized', $result->abortReason);
        self::assertFalse((new Settings())->getBool('connection_validated'));
        self::assertSame([], $this->sent('GET', '/api/incidents'), 'no further calls after 401');
        self::assertTrue((new SyncLock())->acquire(), 'lock released');
        (new SyncLock())->release();
    }

    public function testHeldLockAbortsWithLogLine(): void
    {
        $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'a.example', 'domainstatus' => 'Active']);
        $lock = new SyncLock();
        self::assertTrue($lock->acquire());
        try {
            $result = $this->reconciler()->run();
        } finally {
            $lock->release();
        }

        self::assertTrue($result->aborted);
        self::assertSame('locked', $result->abortReason);
        self::assertSame([], $this->http->requests());
        self::assertNotSame([], array_filter(array_column($GLOBALS['uptimeify_test']['activity'], 0), fn (string $m): bool => str_contains($m, 'lock')));
    }

    public function testRunStopsBeforeAnyWorkWhenTheTokenBelongsToAnotherOrganization(): void
    {
        $h = $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'ended.example', 'domainstatus' => 'Terminated']);
        self::mapRow(['kind' => 'hosting', 'whmcs_ref_id' => $h, 'monitor_type' => 'website', 'monitor_public_id' => 'w1', 'target' => 'ended.example']);
        $this->mapClient();
        $this->settings->set('organization_public_id', 'o-bound');
        $this->remote([self::customer('c1', $this->client)]);

        $result = $this->reconciler()->run();

        self::assertTrue($result->aborted);
        self::assertSame(Reconciler::ABORT_ORGANIZATION, $result->abortReason);
        self::assertSame(['GET /api/organization'], array_map(fn (array $r): string => $r['method'] . ' ' . parse_url($r['url'], PHP_URL_PATH), $this->http->requests()), 'one cheap request, nothing else');
        self::assertSame(MonitorMap::STATE_ACTIVE, self::row('hosting', $h, 'website')['state'], 'no row touched');
        self::assertSame(['publicId' => 'o1', 'name' => 'Acme Org'], (new Settings())->organizationMismatch());
        self::assertNull((new StatusCache())->value('org'), 'no snapshot of another organization');
        self::assertSame(true, (new StatusCache())->value('last_reconcile')['aborted'] ?? null);
        self::assertTrue((new SyncLock())->acquire(), 'the lock is released');
        (new SyncLock())->release();
    }

    public function testFirstRunBindsTheOrganization(): void
    {
        $this->mapClient();
        $this->remote([self::customer('c1', $this->client)]);
        $this->reconciler()->run();
        self::assertSame('o1', (new Settings())->organizationPublicId());
        self::assertCount(1, $this->sent('GET', '/api/organization'), 'verified once per run');
    }

    public function testAReconcileReadsTheDetachedRecordsOnceInsteadOfPerRow(): void
    {
        $websites = [];
        foreach (['a', 'b', 'c'] as $i => $name) {
            $h = $this->insert('tblhosting', ['userid' => $this->client, 'domain' => $name . '.example', 'domainstatus' => 'Active']);
            self::mapRow(['kind' => 'hosting', 'whmcs_ref_id' => $h, 'monitor_type' => 'website', 'monitor_public_id' => 'w' . $name, 'target' => $name . '.example']);
            $websites[] = self::website('w' . $name, $i + 1, $name . '.example', 'c1');
        }
        $this->mapClient();
        $this->remote([self::customer('c1', $this->client)], $websites);
        $connection = \WHMCS\Database\Capsule::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        $this->reconciler()->run();

        $queries = array_values(array_filter(array_column($connection->getQueryLog(), 'query'), static fn (string $q): bool => str_contains($q, 'mod_uptimeify_detached')));
        $connection->disableQueryLog();
        self::assertCount(1, $queries, 'one SELECT for the run, no DELETE for monitors without a record: ' . implode(' | ', $queries));
    }

    public function testRemoteListFailureAbortsWithoutTouchingAnything(): void
    {
        $h = $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'ended.example', 'domainstatus' => 'Terminated']);
        self::mapRow(['kind' => 'hosting', 'whmcs_ref_id' => $h, 'monitor_type' => 'website', 'monitor_public_id' => 'w1', 'target' => 'ended.example']);
        $this->mapClient();
        $this->http = (new FakeHttpClient())
            ->on('GET', '/api/organization', 200, ['id' => 7, 'publicId' => 'o1', 'name' => 'Acme Org'])
            ->on('GET', '/api/customers', 200, self::page([self::customer('c1', $this->client)]))
            ->on('GET', '/api/websites', 200, ['unexpected' => true]);

        $result = $this->reconciler()->run();

        self::assertTrue($result->aborted);
        self::assertSame('api', $result->abortReason);
        self::assertSame(MonitorMap::STATE_ACTIVE, self::row('hosting', $h, 'website')['state'], 'no orphan marking from a broken list');
        self::assertSame(['/api/organization', '/api/customers', '/api/websites'], array_map(fn (array $r): string => (string) parse_url($r['url'], PHP_URL_PATH), $this->http->requests()));
    }

    // ---- §9.1: time budget -----------------------------------------------------------------

    public function testTimeBudgetEnqueuesTheRemainingWorkForTheCron(): void
    {
        $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'a.example', 'domainstatus' => 'Active']);
        $b = $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'b.example', 'domainstatus' => 'Active']);
        $synced = $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'synced.example', 'domainstatus' => 'Active']);
        $d = $this->insert('tbldomains', ['userid' => $this->client, 'domain' => 'reg.example', 'status' => 'Active']);
        $ended = $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'ended.example', 'domainstatus' => 'Terminated']);
        self::mapRow(['kind' => 'hosting', 'whmcs_ref_id' => $synced, 'monitor_type' => 'website', 'monitor_public_id' => 'ws', 'target' => 'synced.example']);
        self::mapRow(['kind' => 'hosting', 'whmcs_ref_id' => $ended, 'monitor_type' => 'website', 'monitor_public_id' => 'we', 'target' => 'ended.example']);
        $this->mapClient();
        $this->remote([self::customer('c1', $this->client)], [self::website('ws', 1, 'synced.example', 'c1'), self::website('we', 2, 'ended.example', 'c1')]);
        $this->createsWebsites();
        $existingJob = (new Queue())->push('hosting.activate', $b);
        $t = 0.0;

        $result = $this->reconciler(function () use (&$t): float {
            return $t += 15.0;
        })->run(20.0);

        self::assertTrue($result->aborted);
        self::assertSame('time_budget', $result->abortReason);
        self::assertSame(1, $result->monitorsCreated);
        $jobs = array_map(fn (array $j): string => $j['job'] . ':' . $j['ref_id'], (new Queue())->due(50));
        self::assertSame(['hosting.activate:' . $b, 'domain.upsert:' . $d, 'hosting.remove:' . $ended], $jobs, 'work in sync is not queued');
        self::assertSame($existingJob, (new Queue())->due(50)[0]['id'], 'an already pending job is reused');
        self::assertSame(2, $result->queued, 'only new queue rows count');
        self::assertStringContainsString('queued=2', $result->summary());
        self::assertNotSame([], array_filter($result->messages, fn (string $m): bool => str_contains($m, 'cron')));
        self::assertSame([], $this->sent('PATCH', '#.#'));
        self::assertNotNull((new StatusCache())->value('org'), 'the cache is still written');
    }

    public function testRateLimitInABudgetedRunCountsAsBudgetUsedUpAndQueuesTheRest(): void
    {
        $a = $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'a.example', 'domainstatus' => 'Active']);
        $b = $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'b.example', 'domainstatus' => 'Active']);
        $d = $this->insert('tbldomains', ['userid' => $this->client, 'domain' => 'reg.example', 'status' => 'Active']);
        $this->mapClient();
        $this->remote([self::customer('c1', $this->client)])
            ->on('POST', '/api/websites', 429, ['statusCode' => 429, 'statusMessage' => 'Too Many Requests'], ['Retry-After' => '30']);

        $result = $this->reconciler()->run(20.0);

        self::assertTrue($result->aborted);
        self::assertSame('time_budget', $result->abortReason, 'spec 9.1: the admin run stops and leaves the rest to the cron');
        self::assertCount(4, $this->sent('POST', '/api/websites'), 'the guard retries the first create, nothing after it');
        $jobs = array_map(fn (array $j): string => $j['job'] . ':' . $j['ref_id'], (new Queue())->due(50));
        self::assertSame(['hosting.activate:' . $a, 'hosting.activate:' . $b, 'domain.upsert:' . $d], $jobs);
        self::assertSame(3, $result->queued);
        self::assertNotSame([], array_filter($result->messages, fn (string $m): bool => str_contains($m, 'rate limited') && str_contains($m, 'cron')));
        self::assertNotNull((new StatusCache())->value('org'), 'the cache is still written');
        self::assertTrue((new SyncLock())->acquire(), 'lock released');
        (new SyncLock())->release();
    }

    public function testRefreshCacheWritesTheRemoteCustomerListForTheAdminPicker(): void
    {
        $this->remote([self::customer('c1', $this->client), self::customer('c2', null, 'Other', 'other@example.com')]);

        $this->reconciler()->run();

        self::assertSame([
            ['publicId' => 'c1', 'name' => 'Acme', 'email' => 'acme@example.com', 'whmcsClientId' => $this->client],
            ['publicId' => 'c2', 'name' => 'Other', 'email' => 'other@example.com', 'whmcsClientId' => null],
        ], (new StatusCache())->value('org_customers'));
    }

    public function testTimeBudgetDoesNotQueueWorkThatCannotSucceed(): void
    {
        $bad = $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'localhost', 'domainstatus' => 'Active']);
        self::mapRow(['kind' => 'hosting', 'whmcs_ref_id' => $bad, 'monitor_type' => 'website', 'monitor_public_id' => null, 'target' => 'localhost', 'state' => MonitorMap::STATE_ERROR, 'last_error' => 'invalidUrl']);
        $this->mapClient('c1');
        $twinA = $this->insert('tblclients', ['companyname' => 'Twin A', 'email' => 'a@example.com']);
        $twinB = $this->insert('tblclients', ['companyname' => 'Twin B', 'email' => 'b@example.com']);
        $this->insert('tblhosting', ['userid' => $twinA, 'domain' => 'twin.example', 'domainstatus' => 'Active']);
        $this->mapClient('c2', $twinA);
        $this->mapClient('c2', $twinB);
        $this->remote([self::customer('c1', $this->client), self::customer('c2', null, 'Twin', 't@example.com')]);
        $t = 0.0;

        $result = $this->reconciler(function () use (&$t): float {
            return $t += 1.0;
        })->run(0.5);

        self::assertSame('time_budget', $result->abortReason);
        self::assertSame([], (new Queue())->due(50), 'invalidUrl with the same target and a customer conflict would only fail again');
        self::assertSame(0, $result->queued);
    }

    // ---- §8.3 steps 9, 10: status cache ------------------------------------------------------

    public function testRefreshCacheWritesOrgAndClientSnapshots(): void
    {
        $h = $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'site.example', 'domainstatus' => 'Active']);
        $d = $this->insert('tbldomains', ['userid' => $this->client, 'domain' => 'reg.example', 'status' => 'Active']);
        $this->mapClient();
        self::mapRow(['kind' => 'hosting', 'whmcs_ref_id' => $h, 'monitor_type' => 'website', 'monitor_public_id' => 'w1', 'target' => 'site.example']);
        self::mapRow(['kind' => 'domain', 'whmcs_ref_id' => $d, 'monitor_type' => 'customer_domain', 'monitor_public_id' => 'd1', 'target' => 'reg.example']);
        $this->remote(
            [self::customer('c1', $this->client)],
            [self::website('w1', 1, 'site.example', 'c1', 'active', ['isDown' => true, 'uptimeDay' => '97.50', 'uptimeMonth' => '99.10', 'uptimeYear' => '99.90', 'avgResponseDay' => 321])],
            [self::domain('d1', 2, 'reg.example', 'c1', ['expiry' => ['domainExpiresAt' => '2027-01-01T00:00:00.000Z', 'domainRegistrar' => 'Example Registrar', 'isExpired' => false]])],
            incidents: [
                ['id' => 1, 'publicId' => 'i1', 'status' => 'open', 'title' => 'HTTP 503', 'started_at' => '2026-09-13T10:00:00Z', 'resolved_at' => null, 'website' => ['publicId' => 'w1', 'name' => 'site.example'], 'monitor' => null, 'customer' => ['publicId' => 'c1']],
                ['id' => 2, 'publicId' => 'i2', 'status' => 'resolved', 'title' => 'old', 'started_at' => '2026-09-01T10:00:00Z', 'resolved_at' => '2026-09-01T11:00:00Z', 'website' => ['publicId' => 'w1'], 'monitor' => null, 'customer' => ['publicId' => 'c1']],
            ],
        );

        $this->reconciler()->run();

        $cache = new StatusCache();
        $org = $cache->value('org');
        self::assertNotNull($org);
        self::assertSame([2, 1, 1, 0, 1, true], [$org['total'], $org['up'], $org['down'], $org['paused'], $org['incidents'], $org['incidentsAvailable']]);
        $client = $cache->value('client:' . $this->client);
        self::assertNotNull($client);
        self::assertSame('c1', $client['customerPublicId']);
        self::assertSame(['total' => 2, 'up' => 1, 'down' => 1, 'paused' => 0], $client['totals']);
        $byId = array_column($client['monitors'], null, 'publicId');
        self::assertSame('down', $byId['w1']['status']);
        self::assertSame(97.5, $byId['w1']['uptimeDay']);
        self::assertEquals(321, $byId['w1']['avgResponseDay'], 'JSON in the cache drops the .0 of a whole float');
        self::assertSame(1, $byId['w1']['openIncidents']);
        self::assertSame(['2027-01-01T00:00:00.000Z', 'Example Registrar', 'up'], [$byId['d1']['expiresAt'], $byId['d1']['registrar'], $byId['d1']['status']]);
        self::assertSame(['HTTP 503'], array_column($client['incidents'], 'title'));
        self::assertSame('2026-09-13T10:00:00Z', $client['incidents'][0]['startedAt']);
        self::assertArrayHasKey('summary', $cache->value('last_reconcile') ?? []);
        self::assertCount(1, $this->sent('GET', '/api/incidents'), 'incidents are loaded once per run');
    }

    public function testIncidentFailureDoesNotAbortTheReconcile(): void
    {
        $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'site.example', 'domainstatus' => 'Active']);
        $this->mapClient();
        $this->http = (new FakeHttpClient())
            ->on('GET', '/api/organization', 200, ['id' => 7, 'publicId' => 'o1', 'name' => 'Acme Org'])
            ->on('GET', '/api/incidents', 200, ['unexpected' => true])
            ->on('GET', '/api/customers', 200, self::page([self::customer('c1', $this->client)]))
            ->on('GET', '/api/websites', 200, self::page([]))
            ->on('GET', '/api/tags', 200, [['id' => 1, 'publicId' => 't1', 'name' => 'whmcs', 'customerId' => null]])
            ->on('POST', '/api/monitor-tags', 200, ['id' => 1]);
        $this->createsWebsites();

        $result = $this->reconciler()->run();

        self::assertFalse($result->aborted);
        self::assertSame(1, $result->monitorsCreated);
        $org = (new StatusCache())->value('org');
        self::assertNotNull($org);
        self::assertFalse($org['incidentsAvailable']);
        self::assertSame(1, $org['total']);
        self::assertNotSame([], array_filter($result->messages, fn (string $m): bool => str_contains($m, 'incidents')));
    }

    public function testCronRunReadsOnlyTheBoundedIncidentWindowAndFlagsItIncomplete(): void
    {
        $h = $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'site.example', 'domainstatus' => 'Active']);
        $this->mapClient();
        self::mapRow(['kind' => 'hosting', 'whmcs_ref_id' => $h, 'monitor_type' => 'website', 'monitor_public_id' => 'w1', 'target' => 'site.example']);
        $this->remote([self::customer('c1', $this->client)], [self::website('w1', 1, 'site.example', 'c1')]);
        $this->intercept(static function (string $method, string $path): ?HttpResponse {
            static $offset = 0;
            if ($path !== '/api/incidents') {
                return null;
            }
            $items = [['id' => $offset + 1, 'publicId' => 'i' . $offset, 'status' => 'open', 'started_at' => '2026-09-13T10:00:00Z', 'resolved_at' => null, 'website' => ['publicId' => 'w1'], 'customer' => ['publicId' => 'c1']]];
            $body = ['incidents' => $items, 'total' => 50_000, 'limit' => 500, 'offset' => $offset];
            $offset++;
            return new HttpResponse(200, [], (string) json_encode($body));
        });

        $result = $this->reconciler()->run();

        self::assertFalse($result->aborted);
        self::assertCount(IncidentWindow::PAGES, $this->sent('GET', '/api/incidents'), 'never more than the bounded window');
        $org = (new StatusCache())->value('org');
        self::assertNotNull($org);
        self::assertSame([true, false, IncidentWindow::PAGES], [$org['incidentsAvailable'], $org['incidentsComplete'], $org['incidents']]);
        $client = (new StatusCache())->value('client:' . $this->client);
        self::assertNotNull($client);
        self::assertFalse($client['incidentsComplete']);
    }

    public function testCompleteIncidentWalkFlagsTheSnapshotsComplete(): void
    {
        $this->mapClient();
        $this->remote([self::customer('c1', $this->client)]);
        $this->reconciler()->run();
        $org = (new StatusCache())->value('org');
        self::assertNotNull($org);
        self::assertTrue($org['incidentsComplete']);
    }

    public function testBudgetedRunNeverWalksIncidentsAndKeepsTheStoredOpenIncidents(): void
    {
        $h = $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'site.example', 'domainstatus' => 'Active']);
        $this->mapClient();
        self::mapRow(['kind' => 'hosting', 'whmcs_ref_id' => $h, 'monitor_type' => 'website', 'monitor_public_id' => 'w1', 'target' => 'site.example']);
        $cache = new StatusCache();
        $cache->set('org', ['total' => 1, 'up' => 1, 'down' => 0, 'paused' => 0, 'incidents' => 1, 'incidentsAvailable' => true, 'incidentsComplete' => false, 'refreshedAt' => '2026-09-13 00:00:00']);
        $cache->set('client:' . $this->client, ['customerPublicId' => 'c1', 'refreshedAt' => '2026-09-13 09:00:00', 'incidents' => [
            ['publicId' => 'i1', 'title' => 'HTTP 503', 'status' => 'open', 'startedAt' => '2026-09-13T10:00:00Z', 'monitorPublicId' => 'w1', 'monitorName' => 'site', 'customerPublicId' => 'c1'],
        ]]);
        $this->remote([self::customer('c1', $this->client)], [self::website('w1', 1, 'site.example', 'c1', 'active', ['isDown' => true])]);

        $result = $this->reconciler()->run(20.0);

        self::assertFalse($result->aborted);
        self::assertSame([], $this->sent('GET', '/api/incidents'), 'no incident walk inside a budgeted admin request');
        $org = $cache->value('org');
        self::assertNotNull($org);
        self::assertSame([1, 1, true, false], [$org['down'], $org['incidents'], $org['incidentsAvailable'], $org['incidentsComplete']], 'counts from the lists, incidents as stored');
        $client = $cache->value('client:' . $this->client);
        self::assertNotNull($client);
        self::assertSame(['i1'], array_column($client['incidents'], 'publicId'));
        self::assertSame(1, $client['monitors'][0]['openIncidents']);
        self::assertSame('2026-09-13 09:00:00', $client['incidentsRefreshedAt'], 'the kept incidents keep the time they were read');
        self::assertSame('2026-09-13 00:00:00', $org['incidentsRefreshedAt']);
    }

    public function testCronRunStampsTheIncidentsItRead(): void
    {
        $this->mapClient();
        $this->remote([self::customer('c1', $this->client)]);
        $this->reconciler()->run();
        $client = (new StatusCache())->value('client:' . $this->client);
        self::assertNotNull($client);
        self::assertSame($client['refreshedAt'], $client['incidentsRefreshedAt']);
    }

    public function testBudgetedRunWithoutStoredIncidentsFlagsThemUnavailable(): void
    {
        $this->mapClient();
        $this->remote([self::customer('c1', $this->client)]);
        $this->reconciler()->run(20.0);
        self::assertSame([], $this->sent('GET', '/api/incidents'));
        $org = (new StatusCache())->value('org');
        self::assertNotNull($org);
        self::assertFalse($org['incidentsAvailable'], 'never shown as "no incidents"');
    }

    private function closeClient(): void
    {
        \WHMCS\Database\Capsule::table('tblclients')->where('id', $this->client)->update(['status' => 'Closed']);
    }

    /**
     * @param list<mixed> $values
     * @return list<mixed>
     */
    private static function sorted(array $values): array
    {
        sort($values);
        return $values;
    }
}
