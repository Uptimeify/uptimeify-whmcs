<?php

declare(strict_types=1);

namespace Uptimeify\Tests\Unit\Client;

use Uptimeify\Tests\Fake\FakeHttpClient;
use Uptimeify\Tests\Support\DbTestCase;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\Uptimeify\Api\Client;
use WHMCS\Module\Addon\Uptimeify\Client\ClientSnapshot;
use WHMCS\Module\Addon\Uptimeify\Container;
use WHMCS\Module\Addon\Uptimeify\Http\HttpResponse;
use WHMCS\Module\Addon\Uptimeify\Settings;
use WHMCS\Module\Addon\Uptimeify\Store\CustomerMap;
use WHMCS\Module\Addon\Uptimeify\Store\MonitorMap;
use WHMCS\Module\Addon\Uptimeify\Store\Schema;
use WHMCS\Module\Addon\Uptimeify\Store\StatusCache;
use WHMCS\Module\Addon\Uptimeify\Store\SyncLock;
use WHMCS\Module\Addon\Uptimeify\Sync\IncidentWindow;
use WHMCS\Module\Addon\Uptimeify\Sync\RateLimitGuard;

/**
 * Page-view refresh of `client:<id>` (spec §9.3): only when expired, only GET calls filtered to the client's
 * customer, bounded (no 429 sleep, bounded incident walk, deadline), never for another client, stale on failure.
 */
