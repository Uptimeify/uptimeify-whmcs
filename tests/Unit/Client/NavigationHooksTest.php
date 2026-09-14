<?php

declare(strict_types=1);

namespace Uptimeify\Tests\Unit\Client;

use Uptimeify\Tests\Fake\FakeHttpClient;
use Uptimeify\Tests\Support\DbTestCase;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\Uptimeify\Container;
use WHMCS\Module\Addon\Uptimeify\Hooks\NavigationHooks;
use WHMCS\Module\Addon\Uptimeify\Settings;
use WHMCS\Module\Addon\Uptimeify\Store\CustomerMap;
use WHMCS\Module\Addon\Uptimeify\Store\MonitorMap;
use WHMCS\Module\Addon\Uptimeify\Store\Schema;
use WHMCS\Module\Addon\Uptimeify\Store\StatusCache;
use WHMCS\View\Menu\Item;

/** ClientAreaPrimaryNavbar and ClientAreaProductDetailsOutput (spec §7, §9.3, §10). */
final class NavigationHooksTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::install();
        (new CustomerMap())->set(5, 'c1', null, 'manual');
    }

    protected function tearDown(): void
    {
        Container::reset();
        parent::tearDown();
    }

    public function testNavItemLandsUnderServicesOnlyForMappedClients(): void
    {
        $GLOBALS['uptimeify_test']['clientId'] = 5;
        $menu = new Item();
        $services = $menu->addChild('Services');
        NavigationHooks::primaryNavbar($menu);
        self::assertSame(['Services'], array_keys($menu->children), 'not added at the root');
        $item = $services->getChild('uptimeifyMonitoring');
        self::assertNotNull($item);
        self::assertSame('index.php?m=uptimeify', $item->attributes['uri']);
        self::assertSame('Monitoring', $item->attributes['label']);
        self::assertSame(90, $item->attributes['order']);
        self::assertSame('fas fa-heartbeat', $item->attributes['icon']);

        $GLOBALS['uptimeify_test']['clientId'] = 8;
        $menu = new Item();
        $services = $menu->addChild('Services');
        NavigationHooks::primaryNavbar($menu);
        self::assertSame([], $services->children, 'client without mapping');

        $GLOBALS['uptimeify_test']['clientId'] = null;
        NavigationHooks::primaryNavbar($menu);
        self::assertSame([], $services->children, 'not logged in');
    }

    public function testNavItemAndProductBlockNeedTheProductsPermission(): void
    {
        $GLOBALS['uptimeify_test']['clientId'] = 5;
        $mine = $this->insert('tblhosting', ['userid' => 5, 'domain' => 'site.example', 'domainstatus' => 'Active']);
        (new MonitorMap())->upsert(['kind' => 'hosting', 'whmcs_ref_id' => $mine, 'monitor_type' => 'website', 'monitor_public_id' => 'w1', 'customer_public_id' => 'c1', 'target' => 'site.example', 'state' => 'active']);
        (new StatusCache())->set('client:5', ['customerPublicId' => 'c1', 'totals' => ['total' => 1, 'up' => 1, 'down' => 0, 'paused' => 0], 'incidentsAvailable' => true, 'refreshedAt' => '2026-09-13 10:00:00', 'incidents' => [], 'monitors' => [
            ['type' => 'website', 'publicId' => 'w1', 'name' => 'site.example', 'target' => 'site.example', 'status' => 'up', 'uptimeDay' => 100.0, 'uptimeMonth' => 100.0, 'uptimeYear' => null, 'avgResponseDay' => null, 'expiresAt' => null, 'registrar' => null, 'openIncidents' => 0],
        ]]);

        $GLOBALS['uptimeify_test']['userPermissions'] = ['invoices'];
        $menu = new Item();
        $services = $menu->addChild('Services');
        NavigationHooks::primaryNavbar($menu);
        self::assertSame([], $services->children, 'sub-user without the products permission');
        self::assertSame('', NavigationHooks::productDetailsOutput(['serviceid' => $mine]));

        $GLOBALS['uptimeify_test']['userPermissions'] = ['products'];
        NavigationHooks::primaryNavbar($menu);
        self::assertNotNull($services->getChild('uptimeifyMonitoring'));
        self::assertStringContainsString('100.00 %', NavigationHooks::productDetailsOutput(['serviceid' => $mine]));

        $GLOBALS['uptimeify_test']['userPermissions'] = null;
        self::assertStringContainsString('100.00 %', NavigationHooks::productDetailsOutput(['serviceid' => $mine]), 'the account owner');

        $GLOBALS['uptimeify_test']['permissionError'] = true;
        $menu = new Item();
        $services = $menu->addChild('Services');
        NavigationHooks::primaryNavbar($menu);
        self::assertSame([], $services->children, 'fails closed');
        self::assertSame('', NavigationHooks::productDetailsOutput(['serviceid' => $mine]));
    }

    public function testUnmappedClientCostsNoPermissionLookup(): void
    {
        $GLOBALS['uptimeify_test']['clientId'] = 8;
        $GLOBALS['uptimeify_test']['userPermissions'] = ['products'];
        $hosting = $this->insert('tblhosting', ['userid' => 8, 'domain' => 'site.example', 'domainstatus' => 'Active']);
        $menu = new Item();
        $services = $menu->addChild('Services');

        NavigationHooks::primaryNavbar($menu);
        self::assertSame('', NavigationHooks::productDetailsOutput(['serviceid' => $hosting]));

        self::assertSame([], $services->children);
        self::assertSame([], $GLOBALS['uptimeify_test']['localApi'], 'the customer map is read before GetUserPermissions');
        self::assertSame([], $GLOBALS['uptimeify_test']['activity']);
    }

    public function testNavItemFallsBackToTheRootWithoutServicesMenu(): void
    {
        $GLOBALS['uptimeify_test']['clientId'] = 5;
        $menu = new Item();
        NavigationHooks::primaryNavbar($menu);
        self::assertNotNull($menu->getChild('uptimeifyMonitoring'));
    }

    public function testNavLabelIsTranslatedAndAFailureNeverBreaksTheMenu(): void
    {
        $GLOBALS['uptimeify_test']['clientId'] = 5;
        Capsule::schema()->drop('mod_uptimeify_customer_map');
        $menu = new Item();
        NavigationHooks::primaryNavbar($menu);
        self::assertSame([], $menu->children);
        self::assertStringContainsString('navigation', $GLOBALS['uptimeify_test']['activity'][0][0]);
    }

    public function testProductBlockChecksOwnershipAndSetting(): void
    {
        $GLOBALS['uptimeify_test']['clientId'] = 5;
        (new Settings())->set('brand_name', 'Acme <b>Monitor</b>');
        $mine = $this->insert('tblhosting', ['userid' => 5, 'domain' => 'site.example', 'domainstatus' => 'Active']);
        $other = $this->insert('tblhosting', ['userid' => 6, 'domain' => 'other.example', 'domainstatus' => 'Active']);
        $map = new MonitorMap();
        $map->upsert(['kind' => 'hosting', 'whmcs_ref_id' => $mine, 'monitor_type' => 'website', 'monitor_public_id' => 'w1', 'customer_public_id' => 'c1', 'target' => 'site.example', 'state' => 'active']);
        $map->upsert(['kind' => 'hosting', 'whmcs_ref_id' => $other, 'monitor_type' => 'website', 'monitor_public_id' => 'w1', 'customer_public_id' => 'c1', 'target' => 'site.example', 'state' => 'active']);
        (new StatusCache())->set('client:5', ['customerPublicId' => 'c1', 'totals' => ['total' => 1, 'up' => 0, 'down' => 1, 'paused' => 0], 'incidentsAvailable' => true, 'refreshedAt' => '2026-09-13 10:00:00',
            'monitors' => [['type' => 'website', 'publicId' => 'w1', 'name' => '<script>x</script>', 'target' => 'site.example', 'status' => 'down', 'uptimeDay' => 90.0, 'uptimeMonth' => 99.0, 'uptimeYear' => null, 'avgResponseDay' => null, 'expiresAt' => null, 'registrar' => null, 'openIncidents' => 1]], 'incidents' => []]);

        $html = NavigationHooks::productDetailsOutput(['service' => (object) ['id' => $mine]]);
        self::assertStringContainsString('90.00 %', $html);
        self::assertStringContainsString('99.00 %', $html);
        self::assertStringContainsString('down', $html);
        self::assertStringContainsString('index.php?m=uptimeify', $html);
        self::assertStringContainsString('btn-secondary', $html);
        self::assertStringContainsString('Acme &lt;b&gt;Monitor&lt;/b&gt;', $html, 'brand escaped');
        self::assertStringNotContainsString('<b>', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertSame($html, NavigationHooks::productDetailsOutput(['serviceid' => $mine]));

        self::assertSame('', NavigationHooks::productDetailsOutput(['service' => (object) ['id' => $other]]), 'not my service');
        self::assertSame('', NavigationHooks::productDetailsOutput(['serviceid' => $other]), 'not my service');
        self::assertSame('', NavigationHooks::productDetailsOutput(['service' => (object) ['id' => 999]]), 'no such service');

        Container::reset();
        (new Settings())->setBool('product_block_enabled', false);
        self::assertSame('', NavigationHooks::productDetailsOutput(['service' => (object) ['id' => $mine]]));
    }

    public function testProductBlockShowsOnlyTheHostingsOwnMonitorOfTheOwnCustomer(): void
    {
        $GLOBALS['uptimeify_test']['clientId'] = 5;
        $withoutMonitor = $this->insert('tblhosting', ['userid' => 5, 'domain' => 'site.example', 'domainstatus' => 'Active']);
        $foreignRow = $this->insert('tblhosting', ['userid' => 5, 'domain' => 'foreign.example', 'domainstatus' => 'Active']);
        (new MonitorMap())->upsert(['kind' => 'hosting', 'whmcs_ref_id' => $foreignRow, 'monitor_type' => 'website', 'monitor_public_id' => 'w9', 'customer_public_id' => 'c9', 'target' => 'foreign.example', 'state' => 'active']);
        (new StatusCache())->set('client:5', ['customerPublicId' => 'c1', 'totals' => ['total' => 2, 'up' => 2, 'down' => 0, 'paused' => 0], 'incidentsAvailable' => true, 'refreshedAt' => '2026-09-13 10:00:00', 'incidents' => [], 'monitors' => [
            ['type' => 'website', 'publicId' => 'w1', 'name' => 'site.example', 'target' => 'site.example', 'status' => 'up', 'uptimeDay' => 100.0, 'uptimeMonth' => 100.0, 'uptimeYear' => null, 'avgResponseDay' => null, 'expiresAt' => null, 'registrar' => null, 'openIncidents' => 0],
            ['type' => 'website', 'publicId' => 'w9', 'name' => 'foreign.example', 'target' => 'foreign.example', 'status' => 'up', 'uptimeDay' => 100.0, 'uptimeMonth' => 100.0, 'uptimeYear' => null, 'avgResponseDay' => null, 'expiresAt' => null, 'registrar' => null, 'openIncidents' => 0],
        ]]);
        self::assertSame('', NavigationHooks::productDetailsOutput(['serviceid' => $withoutMonitor]), 'same domain, but no monitor of this hosting');
        self::assertSame('', NavigationHooks::productDetailsOutput(['serviceid' => $foreignRow]), 'map row of another customer');
    }

    public function testProductBlockReadsTheCacheOnlyAndNeverCallsTheApi(): void
    {
        $GLOBALS['uptimeify_test']['clientId'] = 5;
        $settings = Container::settings();
        $settings->setApiToken('wsm_test-token');
        $settings->setBool('connection_validated', true);
        $http = new FakeHttpClient();
        Container::useHttp($http);
        $mine = $this->insert('tblhosting', ['userid' => 5, 'domain' => 'site.example', 'domainstatus' => 'Active']);
        (new MonitorMap())->upsert(['kind' => 'hosting', 'whmcs_ref_id' => $mine, 'monitor_type' => 'website', 'monitor_public_id' => 'w1', 'customer_public_id' => 'c1', 'target' => 'site.example', 'state' => 'active']);
        (new StatusCache())->set('client:5', ['customerPublicId' => 'c1', 'totals' => ['total' => 1, 'up' => 1, 'down' => 0, 'paused' => 0], 'incidentsAvailable' => true, 'refreshedAt' => '2026-09-13 10:00:00', 'incidents' => [], 'monitors' => [
            ['type' => 'website', 'publicId' => 'w1', 'name' => 'site.example', 'target' => 'site.example', 'status' => 'up', 'uptimeDay' => 100.0, 'uptimeMonth' => 100.0, 'uptimeYear' => null, 'avgResponseDay' => null, 'expiresAt' => null, 'registrar' => null, 'openIncidents' => 0],
        ]]);
        Capsule::table('mod_uptimeify_status_cache')->where('key', 'client:5')->update(['refreshed_at' => '2020-01-01 00:00:00']);

        $html = NavigationHooks::productDetailsOutput(['serviceid' => $mine]);

        self::assertStringContainsString('100.00 %', $html);
        self::assertStringContainsString('may be out of date', $html, 'expired snapshot is shown with the hint');
        self::assertSame([], $http->requests());
    }

    public function testProductBlockNeverBreaksTheProductPage(): void
    {
        $GLOBALS['uptimeify_test']['clientId'] = 5;
        $mine = $this->insert('tblhosting', ['userid' => 5, 'domain' => 'site.example', 'domainstatus' => 'Active']);
        Capsule::schema()->drop('mod_uptimeify_monitor_map');
        self::assertSame('', NavigationHooks::productDetailsOutput(['serviceid' => $mine]));
        self::assertStringContainsString('product block', $GLOBALS['uptimeify_test']['activity'][0][0]);
    }
}
