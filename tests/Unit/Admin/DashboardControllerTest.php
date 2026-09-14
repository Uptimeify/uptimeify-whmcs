<?php

declare(strict_types=1);

namespace Uptimeify\Tests\Unit\Admin;

use Uptimeify\Tests\Fake\FakeHttpClient;
use Uptimeify\Tests\Support\DbTestCase;
use WHMCS\Module\Addon\Uptimeify\Admin\DashboardController;
use WHMCS\Module\Addon\Uptimeify\Container;
use WHMCS\Module\Addon\Uptimeify\Settings;
use WHMCS\Module\Addon\Uptimeify\Store\CustomerMap;
use WHMCS\Module\Addon\Uptimeify\Store\DetachedMonitors;
use WHMCS\Module\Addon\Uptimeify\Store\MonitorMap;
use WHMCS\Module\Addon\Uptimeify\Store\Queue;
use WHMCS\Module\Addon\Uptimeify\Store\Schema;
use WHMCS\Module\Addon\Uptimeify\Store\StatusCache;
use WHMCS\Module\Addon\Uptimeify\Store\SyncLock;
use WHMCS\Module\Addon\Uptimeify\Sync\MonitorPlanner;
use WHMCS\Module\Addon\Uptimeify\Sync\Reconciler;
use WHMCS\Module\Addon\Uptimeify\Sync\RemoteIndex;

