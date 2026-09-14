<?php

declare(strict_types=1);

namespace Uptimeify\Tests\Unit\Client;

use Uptimeify\Tests\Fake\FakeHttpClient;
use Uptimeify\Tests\Support\DbTestCase;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\Uptimeify\Container;
use WHMCS\Module\Addon\Uptimeify\Settings;
use WHMCS\Module\Addon\Uptimeify\Store\CustomerMap;
use WHMCS\Module\Addon\Uptimeify\Store\MonitorMap;
use WHMCS\Module\Addon\Uptimeify\Store\Schema;
use WHMCS\Module\Addon\Uptimeify\Store\StatusCache;

/**
 * `uptimeify_clientarea` (spec §9.3, §10): client id from the session only, the controller shows only monitors and
 * incidents of the client's own customer, and a failed refresh never breaks the page or leaks error text.
 */
final class DispatcherTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::install();
        (new Settings())->set('brand_name', 'Acme Monitor');
        (new CustomerMap())->set(5, 'c1', null, 'manual');
        (new CustomerMap())->set(6, 'c2', null, 'manual');
        $map = new MonitorMap();
        $map->upsert(['kind' => 'hosting', 'whmcs_ref_id' => 1, 'monitor_type' => 'website', 'monitor_public_id' => 'w1', 'customer_public_id' => 'c1', 'target' => 'site.example', 'state' => 'active']);
        $map->upsert(['kind' => 'domain', 'whmcs_ref_id' => 1, 'monitor_type' => 'customer_domain', 'monitor_public_id' => 'd1', 'customer_public_id' => 'c1', 'target' => 'site.example', 'state' => 'active']);
        $map->upsert(['kind' => 'hosting', 'whmcs_ref_id' => 2, 'monitor_type' => 'website', 'monitor_public_id' => 'w2', 'customer_public_id' => 'c2', 'target' => 'other.example', 'state' => 'active']);
        (new StatusCache())->set('client:5', self::clientSnapshot());
        (new StatusCache())->set('client:6', ['customerPublicId' => 'c2', 'totals' => ['total' => 1, 'up' => 0, 'down' => 1, 'paused' => 0], 'refreshedAt' => '2026-09-13 10:00:00', 'incidentsAvailable' => true,
            'monitors' => [self::monitor('website', 'w2', 'other.example', 'down')], 'incidents' => []]);
    }

    protected function tearDown(): void
    {
        unset($_GET['clientid'], $_POST['clientid'], $_REQUEST['clientid']);
        Container::reset();
        parent::tearDown();
    }

    public function testClientareaUsesSessionClientNotRequest(): void
    {
        $GLOBALS['uptimeify_test']['clientId'] = 5;
        $_GET['clientid'] = 6;
        $_POST['clientid'] = 6;
        $_REQUEST['clientid'] = 6;
        $out = uptimeify_clientarea(['modulelink' => 'index.php?m=uptimeify', 'clientid' => 6, 'userid' => 6, '_lang' => []]);
        // WHMCS resolves `templatefile` as `<module>/templates/<templatefile>.tpl` (sample addon: 'publicpage').
        self::assertSame('overview', $out['templatefile']);
        self::assertFileExists(dirname(__DIR__, 3) . '/templates/' . $out['templatefile'] . '.tpl');
        self::assertTrue($out['requirelogin']);
        self::assertSame('Monitoring', $out['pagetitle']);
        self::assertSame(['index.php?m=uptimeify' => 'Monitoring'], $out['breadcrumb']);
        $vars = $out['vars'];
        self::assertSame('ok', $vars['state']);
        self::assertSame('Acme Monitor', $vars['brand']);
        self::assertSame(['d1', 'w1'], array_column($vars['monitors'], 'publicId'), 'client 5, not the requested client 6');
        $w1 = $vars['monitors'][1];
        self::assertSame(['99.95 %', '—', '100.00 %', '321 ms', 'Website', 'up', 'success', 2], [$w1['uptimeDay'], $w1['uptimeMonth'], $w1['uptimeYear'], $w1['avgResponse'], $w1['typeLabel'], $w1['statusLabel'], $w1['statusClass'], $w1['openIncidents']]);
        self::assertFalse($w1['isDomain']);
        $d1 = $vars['monitors'][0];
        self::assertTrue($d1['isDomain']);
        self::assertSame(['2027-02-01', 'Example Registrar', 'Domain expiry', 'down', 'danger'], [$d1['expiresAt'], $d1['registrar'], $d1['typeLabel'], $d1['statusLabel'], $d1['statusClass']]);
        self::assertSame([['title' => 'Site down', 'monitorName' => 'site.example', 'startedAt' => '2026-09-13 08:00 UTC']], $vars['incidents']);
        self::assertSame(['total' => 2, 'up' => 1, 'down' => 1, 'paused' => 0], $vars['totals']);
        self::assertSame('2026-09-13 10:00 UTC', $vars['refreshedAt']);
        self::assertFalse($vars['stale']);
        self::assertTrue($vars['incidentsAvailable']);
        self::assertTrue($vars['incidentsComplete'], 'a snapshot without the flag counts as complete');
        self::assertNull($vars['portalUrl']);
        self::assertSame('Your Acme Monitor monitors', $vars['L']['heading']);
    }

    public function testControllerDropsMonitorsAndIncidentsThatDoNotBelongToTheClientsCustomer(): void
    {
        $GLOBALS['uptimeify_test']['clientId'] = 5;
        $snapshot = self::clientSnapshot();
        // A snapshot entry for another customer's monitor and incident (e.g. written before a mapping change).
        $snapshot['monitors'][] = self::monitor('website', 'w2', 'other.example', 'down');
        $snapshot['monitors'][] = self::monitor('website', 'w-unmapped', 'unmapped.example', 'up');
        $snapshot['incidents'][] = ['publicId' => 'i9', 'title' => 'Secret', 'status' => 'open', 'startedAt' => '2026-09-13T08:00:00Z', 'monitorPublicId' => 'w2', 'monitorName' => 'other.example', 'customerPublicId' => 'c2'];
        $snapshot['incidents'][] = ['publicId' => 'i10', 'title' => 'No owner', 'status' => 'open', 'startedAt' => '2026-09-13T08:00:00Z', 'monitorPublicId' => 'w1', 'monitorName' => 'site.example'];
        (new StatusCache())->set('client:5', $snapshot);

        $vars = uptimeify_clientarea([])['vars'];

        self::assertSame(['d1', 'w1'], array_column($vars['monitors'], 'publicId'));
        self::assertSame(['Site down'], array_column($vars['incidents'], 'title'));
        self::assertSame(['total' => 2, 'up' => 1, 'down' => 1, 'paused' => 0], $vars['totals'], 'totals are recounted from what is shown');
        self::assertStringNotContainsString('other.example', (string) json_encode($vars));
        self::assertStringNotContainsString('Secret', (string) json_encode($vars));
    }

    public function testSnapshotOfAnotherCustomerIsNotShown(): void
    {
        $GLOBALS['uptimeify_test']['clientId'] = 5;
        (new StatusCache())->set('client:5', ['customerPublicId' => 'c2'] + self::clientSnapshot());
        $vars = uptimeify_clientarea([])['vars'];
        self::assertSame('unavailable', $vars['state']);
        self::assertSame([], $vars['monitors']);
        self::assertSame([], $vars['incidents']);
    }

    public function testCustomerMappedToSeveralClientsIsShownToNone(): void
    {
        (new CustomerMap())->set(7, 'c1', null, 'manual');
        $GLOBALS['uptimeify_test']['clientId'] = 5;
        $vars = uptimeify_clientarea([])['vars'];
        self::assertSame('unavailable', $vars['state']);
        self::assertSame([], $vars['monitors']);
        self::assertStringContainsString('several WHMCS clients', $GLOBALS['uptimeify_test']['activity'][0][0]);
        uptimeify_clientarea([]);
        self::assertCount(1, $GLOBALS['uptimeify_test']['activity'], 'logged once per window, not on every view');
    }

    public function testClientWithoutMappingSeesNotSetUp(): void
    {
        $GLOBALS['uptimeify_test']['clientId'] = 7;
        (new StatusCache())->set('client:7', self::clientSnapshot());
        $out = uptimeify_clientarea(['modulelink' => 'index.php?m=uptimeify']);
        self::assertSame('not_set_up', $out['vars']['state']);
        self::assertSame([], $out['vars']['monitors']);
        self::assertSame('Monitoring by Acme Monitor is not set up for your account yet.', $out['vars']['L']['not_set_up']);
    }

    public function testIncompleteIncidentWindowIsPassedToTheTemplate(): void
    {
        $snapshot = self::clientSnapshot();
        $snapshot['incidentsComplete'] = false;
        (new StatusCache())->set('client:5', $snapshot);
        $GLOBALS['uptimeify_test']['clientId'] = 5;
        $vars = uptimeify_clientarea([])['vars'];
        self::assertSame('ok', $vars['state']);
        self::assertFalse($vars['incidentsComplete']);
        self::assertSame('Open incidents may be incomplete: only the most recent incidents were checked.', $vars['L']['incidents_incomplete']);
    }

    public function testIncompleteNoteOnlyWhenTheClientHasADownMonitor(): void
    {
        $snapshot = self::clientSnapshot();
        $snapshot['incidentsComplete'] = false;
        foreach ($snapshot['monitors'] as $i => $monitor) {
            $snapshot['monitors'][$i]['status'] = 'up';
        }
        (new StatusCache())->set('client:5', $snapshot);
        $GLOBALS['uptimeify_test']['clientId'] = 5;
        self::assertTrue(uptimeify_clientarea([])['vars']['incidentsComplete'], 'all monitors up: no open incident can be missing that matters, no note');
    }

    public function testSubUserWithoutProductsPermissionSeesNoMonitoring(): void
    {
        $GLOBALS['uptimeify_test']['clientId'] = 5;
        $GLOBALS['uptimeify_test']['userPermissions'] = ['invoices', 'tickets'];
        $out = uptimeify_clientarea([]);
        self::assertTrue($out['requirelogin']);
        $vars = $out['vars'];
        self::assertSame('no_permission', $vars['state']);
        self::assertSame([], $vars['monitors']);
        self::assertSame([], $vars['incidents']);
        self::assertSame('You do not have permission to view the monitoring of this account. Please ask the account owner for access to products and services.', $vars['L']['no_permission']);
    }

    public function testSubUserWithProductsPermissionAndTheOwnerSeeTheMonitoring(): void
    {
        $GLOBALS['uptimeify_test']['clientId'] = 5;
        $GLOBALS['uptimeify_test']['userPermissions'] = ['products'];
        self::assertSame('ok', uptimeify_clientarea([])['vars']['state']);
        $GLOBALS['uptimeify_test']['userPermissions'] = null;
        self::assertSame('ok', uptimeify_clientarea([])['vars']['state'], 'the account owner always passes');
    }

    public function testPermissionIsCheckedBeforeAnyRefresh(): void
    {
        $http = new FakeHttpClient();
        $this->connect($http);
        Capsule::table('mod_uptimeify_status_cache')->where('key', 'client:5')->update(['refreshed_at' => '2020-01-01 00:00:00']);
        $GLOBALS['uptimeify_test']['clientId'] = 5;
        $GLOBALS['uptimeify_test']['userPermissions'] = [];
        self::assertSame('no_permission', uptimeify_clientarea([])['vars']['state']);
        self::assertSame([], $http->requests(), 'no API call for a user without permission');
    }

    public function testPermissionCheckFailureFailsClosed(): void
    {
        $GLOBALS['uptimeify_test']['clientId'] = 5;
        $GLOBALS['uptimeify_test']['permissionError'] = true;
        self::assertSame('no_permission', uptimeify_clientarea([])['vars']['state']);
    }

    public function testNotLoggedIn(): void
    {
        $out = uptimeify_clientarea([]);
        self::assertTrue($out['requirelogin']);
        self::assertSame('not_logged_in', $out['vars']['state']);
        self::assertTrue($out['vars']['notLoggedIn']);
        self::assertSame([], $out['vars']['monitors']);
    }

    public function testFailedRefreshShowsTheStaleSnapshotWithAHintButNoErrorText(): void
    {
        $this->connect((new FakeHttpClient())->on('GET', '/api/websites', 500, ['statusMessage' => 'internal secret detail']));
        Capsule::table('mod_uptimeify_status_cache')->where('key', 'client:5')->update(['refreshed_at' => '2020-01-01 00:00:00']);
        $GLOBALS['uptimeify_test']['clientId'] = 5;
        $vars = uptimeify_clientarea([])['vars'];
        self::assertSame('ok', $vars['state']);
        self::assertTrue($vars['stale']);
        self::assertSame(['d1', 'w1'], array_column($vars['monitors'], 'publicId'));
        self::assertStringNotContainsString('secret', (string) json_encode($vars));
    }

    public function testNoSnapshotAndFailedRefreshShowsUnavailable(): void
    {
        $this->connect((new FakeHttpClient())->on('GET', '/api/websites', 500, ['statusMessage' => 'internal secret detail']));
        Capsule::table('mod_uptimeify_status_cache')->delete();
        $GLOBALS['uptimeify_test']['clientId'] = 5;
        $vars = uptimeify_clientarea([])['vars'];
        self::assertSame('unavailable', $vars['state']);
        self::assertStringNotContainsString('secret', (string) json_encode($vars));
    }

    public function testUnexpectedErrorShowsUnavailableInsteadOfFailingThePage(): void
    {
        Capsule::schema()->drop('mod_uptimeify_status_cache');
        $GLOBALS['uptimeify_test']['clientId'] = 5;
        $out = uptimeify_clientarea([]);
        self::assertSame('unavailable', $out['vars']['state']);
        self::assertTrue($out['requirelogin']);
        self::assertStringNotContainsString('mod_uptimeify_status_cache', (string) json_encode($out));
        self::assertSame('Acme Monitor', $out['vars']['brand'], 'the brand still shows on the error page');
        self::assertStringContainsString('client area', $GLOBALS['uptimeify_test']['activity'][0][0]);
    }

    public function testPortalLinkOnlyWhenSetAndAWebUrl(): void
    {
        $GLOBALS['uptimeify_test']['clientId'] = 5;
        (new Settings())->set('status_portal_url', 'https://status.acme.example');
        self::assertSame('https://status.acme.example', uptimeify_clientarea([])['vars']['portalUrl']);
        Container::reset();
        (new Settings())->set('status_portal_url', 'javascript:alert(1)');
        self::assertNull(uptimeify_clientarea([])['vars']['portalUrl']);
    }

    public function testGermanClientLanguage(): void
    {
        $GLOBALS['uptimeify_test']['clientId'] = 5;
        $_SESSION['Language'] = 'german';
        try {
            $vars = uptimeify_clientarea([])['vars'];
        } finally {
            unset($_SESSION['Language']);
        }
        self::assertSame('Ihre Monitore bei Acme Monitor', $vars['L']['heading']);
        self::assertSame('online', $vars['monitors'][1]['statusLabel']);
    }

    private function connect(FakeHttpClient $http): void
    {
        $settings = Container::settings();
        $settings->setApiToken('wsm_test-token');
        $settings->setBool('connection_validated', true);
        Container::useHttp($http);
    }

    /** @return array<string,mixed> */
    private static function clientSnapshot(): array
    {
        $website = ['uptimeDay' => 99.95, 'uptimeMonth' => null, 'uptimeYear' => 100.0, 'avgResponseDay' => 321.4, 'openIncidents' => 2] + self::monitor('website', 'w1', 'site.example', 'up');
        $domain = ['expiresAt' => '2027-02-01T00:00:00.000Z', 'registrar' => 'Example Registrar'] + self::monitor('customer_domain', 'd1', 'site.example', 'down');
        return [
            'customerPublicId' => 'c1',
            'totals' => ['total' => 2, 'up' => 1, 'down' => 1, 'paused' => 0],
            'refreshedAt' => '2026-09-13 10:00:00',
            'incidentsAvailable' => true,
            'monitors' => [$domain, $website],
            'incidents' => [['publicId' => 'i1', 'title' => 'Site down', 'status' => 'open', 'startedAt' => '2026-09-13T08:00:00Z', 'monitorPublicId' => 'w1', 'monitorName' => 'site.example', 'customerPublicId' => 'c1']],
        ];
    }

    /** @return array<string,mixed> */
    private static function monitor(string $type, string $publicId, string $target, string $status): array
    {
        return ['type' => $type, 'publicId' => $publicId, 'name' => $target, 'target' => $target, 'status' => $status, 'uptimeDay' => null, 'uptimeMonth' => null, 'uptimeYear' => null, 'avgResponseDay' => null, 'expiresAt' => null, 'registrar' => null, 'openIncidents' => 0];
    }
}