final class ClientSnapshotTest extends DbTestCase
{
    private const OLD = '2020-01-01 00:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        Schema::install();
        (new CustomerMap())->set(5, 'c1', null, 'manual');
        (new MonitorMap())->upsert(['kind' => 'hosting', 'whmcs_ref_id' => 1, 'monitor_type' => 'website', 'monitor_public_id' => 'w1', 'customer_public_id' => 'c1', 'target' => 'site.example', 'state' => 'active']);
    }

    protected function tearDown(): void
    {
        Container::reset();
        parent::tearDown();
    }

    public function testReturnsNullWithoutMapping(): void
    {
        self::assertNull($this->snapshots(new FakeHttpClient())->for(99));
        self::assertNull($this->snapshots(null)->for(99));
    }

    public function testFreshSnapshotIsServedWithoutApiCalls(): void
    {
        (new StatusCache())->set('client:5', self::snapshot('c1', ['up' => 1]));
        $http = new FakeHttpClient();
        $snap = $this->snapshots($http)->for(5);
        self::assertNotNull($snap);
        self::assertSame(1, $snap['totals']['up']);
        self::assertFalse($snap['stale']);
        self::assertSame([], $http->requests());
    }

    public function testMissingSnapshotIsBuiltForThisClientOnlyWithCustomerFilteredGets(): void
    {
        (new CustomerMap())->set(6, 'c2', null, 'manual');
        (new MonitorMap())->upsert(['kind' => 'hosting', 'whmcs_ref_id' => 2, 'monitor_type' => 'customer_domain', 'monitor_public_id' => 'd2', 'customer_public_id' => 'c2', 'target' => 'other.example', 'state' => 'active']);
        $cache = new StatusCache();
        $cache->set('client:6', self::snapshot('c2', ['down' => 1]));
        $this->backdate('client:6');
        $http = (new FakeHttpClient())
            ->on('GET', '/api/websites', 200, ['items' => [['publicId' => 'w1', 'id' => 1, 'customerId' => 42, 'customerPublicId' => 'c1', 'name' => 'site', 'url' => 'https://site.example', 'status' => 'active', 'isDown' => false, 'uptimeDay' => '100.00']], 'total' => 1, 'page' => 1, 'perPage' => 100])
            ->on('GET', '/api/incidents', 200, ['incidents' => [
                ['id' => 1, 'publicId' => 'i1', 'status' => 'open', 'title' => 'Down', 'started_at' => '2026-09-13T10:00:00Z', 'resolved_at' => null, 'website' => ['publicId' => 'w1', 'name' => 'site'], 'customer' => ['publicId' => 'c1']],
                ['id' => 2, 'publicId' => 'i2', 'status' => 'open', 'title' => 'Other', 'started_at' => '2026-09-13T09:00:00Z', 'resolved_at' => null, 'website' => ['publicId' => 'w9'], 'customer' => ['publicId' => 'c2']],
            ], 'total' => 2, 'limit' => 500, 'offset' => 0]);

        $snap = $this->snapshots($http)->for(5);

        self::assertNotNull($snap);
        self::assertFalse($snap['stale']);
        self::assertSame('c1', $snap['customerPublicId']);
        self::assertSame('up', $snap['monitors'][0]['status']);
        self::assertSame(100.0, $snap['monitors'][0]['uptimeDay']);
        self::assertSame(['i1'], array_column($snap['incidents'], 'publicId'));
        self::assertTrue($snap['incidentsAvailable']);
        self::assertSame($snap['customerPublicId'], $cache->value('client:5')['customerPublicId'] ?? null, 'client:5 written');
        self::assertArrayNotHasKey('stale', $cache->value('client:5') ?? [], 'the flag is not stored');
        self::assertSame(self::OLD, $cache->get('client:6')['refreshed_at'] ?? null, 'another client\'s snapshot is not touched');
        self::assertNull($cache->get('org'), 'the org snapshot is not written from the client area');
        self::assertSame(['GET'], array_values(array_unique(array_column($http->requests(), 'method'))), 'read-only: GET calls only');
        self::assertSame([
            'https://api.test/api/websites?page=1&perPage=100&sortBy=createdAt&sortOrder=asc&customerId=c1',
            'https://api.test/api/incidents?limit=500&includeTotal=1&offset=0',
        ], array_column($http->requests(), 'url'), 'no customer list, only the types in the map, filtered by customer');
    }

    public function testDnsMonitorsAreFilteredByTheNumericCustomerId(): void
    {
        (new MonitorMap())->upsert(['kind' => 'domain', 'whmcs_ref_id' => 3, 'monitor_type' => 'dns', 'monitor_public_id' => 'n1', 'customer_public_id' => 'c1', 'target' => 'site.example', 'state' => 'active']);
        $http = (new FakeHttpClient())
            ->on('GET', '/api/websites', 200, ['items' => [['publicId' => 'w1', 'customerId' => 42, 'customerPublicId' => 'c1', 'status' => 'active']], 'total' => 1, 'page' => 1, 'perPage' => 100])
            ->on('GET', '/api/dns-monitors', 200, ['items' => [['publicId' => 'n1', 'customerId' => 42, 'customerPublicId' => 'c1', 'hostname' => 'site.example', 'status' => 'active', 'isDown' => true]], 'total' => 1, 'page' => 1, 'perPage' => 100])
            ->on('GET', '/api/incidents', 200, ['incidents' => [], 'total' => 0, 'limit' => 500, 'offset' => 0]);
        $snap = $this->snapshots($http)->for(5);
        self::assertNotNull($snap);
        self::assertContains('https://api.test/api/dns-monitors?page=1&perPage=100&sortBy=createdAt&sortOrder=asc&customerId=42', array_column($http->requests(), 'url'));
        self::assertSame(['down', 'up'], array_column($snap['monitors'], 'status'));

        // Only DNS in the map: the numeric id comes from GET /api/customers/:publicId.
        (new MonitorMap())->remove('hosting', 1, 'website');
        Capsule::table('mod_uptimeify_status_cache')->delete();
        $http = (new FakeHttpClient())
            ->on('GET', '/api/customers/c1', 200, ['id' => 43, 'publicId' => 'c1', 'name' => 'A'])
            ->on('GET', '/api/dns-monitors', 200, ['items' => [], 'total' => 0, 'page' => 1, 'perPage' => 100])
            ->on('GET', '/api/incidents', 200, ['incidents' => [], 'total' => 0, 'limit' => 500, 'offset' => 0]);
        $this->snapshots($http)->for(5);
        self::assertSame([
            'https://api.test/api/customers/c1',
            'https://api.test/api/dns-monitors?page=1&perPage=100&sortBy=createdAt&sortOrder=asc&customerId=43',
            'https://api.test/api/incidents?limit=500&includeTotal=1&offset=0',
        ], array_column($http->requests(), 'url'));
    }

    public function testApiFailureServesTheStaleSnapshotAndKeepsIt(): void
    {
        $cache = new StatusCache();
        $cache->set('client:5', self::snapshot('c1', ['down' => 1]));
        $this->backdate('client:5');
        $http = (new FakeHttpClient())->on('GET', '/api/websites', 500, ['statusMessage' => 'boom']);
        $snap = $this->snapshots($http)->for(5);
        self::assertNotNull($snap);
        self::assertSame(1, $snap['totals']['down']);
        self::assertTrue($snap['stale']);
        self::assertSame(self::OLD, $cache->get('client:5')['refreshed_at'] ?? null);
        self::assertStringContainsString('boom', $GLOBALS['uptimeify_test']['activity'][0][0], 'the cause goes to the activity log for the admin');

        $again = $this->snapshots($http)->for(5);
        self::assertNotNull($again);
        self::assertTrue($again['stale']);
        self::assertCount(1, $http->requests(), 'within the retry window: no new attempt');
        self::assertCount(1, $GLOBALS['uptimeify_test']['activity'], 'and no new log line');

        $this->backdate(ClientSnapshot::retryKey(5));
        $this->snapshots($http)->for(5);
        self::assertCount(2, $http->requests(), 'after the window it retries');
        self::assertCount(2, $GLOBALS['uptimeify_test']['activity']);
    }

    public function testCachedNeverCallsTheApi(): void
    {
        (new StatusCache())->set('client:5', self::snapshot('c1', ['up' => 1]));
        $http = new FakeHttpClient();
        $cached = $this->snapshots($http)->cached(5);
        self::assertNotNull($cached);
        self::assertFalse($cached['stale']);
        $this->backdate('client:5');
        $cached = $this->snapshots($http)->cached(5);
        self::assertNotNull($cached);
        self::assertTrue($cached['stale'], 'expired');
        self::assertSame([], $http->requests());
        self::assertNull($this->snapshots($http)->cached(99), 'no mapping');
        (new StatusCache())->set('client:5', self::snapshot('c-old', ['up' => 1]));
        self::assertNull($this->snapshots($http)->cached(5), 'snapshot of a previous mapping');
    }

    public function testASnapshotWrittenBySyncDuringTheRefreshIsNotOverwritten(): void
    {
        $cache = new StatusCache();
        $cache->set('client:5', self::snapshot('c1', ['up' => 1]));
        $this->backdate('client:5');
        $http = new class () extends FakeHttpClient {
            public function send(string $method, string $url, array $headers, ?string $body, float $timeout): HttpResponse
            {
                parent::on('GET', '/api/websites', 200, ['items' => [], 'total' => 0, 'page' => 1, 'perPage' => 100]);
                parent::on('GET', '/api/incidents', 200, ['incidents' => [], 'total' => 0, 'limit' => 500, 'offset' => 0]);
                if (str_contains($url, '/api/incidents')) {
                    // The cron run writes a newer snapshot while this refresh is still loading.
                    (new StatusCache())->set('client:5', ['customerPublicId' => 'c1', 'totals' => ['total' => 7, 'up' => 7, 'down' => 0, 'paused' => 0], 'monitors' => [], 'incidents' => [], 'incidentsAvailable' => true, 'refreshedAt' => 'cron']);
                }
                return parent::send($method, $url, $headers, $body, $timeout);
            }
        };
        $snap = $this->snapshots($http)->for(5);
        self::assertNotNull($snap);
        self::assertSame(7, $snap['totals']['up'], 'serves the newer snapshot');
        self::assertFalse($snap['stale']);
        self::assertSame('cron', $cache->value('client:5')['refreshedAt'] ?? null, 'not overwritten');
    }

    public function testNoMonitorTypesSkipsEveryApiCall(): void
    {
        (new MonitorMap())->remove('hosting', 1, 'website');
        $http = new FakeHttpClient();
        $snap = $this->snapshots($http)->for(5);
        self::assertNotNull($snap);
        self::assertSame([], $snap['monitors']);
        self::assertSame([], $http->requests(), 'no incident walk for a client without monitors');
        self::assertNotNull((new StatusCache())->value('client:5'));
    }

    public function testInvalidIncidentResponseServesStaleInsteadOfFailing(): void
    {
        (new StatusCache())->set('client:5', self::snapshot('c1', ['up' => 1]));
        $this->backdate('client:5');
        $http = (new FakeHttpClient())
            ->on('GET', '/api/websites', 200, ['items' => [], 'total' => 0, 'page' => 1, 'perPage' => 100])
            ->on('GET', '/api/incidents', 200, 'not json');
        $snap = $this->snapshots($http)->for(5);
        self::assertNotNull($snap);
        self::assertTrue($snap['stale']);
    }

    public function testNoSnapshotAndFailedRefreshReturnsNull(): void
    {
        $http = (new FakeHttpClient())->on('GET', '/api/websites', 503, ['statusMessage' => 'down']);
        self::assertNull($this->snapshots($http)->for(5));
    }

    public function testRateLimitNeverSleepsInTheClientArea(): void
    {
        $settings = new Settings();
        $settings->setApiToken('wsm_test-token');
        $settings->setBool('connection_validated', true);
        (new StatusCache())->set('client:5', self::snapshot('c1', ['up' => 1]));
        $this->backdate('client:5');
        $http = (new FakeHttpClient())->on('GET', '/api/websites', 429, ['statusMessage' => 'Too Many Requests'], ['Retry-After' => '60']);
        Container::useHttp($http);
        $started = microtime(true);
        $snap = Container::clientSnapshot()->for(5);
        self::assertNotNull($snap);
        self::assertTrue($snap['stale']);
        self::assertCount(1, $http->requests(), 'no retry');
        self::assertLessThan(5.0, microtime(true) - $started, 'no sleep');
    }

    public function testContainerGivesNoApiWhenNotConnected(): void
    {
        (new Settings())->setApiToken('wsm_test-token');
        (new StatusCache())->set('client:5', self::snapshot('c1', ['up' => 1]));
        $this->backdate('client:5');
        $http = new FakeHttpClient();
        Container::useHttp($http);
        $snap = Container::clientSnapshot()->for(5);
        self::assertNotNull($snap);
        self::assertTrue($snap['stale'], 'expired and not refreshable');
        self::assertSame([], $http->requests());
    }

    public function testContainerGivesNoApiWhileTheTokenBelongsToAnotherOrganization(): void
    {
        $settings = Container::settings();
        $settings->setApiToken('wsm_test-token');
        $settings->setBool('connection_validated', true);
        $settings->setJson('organization_mismatch', ['publicId' => 'o2', 'name' => 'Other']);
        (new StatusCache())->set('client:5', self::snapshot('c1', ['up' => 1]));
        $this->backdate('client:5');
        $http = new FakeHttpClient();
        Container::useHttp($http);
        $snap = Container::clientSnapshot()->for(5);
        self::assertNotNull($snap);
        self::assertTrue($snap['stale']);
        self::assertSame([], $http->requests(), 'no organization check and no refresh inside the page view');
    }

    public function testHeldSyncLockServesStaleWithoutApiCalls(): void
    {
        (new StatusCache())->set('client:5', self::snapshot('c1', ['up' => 1]));
        $this->backdate('client:5');
        $lock = new SyncLock();
        self::assertTrue($lock->acquire());
        $http = new FakeHttpClient();
        $snap = $this->snapshots($http)->for(5);
        $lock->release();
        self::assertNotNull($snap);
        self::assertTrue($snap['stale']);
        self::assertSame([], $http->requests());
    }

    public function testConcurrentRefreshOfTheSameClientServesStale(): void
    {
        (new StatusCache())->set('client:5', self::snapshot('c1', ['up' => 1]));
        $this->backdate('client:5');
        $lock = new SyncLock();
        self::assertTrue($lock->acquire(ClientSnapshot::lockName(5)));
        $http = new FakeHttpClient();
        $snap = $this->snapshots($http)->for(5);
        self::assertNotNull($snap);
        self::assertTrue($snap['stale']);
        self::assertSame([], $http->requests());
        $lock->release(ClientSnapshot::lockName(5));
        self::assertTrue($lock->acquire(ClientSnapshot::lockName(5)), 'a refresh releases its lock');
    }

    public function testRefreshReleasesItsLockAfterAFailure(): void
    {
        $http = (new FakeHttpClient())->on('GET', '/api/websites', 500, ['statusMessage' => 'boom']);
        $this->snapshots($http)->for(5);
        self::assertTrue((new SyncLock())->acquire(ClientSnapshot::lockName(5)));
    }

    public function testSnapshotOfAPreviousMappingIsNeverServed(): void
    {
        (new StatusCache())->set('client:5', self::snapshot('c-old', ['up' => 1]));
        self::assertNull($this->snapshots(null)->for(5), 'fresh, but for another customer; no API to rebuild');

        $http = (new FakeHttpClient())
            ->on('GET', '/api/websites', 200, ['items' => [], 'total' => 0, 'page' => 1, 'perPage' => 100])
            ->on('GET', '/api/incidents', 200, ['incidents' => [], 'total' => 0, 'limit' => 500, 'offset' => 0]);
        $snap = $this->snapshots($http)->for(5);
        self::assertNotNull($snap);
        self::assertSame('c1', $snap['customerPublicId'], 'rebuilt at once although not expired');
    }

    public function testAFreshSnapshotWithIncidentsReadLongAgoIsRefreshed(): void
    {
        $snapshot = self::snapshot('c1', ['up' => 1]);
        $snapshot['incidentsRefreshedAt'] = self::OLD; // written by an admin "Refresh status" that kept older incidents
        (new StatusCache())->set('client:5', $snapshot);
        $http = (new FakeHttpClient())
            ->on('GET', '/api/websites', 200, ['items' => [], 'total' => 0, 'page' => 1, 'perPage' => 100])
            ->on('GET', '/api/incidents', 200, ['incidents' => [], 'total' => 0, 'limit' => 500, 'offset' => 0]);
        $snap = $this->snapshots($http)->for(5);
        self::assertNotNull($snap);
        self::assertFalse($snap['stale']);
        self::assertCount(2, $http->requests(), 'the page view reads the incidents again');
        self::assertNotSame(self::OLD, $snap['incidentsRefreshedAt']);
    }

    public function testNoApiServesAnExpiredSnapshotAsStale(): void
    {
        (new StatusCache())->set('client:5', self::snapshot('c1', ['up' => 1]));
        $this->backdate('client:5');
        $snap = $this->snapshots(null)->for(5);
        self::assertNotNull($snap);
        self::assertTrue($snap['stale']);
    }

    public function testTruncatedIncidentWalkFlagsIncompleteAndCarriesNothingOverFromThePreviousSnapshot(): void
    {
        $previous = self::snapshot('c1', ['up' => 1]);
        $previous['incidents'] = [
            ['publicId' => 'i-new', 'title' => 'Was open', 'status' => 'open', 'startedAt' => '2026-09-13T10:00:00Z', 'monitorPublicId' => 'w1', 'monitorName' => 'site', 'customerPublicId' => 'c1'],
            ['publicId' => 'i-old', 'title' => 'Long open', 'status' => 'acknowledged', 'startedAt' => '2025-01-01T00:00:00Z', 'monitorPublicId' => 'w1', 'monitorName' => 'site', 'customerPublicId' => 'c1'],
        ];
        (new StatusCache())->set('client:5', $previous);
        $this->backdate('client:5');
        $http = $this->incidentPages(static fn (int $offset): array => $offset === 0
            ? [['id' => 1, 'publicId' => 'i-new', 'status' => 'resolved', 'started_at' => '2026-09-13T10:00:00Z', 'resolved_at' => '2026-09-13T11:00:00Z', 'website' => ['publicId' => 'w1'], 'customer' => ['publicId' => 'c1']]]
            : [['id' => $offset + 1, 'publicId' => 'x' . $offset, 'status' => 'resolved', 'started_at' => '2026-09-12T10:00:00Z', 'resolved_at' => '2026-09-12T11:00:00Z', 'customer' => ['publicId' => 'c9']]], 1000);

        $snap = $this->snapshots($http)->for(5);

        self::assertNotNull($snap);
        self::assertFalse($snap['stale']);
        self::assertSame([], array_column($snap['incidents'], 'publicId'), 'an incident outside the window is never kept: it could never be seen resolved');
        self::assertSame(0, $snap['monitors'][0]['openIncidents']);
        self::assertTrue($snap['incidentsAvailable']);
        self::assertFalse($snap['incidentsComplete']);
        self::assertCount(1 + IncidentWindow::PAGES, $http->requests(), 'websites + the bounded incident pages');
    }

    public function testCompleteIncidentWalkFlagsComplete(): void
    {
        $http = $this->incidentPages(static fn (int $offset): array => [['id' => 1, 'publicId' => 'i1', 'status' => 'open', 'started_at' => '2026-09-13T10:00:00Z', 'resolved_at' => null, 'website' => ['publicId' => 'w1'], 'customer' => ['publicId' => 'c1']]], 1);
        $snap = $this->snapshots($http)->for(5);
        self::assertNotNull($snap);
        self::assertTrue($snap['incidentsAvailable']);
        self::assertTrue($snap['incidentsComplete']);
        self::assertSame(['i1'], array_column($snap['incidents'], 'publicId'));
    }

    public function testTruncatedIncidentWalkWithoutPreviousSnapshotShowsTheWindowAsIncomplete(): void
    {
        $http = $this->incidentPages(static fn (int $offset): array => [['id' => $offset + 1, 'publicId' => 'i' . $offset, 'status' => 'open', 'started_at' => '2026-09-13T10:00:00Z', 'resolved_at' => null, 'website' => ['publicId' => 'w1'], 'customer' => ['publicId' => 'c1']]], 1000);
        $snap = $this->snapshots($http)->for(5);
        self::assertNotNull($snap);
        self::assertTrue($snap['incidentsAvailable']);
        self::assertFalse($snap['incidentsComplete']);
        self::assertCount(IncidentWindow::PAGES, $snap['incidents']);
    }

    public function testDeadlineStopsTheRefreshAndServesStale(): void
    {
        $cache = new StatusCache();
        $cache->set('client:5', self::snapshot('c1', ['up' => 1]));
        $this->backdate('client:5');
        $http = (new FakeHttpClient())
            ->on('GET', '/api/websites', 200, ['items' => [], 'total' => 0, 'page' => 1, 'perPage' => 100])
            ->on('GET', '/api/incidents', 200, ['incidents' => [], 'total' => 0, 'limit' => 500, 'offset' => 0]);
        $times = [0.0, ClientSnapshot::DEADLINE_SECONDS + 1.0];
        $clock = static function () use (&$times): float {
            return count($times) > 1 ? (float) array_shift($times) : (float) $times[0];
        };
        $snap = $this->snapshots($http, $clock)->for(5);
        self::assertNotNull($snap);
        self::assertTrue($snap['stale']);
        self::assertSame(['https://api.test/api/websites?page=1&perPage=100&sortBy=createdAt&sortOrder=asc&customerId=c1'], array_column($http->requests(), 'url'));
        self::assertSame(self::OLD, $cache->get('client:5')['refreshed_at'] ?? null);
    }

    /** @param (\Closure():float)|null $clock */
    private function snapshots(?FakeHttpClient $http, ?\Closure $clock = null): ClientSnapshot
    {
        $api = $http === null ? null : new Client($http, 'https://api.test', 'wsm_test-token');
        return new ClientSnapshot(new StatusCache(), new CustomerMap(), new MonitorMap(), $api, new RateLimitGuard(null, 0), new SyncLock(), $clock);
    }

    /**
     * Websites answer empty; incidents come in pages of one row out of `$total`.
     *
     * @param \Closure(int):list<array<string,mixed>> $page
     */
    private function incidentPages(\Closure $page, int $total): FakeHttpClient
    {
        return new class ($page, $total) extends FakeHttpClient {
            /** @param \Closure(int):list<array<string,mixed>> $page */
            public function __construct(private readonly \Closure $page, private readonly int $total)
            {
            }

            public function send(string $method, string $url, array $headers, ?string $body, float $timeout): HttpResponse
            {
                parent::on('GET', '/api/websites', 200, ['items' => [['publicId' => 'w1', 'customerPublicId' => 'c1', 'status' => 'active']], 'total' => 1, 'page' => 1, 'perPage' => 100]);
                parent::on('GET', '/api/incidents', 200, []);
                $response = parent::send($method, $url, $headers, $body, $timeout);
                if (!str_contains($url, '/api/incidents')) {
                    return $response;
                }
                parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
                $offset = (int) ($q['offset'] ?? 0);
                return new HttpResponse(200, [], (string) json_encode(['incidents' => ($this->page)($offset), 'total' => $this->total, 'limit' => 500, 'offset' => $offset]));
            }
        };
    }

    private function backdate(string $key): void
    {
        Capsule::table('mod_uptimeify_status_cache')->where('key', $key)->update(['refreshed_at' => self::OLD]);
    }

    /**
     * @param array<string,int> $totals
     * @return array<string,mixed>
     */
    private static function snapshot(string $customerPublicId, array $totals): array
    {
        return [
            'customerPublicId' => $customerPublicId,
            'totals' => $totals + ['total' => 1, 'up' => 0, 'down' => 0, 'paused' => 0],
            'monitors' => [],
            'incidents' => [],
            'incidentsAvailable' => true,
            'refreshedAt' => '2026-09-13 10:00:00',
        ];
    }
}