final class DashboardControllerTest extends DbTestCase
{
    private FakeHttpClient $http;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::install();
        Container::settings()->setApiToken('wsm_x');
        Container::settings()->setBool('connection_validated', true);
        Container::settings()->set('default_package_type', 'starter');
        $this->http = new FakeHttpClient();
        Container::useHttp($this->http);
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        Container::reset();
        $_SESSION = [];
        parent::tearDown();
    }

    /** @return array{type:string,message:string} */
    private static function flash(): array
    {
        self::assertIsArray($_SESSION['uptimeify_flash'] ?? null, 'a flash message was set');
        return $_SESSION['uptimeify_flash'];
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return array<string,mixed>
     */
    private static function page(array $items): array
    {
        return ['items' => $items, 'total' => count($items), 'page' => 1, 'perPage' => 100];
    }

    /**
     * Every list a job or reconcile reads; websites are created as w1, w2, …
     *
     * @param list<array<string,mixed>> $websites
     */
    private function remote(array $websites = []): void
    {
        $this->http
            ->on('GET', '/api/organization', 200, ['id' => 7, 'publicId' => 'o1', 'name' => 'Acme Org'])
            ->on('GET', '/api/customers', 200, self::page([['id' => 1, 'publicId' => 'c1', 'name' => 'Acme', 'email' => 'a@example.com', 'customFields' => ['whmcs_client_id' => '1']]]))
            ->on('GET', '/api/websites', 200, self::page($websites))
            ->on('GET', '/api/customer-domains', 200, self::page([]))
            ->on('GET', '/api/dns-monitors', 200, self::page([]))
            ->on('GET', '/api/customer-ips', 200, self::page([]))
            ->on('GET', '/api/tags', 200, [['id' => 1, 'publicId' => 't1', 'name' => 'whmcs', 'customerId' => null]])
            ->on('POST', '/api/monitor-tags', 200, ['id' => 1])
            ->on('GET', '/api/incidents', 200, ['incidents' => [], 'total' => 0, 'limit' => 500, 'offset' => 0]);
    }

    /** @return list<string> "METHOD path" */
    private function sent(): array
    {
        return array_map(fn (array $r): string => $r['method'] . ' ' . parse_url($r['url'], PHP_URL_PATH), $this->http->requests());
    }

    public function testRowsMergeDesiredAndKnownAndFilter(): void
    {
        $c = $this->insert('tblclients', ['companyname' => 'Acme', 'email' => 'a@example.com']);
        $h1 = $this->insert('tblhosting', ['userid' => $c, 'domain' => 'one.example', 'domainstatus' => 'Active']);
        $h2 = $this->insert('tblhosting', ['userid' => $c, 'domain' => 'two.example', 'domainstatus' => 'Terminated']);
        (new CustomerMap())->set($c, 'c1', null, 'manual');
        (new MonitorMap())->upsert(['kind' => 'hosting', 'whmcs_ref_id' => $h2, 'monitor_type' => 'website', 'monitor_public_id' => 'w2', 'customer_public_id' => 'c1', 'target' => 'two.example', 'state' => 'paused']);
        $rows = (new DashboardController())->rows([]);
        self::assertCount(2, $rows);
        self::assertSame('not_monitored', $rows[0]['state']);
        self::assertSame($h1, $rows[0]['refId']);
        self::assertSame('hosting:' . $h1 . ':website', $rows[0]['key']);
        self::assertSame('paused', $rows[1]['state']);
        self::assertSame('Acme', $rows[1]['clientName'], 'a row that is no longer planned finds its client through the customer');
        self::assertCount(1, (new DashboardController())->rows(['q' => 'two']));
        self::assertCount(1, (new DashboardController())->rows(['q' => 'ACME', 'state' => 'paused']));
        self::assertCount(1, (new DashboardController())->rows(['state' => 'paused']));
    }

    public function testNoCustomerOnlyWhenAutoCreateIsOff(): void
    {
        $c = $this->insert('tblclients', ['companyname' => 'Acme', 'email' => 'a@example.com']);
        $this->insert('tblhosting', ['userid' => $c, 'domain' => 'one.example', 'domainstatus' => 'Active']);
        self::assertSame('not_monitored', (new DashboardController())->rows([])[0]['state'], 'spec 8.2: auto-create would create the customer');

        Container::settings()->setBool('auto_create_customers', false);
        self::assertSame('no_customer', (new DashboardController())->rows([])[0]['state']);

        (new CustomerMap())->set($c, 'c1', null, 'manual');
        self::assertSame('not_monitored', (new DashboardController())->rows([])[0]['state']);
    }

    public function testQuotaErrorAndLiveStatusFromTheSnapshot(): void
    {
        $c = $this->insert('tblclients', ['companyname' => 'Acme', 'email' => 'a@example.com']);
        $h = $this->insert('tblhosting', ['userid' => $c, 'domain' => 'one.example', 'domainstatus' => 'Active', 'server' => 3]);
        $this->insert('tblhosting', ['userid' => $c, 'domain' => 'two.example', 'domainstatus' => 'Active']);
        (new CustomerMap())->set($c, 'c1', null, 'manual');
        $map = new MonitorMap();
        $map->upsert(['kind' => 'hosting', 'whmcs_ref_id' => $h, 'monitor_type' => 'website', 'monitor_public_id' => 'w1', 'customer_public_id' => 'c1', 'target' => 'one.example', 'state' => 'active']);
        $map->upsert(['kind' => 'server_ip', 'whmcs_ref_id' => MonitorPlanner::serverIpRefId(3, $c), 'monitor_type' => 'customer_ip', 'monitor_public_id' => null, 'customer_public_id' => null, 'target' => '192.0.2.1', 'state' => 'error', 'last_error' => 'activeIpLimitReached']);
        (new StatusCache())->set('client:' . $c, ['monitors' => [['publicId' => 'w1', 'status' => 'down']]]);

        $rows = array_column((new DashboardController())->rows([]), null, 'target');

        self::assertSame(['active', 'down'], [$rows['one.example']['state'], $rows['one.example']['liveStatus']]);
        self::assertSame(['quota', $c, 'Acme'], [$rows['192.0.2.1']['state'], $rows['192.0.2.1']['clientId'], $rows['192.0.2.1']['clientName']], 'server-IP rows decode the client from the ref id');
        self::assertNull($rows['two.example']['liveStatus']);
        self::assertCount(1, (new DashboardController())->rows(['state' => 'quota']));
    }

    public function testIgnoreRemovesARowWithoutMonitorAndUnignorePlansTheTargetAgain(): void
    {
        $c = $this->insert('tblclients', ['companyname' => 'Acme', 'email' => 'a@example.com']);
        $h = $this->insert('tblhosting', ['userid' => $c, 'domain' => 'one.example', 'domainstatus' => 'Active']);
        (new MonitorMap())->upsert(['kind' => 'hosting', 'whmcs_ref_id' => $h, 'monitor_type' => 'website', 'monitor_public_id' => null, 'customer_public_id' => null, 'target' => 'one.example', 'state' => 'error', 'last_error' => 'x']);
        $d = new DashboardController();
        $d->ignore('https://www.One.example/');
        self::assertNull((new MonitorMap())->find('hosting', $h, 'website'));
        self::assertSame(['one.example'], (new Settings())->ignoredTargets());
        self::assertSame([], $d->rows([]), 'an ignored target has no rows; the dashboard lists it from the settings');
        self::assertSame([], $this->http->requests());
        $d->unignore('one.example');
        self::assertSame([], (new Settings())->ignoredTargets());
        self::assertSame('not_monitored', $d->rows([])[0]['state']);
    }

    public function testIgnoreDetachesTheTargetsMonitorsWithoutAnyRemoteWrite(): void
    {
        $c = $this->insert('tblclients', ['companyname' => 'Acme', 'email' => 'a@example.com']);
        $h = $this->insert('tblhosting', ['userid' => $c, 'domain' => 'one.example', 'domainstatus' => 'Active']);
        $other = $this->insert('tblhosting', ['userid' => $c, 'domain' => 'two.example', 'domainstatus' => 'Active']);
        (new CustomerMap())->set($c, 'c1', null, 'manual');
        Container::settings()->setBool('sync_hosting_domain_expiry', true);
        $map = new MonitorMap();
        $map->upsert(['origin' => 'created', 'admin_paused' => true, 'kind' => 'hosting', 'whmcs_ref_id' => $h, 'monitor_type' => 'website', 'monitor_public_id' => 'w1', 'customer_public_id' => 'c1', 'target' => 'one.example', 'state' => 'paused']);
        $map->upsert(['kind' => 'hosting', 'whmcs_ref_id' => $h, 'monitor_type' => 'customer_domain', 'monitor_public_id' => 'd1', 'customer_public_id' => 'c1', 'target' => 'one.example', 'state' => 'active']);
        $map->upsert(['origin' => 'created', 'kind' => 'hosting', 'whmcs_ref_id' => $other, 'monitor_type' => 'website', 'monitor_public_id' => 'w2', 'customer_public_id' => 'c1', 'target' => 'two.example', 'state' => 'active']);

        (new DashboardController())->ignore('one.example');

        self::assertSame([], $this->http->requests(), 'no PATCH, no DELETE: the monitors stay in Uptimeify as they are');
        self::assertSame([], $map->forKindRef('hosting', $h), 'the rows of the ignored target are detached');
        self::assertSame('w2', $map->find('hosting', $other, 'website')['monitor_public_id'] ?? null, 'other targets stay');
        $detached = new DetachedMonitors();
        self::assertSame(['origin' => 'created', 'admin_paused' => true], $detached->find('website', 'w1'));
        self::assertSame(['origin' => 'adopted', 'admin_paused' => false], $detached->find('customer_domain', 'd1'));
        self::assertSame(['one.example'], (new Settings())->ignoredTargets());
        self::assertSame('success', self::flash()['type']);
        $log = implode("\n", array_column($GLOBALS['uptimeify_test']['activity'], 0));
        self::assertStringContainsString('Monitor detached', $log);
        self::assertStringContainsString('ignored_target', $log);
    }

    public function testUnignoreQueuesTheObjectsAndTheJobReadoptsWithOriginAndAdminPause(): void
    {
        $c = $this->insert('tblclients', ['companyname' => 'Acme', 'email' => 'a@example.com']);
        $h = $this->insert('tblhosting', ['userid' => $c, 'domain' => 'one.example', 'domainstatus' => 'Active']);
        (new CustomerMap())->set($c, 'c1', null, 'manual');
        (new MonitorMap())->upsert(['origin' => 'created', 'admin_paused' => true, 'kind' => 'hosting', 'whmcs_ref_id' => $h, 'monitor_type' => 'website', 'monitor_public_id' => 'w1', 'customer_public_id' => 'c1', 'target' => 'one.example', 'state' => 'paused']);
        $controller = new DashboardController();
        $controller->ignore('one.example');

        $controller->unignore('one.example');

        self::assertSame([], (new Settings())->ignoredTargets());
        self::assertSame(['hosting.activate:' . $h], array_map(fn (array $j): string => $j['job'] . ':' . $j['ref_id'], (new Queue())->due(10)), 'the next worker run re-adopts');
        self::assertSame([], $this->http->requests());

        $this->remote([['id' => 1, 'publicId' => 'w1', 'customerId' => 1, 'customerPublicId' => 'c1', 'name' => 'one.example', 'url' => 'https://one.example', 'status' => 'inactive']]);
        Container::jobHandler()->handle('hosting.activate', $h, RemoteIndex::load(Container::apiOrFail(), Container::guard(true), Reconciler::ALL_TYPES));

        $row = (new MonitorMap())->find('hosting', $h, 'website');
        self::assertSame(['w1', 'created', true, 'paused'], [$row['monitor_public_id'] ?? null, $row['origin'] ?? null, $row['admin_paused'] ?? null, $row['state'] ?? null]);
        self::assertNull((new DetachedMonitors())->find('website', 'w1'), 'the record moved back into the row');
        self::assertNotContains('PATCH /api/websites/w1', $this->sent(), 'still paused: nothing to change');
    }

    public function testSyncSelectedTriesARowRefusedWithInvalidUrlAgainAtOnce(): void
    {
        $c = $this->insert('tblclients', ['companyname' => 'Acme', 'email' => 'a@example.com']);
        $h = $this->insert('tblhosting', ['userid' => $c, 'domain' => 'one.example', 'domainstatus' => 'Active']);
        (new CustomerMap())->set($c, 'c1', null, 'manual');
        (new MonitorMap())->upsert(['kind' => 'hosting', 'whmcs_ref_id' => $h, 'monitor_type' => 'website', 'monitor_public_id' => null, 'customer_public_id' => 'c1', 'target' => 'one.example', 'state' => 'error', 'last_error' => 'invalidUrl']);
        $this->remote();
        $this->http->on('POST', '/api/websites', 200, ['id' => 101, 'publicId' => 'w1', 'customerId' => 1, 'url' => 'https://one.example', 'status' => 'active']);

        (new DashboardController())->syncSelected(['hosting:' . $h . ':website']);

        self::assertContains('POST /api/websites', $this->sent());
        $row = (new MonitorMap())->find('hosting', $h, 'website');
        self::assertSame(['active', null, 'w1'], [$row['state'] ?? null, $row['last_error'] ?? null, $row['monitor_public_id'] ?? null]);
        self::assertSame('success', self::flash()['type']);
    }

    public function testUnignoreAndSetCustomerWaitForARunningSync(): void
    {
        $c = $this->insert('tblclients', ['companyname' => 'Acme', 'email' => 'a@example.com']);
        Container::settings()->setIgnoredTargets(['one.example']);
        $lock = new SyncLock();
        self::assertTrue($lock->acquire());
        try {
            (new DashboardController())->unignore('one.example');
            self::assertSame(['one.example'], (new Settings())->ignoredTargets());
            self::assertSame('error', self::flash()['type']);
            unset($_SESSION['uptimeify_flash']);
            (new DashboardController())->setCustomer($c, 'c9');
            self::assertNull((new CustomerMap())->get($c));
            self::assertSame('error', self::flash()['type']);
        } finally {
            $lock->release();
        }
        self::assertSame([], $this->http->requests());
    }

    public function testIgnoreAndUnignoreOnlyTakeKnownTargets(): void
    {
        $d = new DashboardController();
        try {
            $d->ignore('unknown.example');
            self::fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException) {
        }
        try {
            $d->unignore('unknown.example');
            self::fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException) {
        }
        self::assertSame([], (new Settings())->ignoredTargets());
    }

    public function testSyncSelectedRunsTheObjectJobInlineUnderTheLock(): void
    {
        $c = $this->insert('tblclients', ['companyname' => 'Acme', 'email' => 'a@example.com']);
        $h = $this->insert('tblhosting', ['userid' => $c, 'domain' => 'one.example', 'domainstatus' => 'Active']);
        (new CustomerMap())->set($c, 'c1', null, 'manual');
        $this->remote();
        $this->http->on('POST', '/api/websites', 200, ['id' => 101, 'publicId' => 'w1', 'customerId' => 1, 'url' => 'https://one.example', 'status' => 'active']);

        (new DashboardController())->syncSelected(['hosting:' . $h . ':website']);

        self::assertSame('active', (new MonitorMap())->find('hosting', $h, 'website')['state']);
        self::assertSame('success', self::flash()['type']);
        self::assertNotNull((new StatusCache())->value('client:' . $c), 'the status cache is refreshed');
        self::assertTrue((new SyncLock())->acquire(), 'lock released');
        (new SyncLock())->release();
    }

    public function testSyncSelectedRefusesKeysThatAreNeitherPlannedNorMapped(): void
    {
        try {
            (new DashboardController())->syncSelected(['hosting:999:website']);
            self::fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException) {
        }
        self::assertSame([], $this->http->requests());
        self::assertSame([], (new Queue())->due(10));
    }

    public function testSyncSelectedWhileAnotherSyncRunsDoesNothing(): void
    {
        $c = $this->insert('tblclients', ['companyname' => 'Acme', 'email' => 'a@example.com']);
        $h = $this->insert('tblhosting', ['userid' => $c, 'domain' => 'one.example', 'domainstatus' => 'Active']);
        $lock = new SyncLock();
        self::assertTrue($lock->acquire());
        try {
            (new DashboardController())->syncSelected(['hosting:' . $h . ':website']);
        } finally {
            $lock->release();
        }
        self::assertSame('error', self::flash()['type']);
        self::assertSame([], $this->http->requests());
    }

    public function testSyncSelectedQueuesWhatIsLeftAfterTheTimeBudget(): void
    {
        $c = $this->insert('tblclients', ['companyname' => 'Acme', 'email' => 'a@example.com']);
        $h1 = $this->insert('tblhosting', ['userid' => $c, 'domain' => 'one.example', 'domainstatus' => 'Active']);
        $h2 = $this->insert('tblhosting', ['userid' => $c, 'domain' => 'two.example', 'domainstatus' => 'Active']);
        $d = $this->insert('tbldomains', ['userid' => $c, 'domain' => 'reg.example', 'status' => 'Active']);
        (new CustomerMap())->set($c, 'c1', null, 'manual');
        $this->remote();
        $this->http->on('POST', '/api/websites', 200, ['id' => 101, 'publicId' => 'w1', 'customerId' => 1, 'url' => 'https://one.example', 'status' => 'active']);
        $t = 0.0;
        $clock = function () use (&$t): float {
            return $t += 15.0;
        };

        (new DashboardController($clock))->syncSelected(['hosting:' . $h1 . ':website', 'hosting:' . $h2 . ':website', 'domain:' . $d . ':customer_domain']);

        self::assertSame('active', (new MonitorMap())->find('hosting', $h1, 'website')['state']);
        self::assertSame(['hosting.activate:' . $h2, 'domain.upsert:' . $d], array_map(fn (array $j): string => $j['job'] . ':' . $j['ref_id'], (new Queue())->due(10)));
        self::assertSame('warning', self::flash()['type']);
        self::assertStringContainsString('cron', self::flash()['message']);
    }

    public function testSyncSelectedQueuesTheRestOnRateLimitWithoutSleeping(): void
    {
        $c = $this->insert('tblclients', ['companyname' => 'Acme', 'email' => 'a@example.com']);
        $h1 = $this->insert('tblhosting', ['userid' => $c, 'domain' => 'one.example', 'domainstatus' => 'Active']);
        $h2 = $this->insert('tblhosting', ['userid' => $c, 'domain' => 'two.example', 'domainstatus' => 'Active']);
        (new CustomerMap())->set($c, 'c1', null, 'manual');
        $this->remote();
        $this->http->on('POST', '/api/websites', 429, ['statusMessage' => 'Too Many Requests'], ['Retry-After' => '60']);
        $started = microtime(true);

        (new DashboardController())->syncSelected(['hosting:' . $h1 . ':website', 'hosting:' . $h2 . ':website']);

        self::assertLessThan(10.0, microtime(true) - $started, 'no Retry-After sleep inside the admin request');
        self::assertCount(1, array_filter($this->sent(), fn (string $r): bool => $r === 'POST /api/websites'));
        self::assertSame(['hosting.activate:' . $h1, 'hosting.activate:' . $h2], array_map(fn (array $j): string => $j['job'] . ':' . $j['ref_id'], (new Queue())->due(10)));
        self::assertSame('warning', self::flash()['type']);
    }

    public function testReconcileTreatsRateLimitAsBudgetUsedUpAndQueuesTheRest(): void
    {
        $c = $this->insert('tblclients', ['companyname' => 'Acme', 'email' => 'a@example.com']);
        $h1 = $this->insert('tblhosting', ['userid' => $c, 'domain' => 'one.example', 'domainstatus' => 'Active']);
        $h2 = $this->insert('tblhosting', ['userid' => $c, 'domain' => 'two.example', 'domainstatus' => 'Active']);
        (new CustomerMap())->set($c, 'c1', null, 'manual');
        $this->remote();
        $this->http->on('POST', '/api/websites', 429, ['statusMessage' => 'Too Many Requests'], ['Retry-After' => '60']);
        $started = microtime(true);

        (new DashboardController())->reconcile();

        self::assertLessThan(10.0, microtime(true) - $started, 'no Retry-After sleep inside the admin request');
        self::assertSame(['hosting.activate:' . $h1, 'hosting.activate:' . $h2], array_map(fn (array $j): string => $j['job'] . ':' . $j['ref_id'], (new Queue())->due(10)));
        self::assertSame('warning', self::flash()['type']);
        self::assertStringContainsString('The rest runs in the cron', self::flash()['message']);
    }

    public function testReconcileReportsALockedRun(): void
    {
        $lock = new SyncLock();
        self::assertTrue($lock->acquire());
        try {
            (new DashboardController())->reconcile();
        } finally {
            $lock->release();
        }
        self::assertSame('error', self::flash()['type']);
        self::assertStringContainsString('Another sync is running', self::flash()['message']);
    }

    public function testReconcileFinishedIsASuccess(): void
    {
        $this->remote();
        (new DashboardController())->reconcile();
        self::assertSame('success', self::flash()['type']);
        self::assertNotNull((new StatusCache())->value('last_reconcile'));
    }

    public function testReconcileStopsWhenTheTokenBelongsToAnotherOrganization(): void
    {
        Container::settings()->set('organization_public_id', 'o-bound');
        $this->remote();
        (new DashboardController())->reconcile();
        self::assertSame('error', self::flash()['type']);
        self::assertStringContainsString('another Uptimeify organization', self::flash()['message']);
        self::assertSame(['GET /api/organization'], $this->sent());
    }

    public function testRefreshStatusOnlyReadsTheListsAndWritesTheStatusCacheWithoutAnIncidentWalk(): void
    {
        $c = $this->insert('tblclients', ['companyname' => 'Acme', 'email' => 'a@example.com']);
        $h = $this->insert('tblhosting', ['userid' => $c, 'domain' => 'gone.example', 'domainstatus' => 'Terminated']);
        $this->insert('tblhosting', ['userid' => $c, 'domain' => 'new.example', 'domainstatus' => 'Active']);
        (new CustomerMap())->set($c, 'c1', null, 'manual');
        (new MonitorMap())->upsert(['kind' => 'hosting', 'whmcs_ref_id' => $h, 'monitor_type' => 'website', 'monitor_public_id' => 'w1', 'customer_public_id' => 'c1', 'target' => 'gone.example', 'state' => 'active', 'origin' => 'created']);
        $cache = new StatusCache();
        $cache->set('org', ['total' => 1, 'up' => 1, 'down' => 0, 'paused' => 0, 'incidents' => 1, 'incidentsAvailable' => true, 'incidentsComplete' => true]);
        $cache->set('client:' . $c, ['customerPublicId' => 'c1', 'incidents' => [['publicId' => 'i1', 'title' => 'down', 'status' => 'open', 'startedAt' => '2026-09-13T10:00:00Z', 'monitorPublicId' => 'w1', 'monitorName' => 'gone', 'customerPublicId' => 'c1']]]);
        $this->remote([['id' => 1, 'publicId' => 'w1', 'customerId' => 1, 'customerPublicId' => 'c1', 'name' => 'gone.example', 'url' => 'https://gone.example', 'status' => 'active', 'isDown' => true]]);

        (new DashboardController())->refreshStatus();

        self::assertSame(['GET /api/organization', 'GET /api/customers', 'GET /api/websites'], $this->sent(), 'GET only, only the types in the map, no incident walk');
        self::assertSame('active', (new MonitorMap())->find('hosting', $h, 'website')['state'] ?? null, 'nothing reconciled: the terminated hosting keeps its monitor');
        $org = $cache->value('org');
        self::assertNotNull($org);
        self::assertSame([1, 1, 1], [$org['total'], $org['down'], $org['incidents']]);
        self::assertSame('down', $cache->value('client:' . $c)['monitors'][0]['status'] ?? null);
        self::assertSame([], (new Queue())->due(10), 'no queue jobs');
        self::assertSame('success', self::flash()['type']);
        self::assertStringContainsString('Status refreshed', self::flash()['message']);
        self::assertTrue((new SyncLock())->acquire(), 'lock released');
        (new SyncLock())->release();
    }

    public function testRefreshStatusStopsAfterTheBudgetWithoutWritingAPartialSnapshot(): void
    {
        (new CustomerMap())->set(1, 'c1', null, 'manual');
        (new MonitorMap())->upsert(['kind' => 'hosting', 'whmcs_ref_id' => 1, 'monitor_type' => 'website', 'monitor_public_id' => 'w1', 'customer_public_id' => 'c1', 'target' => 'one.example', 'state' => 'active']);
        (new MonitorMap())->upsert(['kind' => 'domain', 'whmcs_ref_id' => 1, 'monitor_type' => 'customer_domain', 'monitor_public_id' => 'd1', 'customer_public_id' => 'c1', 'target' => 'one.example', 'state' => 'active']);
        $this->remote();
        $now = 0.0;
        $clock = static function () use (&$now): float {
            $now += 15.0;
            return $now;
        };

        (new DashboardController($clock))->refreshStatus();

        self::assertNotContains('GET /api/customer-domains', $this->sent(), 'stopped between lists');
        self::assertNull((new StatusCache())->value('org'));
        self::assertSame('warning', self::flash()['type']);
    }

    public function testRefreshStatusWaitsForARunningSyncAndNeverSleepsOnRateLimit(): void
    {
        $lock = new SyncLock();
        self::assertTrue($lock->acquire());
        try {
            (new DashboardController())->refreshStatus();
        } finally {
            $lock->release();
        }
        self::assertSame('error', self::flash()['type']);
        self::assertSame([], $this->sent());

        unset($_SESSION['uptimeify_flash']);
        $this->http->on('GET', '/api/organization', 429, ['statusMessage' => 'Too Many Requests'], ['Retry-After' => '60']);
        $started = microtime(true);
        (new DashboardController())->refreshStatus();
        self::assertLessThan(10.0, microtime(true) - $started);
        self::assertSame('error', self::flash()['type']);
        self::assertStringContainsString('HTTP 429', self::flash()['message']);
    }

    public function testSyncSelectedStopsWhenTheTokenBelongsToAnotherOrganization(): void
    {
        $c = $this->insert('tblclients', ['companyname' => 'Acme', 'email' => 'a@example.com']);
        $h = $this->insert('tblhosting', ['userid' => $c, 'domain' => 'one.example', 'domainstatus' => 'Active']);
        (new CustomerMap())->set($c, 'c1', null, 'manual');
        Container::settings()->set('organization_public_id', 'o-bound');
        $this->remote();

        (new DashboardController())->syncSelected(['hosting:' . $h . ':website']);

        self::assertSame(['GET /api/organization'], $this->sent());
        self::assertNull((new MonitorMap())->find('hosting', $h, 'website'));
        self::assertSame('error', self::flash()['type']);
        self::assertSame([], (new Queue())->due(10));
    }

    public function testEnableAlsoClearsTheAdminPauseKeptForADetachedRowOfTheMonitor(): void
    {
        $c = $this->insert('tblclients', ['companyname' => 'Acme', 'email' => 'a@example.com']);
        $h = $this->insert('tblhosting', ['userid' => $c, 'domain' => 'one.example', 'domainstatus' => 'Active']);
        (new CustomerMap())->set($c, 'c1', null, 'manual');
        (new MonitorMap())->upsert(['kind' => 'hosting', 'whmcs_ref_id' => $h, 'monitor_type' => 'website', 'monitor_public_id' => 'w1', 'customer_public_id' => 'c1', 'target' => 'one.example', 'state' => 'paused', 'admin_paused' => true]);
        (new DetachedMonitors())->remember('website', 'w1', 'created', true);
        $this->remote([['id' => 1, 'publicId' => 'w1', 'customerId' => 1, 'customerPublicId' => 'c1', 'name' => 'one.example', 'url' => 'https://one.example', 'status' => 'inactive']]);
        $this->http->on('PATCH', '/api/websites/w1', 200, ['publicId' => 'w1', 'status' => 'active']);

        (new DashboardController())->enable('hosting:' . $h . ':website');

        self::assertContains('PATCH /api/websites/w1', $this->sent(), 'resumed, not paused again because of the detached record');
        self::assertSame(['active', false], [(new MonitorMap())->find('hosting', $h, 'website')['state'] ?? null, (new MonitorMap())->find('hosting', $h, 'website')['admin_paused'] ?? null]);
        self::assertFalse((new DetachedMonitors())->find('website', 'w1')['admin_paused'] ?? false);
    }

    public function testEnableClearsADetachedAdminPauseEvenWhenTheRowItselfIsNotPaused(): void
    {
        (new MonitorMap())->upsert(['kind' => 'hosting', 'whmcs_ref_id' => 7, 'monitor_type' => 'website', 'monitor_public_id' => 'w1', 'customer_public_id' => 'c1', 'target' => 'one.example', 'state' => 'error', 'last_error' => 'http500']);
        (new DetachedMonitors())->remember('website', 'w1', 'adopted', true);
        $this->remote();

        (new DashboardController())->enable('hosting:7:website');

        self::assertSame(['origin' => 'adopted', 'admin_paused' => false], (new DetachedMonitors())->find('website', 'w1'));
    }

    public function testPausePausesAMappedActiveWebsite(): void
    {
        $c = $this->insert('tblclients', ['companyname' => 'Acme', 'email' => 'a@example.com']);
        $h = $this->insert('tblhosting', ['userid' => $c, 'domain' => 'one.example', 'domainstatus' => 'Active']);
        (new MonitorMap())->upsert(['kind' => 'hosting', 'whmcs_ref_id' => $h, 'monitor_type' => 'website', 'monitor_public_id' => 'w1', 'customer_public_id' => 'c1', 'target' => 'one.example', 'state' => 'active']);
        $this->http->on('PATCH', '/api/websites/w1', 200, ['publicId' => 'w1', 'status' => 'inactive']);

        (new DashboardController())->pause('hosting:' . $h . ':website');

        self::assertSame(['PATCH /api/websites/w1'], $this->sent());
        $row = (new MonitorMap())->find('hosting', $h, 'website');
        self::assertSame(['paused', true], [$row['state'] ?? null, $row['admin_paused'] ?? null], 'a persistent admin pause (D7)');
        self::assertSame('success', self::flash()['type']);
        self::assertSame('paused_admin', (new DashboardController())->rows([])[0]['state']);
        self::assertCount(1, (new DashboardController())->rows(['state' => 'paused_admin']));
    }

    public function testPauseOfACustomerIpDisablesItPersistently(): void
    {
        $ref = MonitorPlanner::serverIpRefId(3, 7);
        (new MonitorMap())->upsert(['kind' => 'server_ip', 'whmcs_ref_id' => $ref, 'monitor_type' => 'customer_ip', 'monitor_public_id' => 'i1', 'customer_public_id' => 'c1', 'target' => '192.0.2.1', 'state' => 'active']);
        $this->http->on('PATCH', '/api/customer-ips/i1', 200, ['publicId' => 'i1', 'status' => 'disabled']);

        (new DashboardController())->pause('server_ip:' . $ref . ':customer_ip');

        self::assertSame(['PATCH /api/customer-ips/i1'], $this->sent());
        self::assertSame('{"status":"disabled"}', $this->http->requests()[0]['body']);
        self::assertTrue((new MonitorMap())->find('server_ip', $ref, 'customer_ip')['admin_paused'] ?? null);
    }

    public function testEnableClearsTheAdminPauseOfEverySharedRowAndResumesWhenWhmcsWantsIt(): void
    {
        $c = $this->insert('tblclients', ['companyname' => 'Acme', 'email' => 'a@example.com']);
        $h1 = $this->insert('tblhosting', ['userid' => $c, 'domain' => 'one.example', 'domainstatus' => 'Active']);
        $h2 = $this->insert('tblhosting', ['userid' => $c, 'domain' => 'www.one.example', 'domainstatus' => 'Active']);
        (new CustomerMap())->set($c, 'c1', null, 'manual');
        $map = new MonitorMap();
        foreach ([$h1, $h2] as $h) {
            $map->upsert(['kind' => 'hosting', 'whmcs_ref_id' => $h, 'monitor_type' => 'website', 'monitor_public_id' => 'w1', 'customer_public_id' => 'c1', 'target' => 'one.example', 'state' => 'paused', 'admin_paused' => true]);
        }
        $this->remote([['id' => 1, 'publicId' => 'w1', 'customerId' => 1, 'customerPublicId' => 'c1', 'name' => 'one.example', 'url' => 'https://one.example', 'status' => 'inactive']]);
        $this->http->on('PATCH', '/api/websites/w1', 200, ['publicId' => 'w1', 'status' => 'active']);

        (new DashboardController())->enable('hosting:' . $h1 . ':website');

        self::assertSame(1, count(array_filter($this->sent(), fn (string $r): bool => $r === 'PATCH /api/websites/w1')), 'resumed once');
        self::assertSame('{"status":"active"}', array_values(array_filter($this->http->requests(), fn (array $r): bool => $r['method'] === 'PATCH'))[0]['body']);
        foreach ([$h1, $h2] as $h) {
            $row = $map->find('hosting', $h, 'website');
            self::assertSame(['active', false], [$row['state'] ?? null, $row['admin_paused'] ?? null]);
        }
        self::assertSame('success', self::flash()['type']);
    }

    public function testEnableOfAnAdminPausedMonitorWhmcsDoesNotWantOnlyClearsThePause(): void
    {
        $c = $this->insert('tblclients', ['companyname' => 'Acme', 'email' => 'a@example.com']);
        $h = $this->insert('tblhosting', ['userid' => $c, 'domain' => 'one.example', 'domainstatus' => 'Suspended']);
        (new CustomerMap())->set($c, 'c1', null, 'manual');
        (new MonitorMap())->upsert(['kind' => 'hosting', 'whmcs_ref_id' => $h, 'monitor_type' => 'website', 'monitor_public_id' => 'w1', 'customer_public_id' => 'c1', 'target' => 'one.example', 'state' => 'paused', 'admin_paused' => true]);
        $this->remote([['id' => 1, 'publicId' => 'w1', 'customerId' => 1, 'customerPublicId' => 'c1', 'name' => 'one.example', 'url' => 'https://one.example', 'status' => 'inactive']]);

        (new DashboardController())->enable('hosting:' . $h . ':website');

        self::assertSame([], array_values(array_filter($this->sent(), fn (string $r): bool => str_starts_with($r, 'PATCH'))), 'a suspended hosting keeps its website paused');
        $row = (new MonitorMap())->find('hosting', $h, 'website');
        self::assertSame(['paused', false], [$row['state'] ?? null, $row['admin_paused'] ?? null]);
    }

    public function testEnableWhileAnotherSyncRunsKeepsTheAdminPause(): void
    {
        (new MonitorMap())->upsert(['kind' => 'hosting', 'whmcs_ref_id' => 7, 'monitor_type' => 'website', 'monitor_public_id' => 'w1', 'customer_public_id' => 'c1', 'target' => 'one.example', 'state' => 'paused', 'admin_paused' => true]);
        $other = new SyncLock();
        self::assertTrue($other->acquire());
        try {
            (new DashboardController())->enable('hosting:7:website');
        } finally {
            $other->release();
        }
        self::assertTrue((new MonitorMap())->find('hosting', 7, 'website')['admin_paused'] ?? null);
        self::assertSame([], $this->http->requests());
        self::assertSame('error', self::flash()['type']);
    }

    public function testPauseMarksEveryRowSharingTheMonitor(): void
    {
        $map = new MonitorMap();
        $map->upsert(['kind' => 'hosting', 'whmcs_ref_id' => 7, 'monitor_type' => 'website', 'monitor_public_id' => 'w1', 'customer_public_id' => 'c1', 'target' => 'one.example', 'state' => 'active']);
        $map->upsert(['kind' => 'hosting', 'whmcs_ref_id' => 8, 'monitor_type' => 'website', 'monitor_public_id' => 'w1', 'customer_public_id' => 'c1', 'target' => 'one.example', 'state' => 'active']);
        $map->upsert(['kind' => 'hosting', 'whmcs_ref_id' => 8, 'monitor_type' => 'customer_domain', 'monitor_public_id' => 'w1', 'customer_public_id' => 'c1', 'target' => 'one.example', 'state' => 'active']);
        $this->http->on('PATCH', '/api/websites/w1', 200, ['publicId' => 'w1', 'status' => 'inactive']);

        (new DashboardController())->pause('hosting:7:website');

        self::assertSame(['paused', 'paused', 'active'], [$map->find('hosting', 7, 'website')['state'], $map->find('hosting', 8, 'website')['state'], $map->find('hosting', 8, 'customer_domain')['state']]);
        self::assertSame([true, true, false], [$map->find('hosting', 7, 'website')['admin_paused'], $map->find('hosting', 8, 'website')['admin_paused'], $map->find('hosting', 8, 'customer_domain')['admin_paused']]);
    }

    public function testPauseOfAMonitorGoneRemotelyMarksTheRowOrphaned(): void
    {
        $h = 7;
        (new MonitorMap())->upsert(['kind' => 'hosting', 'whmcs_ref_id' => $h, 'monitor_type' => 'website', 'monitor_public_id' => 'w1', 'customer_public_id' => 'c1', 'target' => 'one.example', 'state' => 'active']);
        $this->http->on('PATCH', '/api/websites/w1', 404, ['statusMessage' => 'Website not found', 'data' => ['code' => 'websiteNotFound']]);

        (new DashboardController())->pause('hosting:' . $h . ':website');

        self::assertSame('orphaned', (new MonitorMap())->find('hosting', $h, 'website')['state']);
    }

    public function testPauseOnlyTakesAMappedWebsiteOrCustomerIp(): void
    {
        (new MonitorMap())->upsert(['kind' => 'domain', 'whmcs_ref_id' => 3, 'monitor_type' => 'customer_domain', 'monitor_public_id' => 'd1', 'customer_public_id' => 'c1', 'target' => 'reg.example', 'state' => 'active']);
        foreach (['domain:3:customer_domain', 'hosting:3:website', 'garbage'] as $key) {
            try {
                (new DashboardController())->pause($key);
                self::fail('expected InvalidArgumentException for ' . $key);
            } catch (\InvalidArgumentException) {
            }
        }
        self::assertSame([], $this->http->requests());
    }

    public function testSetCustomerStoresAManualMappingAndQueuesTheClient(): void
    {
        $c = $this->insert('tblclients', ['companyname' => 'Acme', 'email' => 'a@example.com']);
        $this->http->on('GET', '/api/customers/c9', 200, ['id' => 9, 'publicId' => 'c9', 'name' => 'Acme', 'packageDisplayName' => 'Starter', 'customFields' => ['whmcs_client_id' => (string) $c]]);

        (new DashboardController())->setCustomer($c, ' c9 ');

        $mapping = (new CustomerMap())->get($c);
        self::assertNotNull($mapping);
        self::assertSame(['c9', 'manual', 'Starter'], [$mapping['customer_public_id'], $mapping['source'], $mapping['package_type']]);
        self::assertSame(['client.upsert:' . $c], array_map(fn (array $j): string => $j['job'] . ':' . $j['ref_id'], (new Queue())->due(10)));
        self::assertSame('success', self::flash()['type']);
    }

    public function testSetCustomerRefusesACustomerHeldByAnotherClient(): void
    {
        $c = $this->insert('tblclients', ['companyname' => 'Acme', 'email' => 'a@example.com']);
        $other = $this->insert('tblclients', ['companyname' => 'Other', 'email' => 'o@example.com']);
        (new CustomerMap())->set($other, 'c9', null, 'matched_email');

        try {
            (new DashboardController())->setCustomer($c, 'c9');
            self::fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('#' . $other, $e->getMessage());
        }
        self::assertNull((new CustomerMap())->get($c));
        self::assertSame([], $this->http->requests(), 'refused before any API call');
    }

    public function testSetCustomerRefusesACustomerTaggedForAnotherClient(): void
    {
        $c = $this->insert('tblclients', ['companyname' => 'Acme', 'email' => 'a@example.com']);
        $this->http->on('GET', '/api/customers/c9', 200, ['id' => 9, 'publicId' => 'c9', 'name' => 'Other', 'customFields' => ['whmcs_client_id' => '4711']]);

        try {
            (new DashboardController())->setCustomer($c, 'c9');
            self::fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('4711', $e->getMessage());
        }
        self::assertNull((new CustomerMap())->get($c));
    }

    public function testSetCustomerRefusesUnknownClientsAndCustomers(): void
    {
        $c = $this->insert('tblclients', ['companyname' => 'Acme', 'email' => 'a@example.com']);
        $this->http->on('GET', '/api/customers/nope', 404, ['statusMessage' => 'Customer not found']);
        $this->http->on('GET', '/api/customers/bad%20id', 400, ['statusMessage' => 'Invalid Customer identifier']);
        foreach ([[999, 'c9'], [$c, 'nope'], [$c, 'bad id'], [$c, '']] as [$clientId, $customer]) {
            try {
                (new DashboardController())->setCustomer($clientId, $customer);
                self::fail('expected InvalidArgumentException for ' . $clientId . '/' . $customer);
            } catch (\InvalidArgumentException) {
            }
        }
        self::assertSame([], (new CustomerMap())->all());
    }
}
