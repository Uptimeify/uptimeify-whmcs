<?php

declare(strict_types=1);

namespace Uptimeify\Tests\Unit\Admin;

use Uptimeify\Tests\Fake\FakeHttpClient;
use Uptimeify\Tests\Support\DbTestCase;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\Uptimeify\Admin\Dispatcher;
use WHMCS\Module\Addon\Uptimeify\Admin\View;
use WHMCS\Module\Addon\Uptimeify\Container;
use WHMCS\Module\Addon\Uptimeify\Lang;
use WHMCS\Module\Addon\Uptimeify\Settings;
use WHMCS\Module\Addon\Uptimeify\Store\CustomerMap;
use WHMCS\Module\Addon\Uptimeify\Store\MonitorMap;
use WHMCS\Module\Addon\Uptimeify\Store\Queue;
use WHMCS\Module\Addon\Uptimeify\Store\Schema;
use WHMCS\Module\Addon\Uptimeify\Store\StatusCache;

final class DispatcherTest extends DbTestCase
{
    private const LINK = 'addonmodules.php?module=uptimeify';

    private FakeHttpClient $http;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::install();
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

    /** @return array<string,string> */
    private function vars(): array
    {
        return ['modulelink' => self::LINK, 'version' => '0.1.0'];
    }

    /**
     * @param array<string,mixed> $get
     * @param array<string,mixed> $post
     */
    private function handle(array $get, array $post = [], string $method = 'GET'): string
    {
        return (new Dispatcher())->handle($this->vars(), $get, $post, $method);
    }

    private function connect(string $token = 'wsm_x'): void
    {
        Container::settings()->setApiToken($token);
        Container::settings()->setBool('connection_validated', true);
        Container::settings()->set('organization_name', 'Acme Org');
    }

    /** @return array{type:string,message:string} */
    private static function flash(): array
    {
        self::assertIsArray($_SESSION['uptimeify_flash'] ?? null, 'a flash message was set');
        return $_SESSION['uptimeify_flash'];
    }

    /** @param array<string,scalar> $vars */
    private static function text(string $key, array $vars = []): string
    {
        return View::e(Lang::t(Lang::load('english'), $key, $vars + ['brand' => 'Uptimeify']));
    }

    // ---- setup and connect -------------------------------------------------------------

    public function testShowsSetupWhileNotConnected(): void
    {
        $html = $this->handle(['action' => 'dashboard']);
        self::assertStringContainsString('name="api_token"', $html);
        self::assertStringContainsString('name="api_base_url"', $html, 'T17-2: advanced base URL field');
        self::assertStringContainsString('value="https://uptimeify.io"', $html);
        self::assertStringContainsString('test-token', $html, 'CSRF token field present');
        self::assertStringNotContainsString('action=customers', $html, 'no tabs before the connection');
        self::assertStringNotContainsString(self::text('banner.connection_reset'), $html, 'no reset banner on a fresh install');
    }

    public function testSetupFollowsTheAdminLanguage(): void
    {
        $_SESSION['adminlang'] = 'german';
        $html = $this->handle([]);
        self::assertStringContainsString('Verbinden', $html);
    }

    public function testConnectStoresTokenAndOrgName(): void
    {
        $this->http->on('GET', '/api/organization', 200, ['id' => 1, 'publicId' => 'o1', 'name' => 'Acme Org']);
        $this->http->on('GET', '/api/custom-fields', 200, []);

        $r = $this->handle(['action' => 'connect'], ['api_token' => ' wsm_abc '], 'POST');

        self::assertSame('REDIRECT:' . self::LINK . '&action=settings', $r, 'next step: default package and types');
        self::assertSame(1, $GLOBALS['uptimeify_test']['tokenChecked']);
        $s = new Settings();
        self::assertTrue($s->isConnected());
        self::assertSame('Acme Org', $s->get('organization_name'));
        self::assertSame('o1', $s->organizationPublicId(), 'bound to the organization on the first connect (D9)');
        self::assertSame('wsm_abc', $s->getApiToken());
        self::assertSame('success', self::flash()['type']);
        self::assertStringContainsString('Acme Org', self::flash()['message']);
        self::assertSame(['/api/organization', '/api/custom-fields'], array_map(fn (array $r): string => (string) parse_url($r['url'], PHP_URL_PATH), $this->http->requests()));
    }

    public function testConnectWithBadTokenShowsError(): void
    {
        $this->http->on('GET', '/api/organization', 401, ['statusMessage' => 'Unauthorized']);
        $this->handle(['action' => 'connect'], ['api_token' => 'wsm_bad'], 'POST');
        $s = new Settings();
        self::assertFalse($s->isConnected());
        self::assertFalse($s->hasApiToken(), 'a rejected token is not stored');
        self::assertSame('error', self::flash()['type']);
        self::assertStringContainsString('HTTP 401', self::flash()['message']);
    }

    public function testCustomerScopedTokenIsRejectedWithSpecificMessage(): void
    {
        $this->http->on('GET', '/api/organization', 200, ['id' => 1, 'name' => 'X']);
        $this->http->on('GET', '/api/custom-fields', 403, ['statusMessage' => 'Forbidden', 'data' => ['code' => 'customerScopedTokenForbidden']]);
        $this->handle(['action' => 'connect'], ['api_token' => 'wsm_scoped'], 'POST');
        self::assertFalse((new Settings())->isConnected());
        self::assertFalse((new Settings())->hasApiToken());
        self::assertSame('error', self::flash()['type']);
        self::assertStringContainsString('organization-wide token', self::flash()['message']);
    }

    public function testConnectRejectsATokenWithoutPrefixBeforeAnyRequest(): void
    {
        $this->handle(['action' => 'connect'], ['api_token' => 'abc'], 'POST');
        self::assertSame([], $this->http->requests());
        self::assertSame('error', self::flash()['type']);
    }

    public function testAdvancedBaseUrlOnSetupIsValidatedAndUsedForTheHandshake(): void
    {
        $this->handle(['action' => 'connect'], ['api_token' => 'wsm_abc', 'api_base_url' => 'http://evil.example'], 'POST');
        self::assertSame([], $this->http->requests(), 'the token never goes to a disallowed host');
        self::assertSame('https://uptimeify.io', (new Settings())->apiBaseUrl());
        self::assertSame('error', self::flash()['type']);

        $this->http->on('GET', '/api/organization', 200, ['id' => 1, 'publicId' => 'o-sandbox', 'name' => 'Sandbox Org']);
        $this->http->on('GET', '/api/custom-fields', 200, []);
        $this->handle(['action' => 'connect'], ['api_token' => 'wsm_abc', 'api_base_url' => 'http://host.docker.internal:3001/'], 'POST');
        self::assertSame(['http://host.docker.internal:3001/api/organization', 'http://host.docker.internal:3001/api/custom-fields'], array_column($this->http->requests(), 'url'));
        self::assertSame('http://host.docker.internal:3001', (new Settings())->apiBaseUrl());
        self::assertTrue((new Settings())->isConnected());
    }

    public function testResetConnectionShowsTheUnauthorizedBanner(): void
    {
        Container::settings()->setApiToken('wsm_x'); // stored, but connection_validated was reset by a 401
        $html = $this->handle(['action' => 'dashboard']);
        self::assertStringContainsString('name="api_token"', $html);
        self::assertStringContainsString(self::text('banner.connection_reset'), $html);
    }

    public function testUnusableStoredTokenShowsSetupInsteadOfCrashing(): void
    {
        $this->connect();
        // The fake DecryptPassword turns this cipher into '' (as after a changed cc_encryption_hash).
        Capsule::table('tbladdonmodules')->where('module', 'uptimeify')->where('setting', 'api_token')->update(['value' => 'enc:']);
        Container::reset();
        Container::useHttp($this->http);

        $html = $this->handle(['action' => 'dashboard']);

        self::assertStringContainsString('name="api_token"', $html);
        self::assertStringContainsString(self::text('banner.connection_reset'), $html);
    }

    // ---- POST handling ----------------------------------------------------------------

    public function testEveryPostChecksTheCsrfTokenFirst(): void
    {
        $this->connect();
        $r = $this->handle(['action' => 'bogus'], [], 'POST');
        self::assertSame(1, $GLOBALS['uptimeify_test']['tokenChecked']);
        self::assertStringStartsWith('REDIRECT:', $r);
        self::assertSame('error', self::flash()['type']);
    }

    public function testIgnorePostIsTokenCheckedAndRedirectsBack(): void
    {
        $this->connect();
        $c = $this->insert('tblclients', ['companyname' => 'Acme', 'email' => 'a@example.com']);
        $this->insert('tblhosting', ['userid' => $c, 'domain' => 'x.example', 'domainstatus' => 'Active']);
        $r = $this->handle(['action' => 'ignore'], ['target' => 'x.example', 'back' => 'dashboard'], 'POST');
        self::assertSame(1, $GLOBALS['uptimeify_test']['tokenChecked']);
        self::assertSame('REDIRECT:' . self::LINK . '&action=dashboard', $r);
        self::assertSame(['x.example'], (new Settings())->ignoredTargets());
    }

    public function testAFailingCsrfCheckStopsThePostBeforeAnySideEffect(): void
    {
        $this->connect();
        $c = $this->insert('tblclients', ['companyname' => 'Acme', 'email' => 'a@example.com']);
        $this->insert('tblhosting', ['userid' => $c, 'domain' => 'x.example', 'domainstatus' => 'Active']);
        $GLOBALS['uptimeify_test']['tokenValid'] = false; // WHMCS' check_token() ends the request on a bad token

        foreach ([['ignore', ['target' => 'x.example']], ['disconnect', []], ['save_filter', ['filter_mode' => 'whitelist']]] as [$action, $post]) {
            try {
                $this->handle(['action' => $action], $post, 'POST');
                self::fail('expected the token check to stop ' . $action);
            } catch (\RuntimeException $e) {
                self::assertSame('stub: invalid CSRF token', $e->getMessage());
            }
        }

        $s = new Settings();
        self::assertSame([], $s->ignoredTargets());
        self::assertTrue($s->isConnected());
        self::assertSame('blacklist', $s->filterMode());
        self::assertArrayNotHasKey('uptimeify_flash', $_SESSION);
        self::assertSame([], $this->http->requests());
    }

    public function testEnableAndReconcileAreDispatched(): void
    {
        $this->connect();
        $c = $this->insert('tblclients', ['companyname' => 'Acme', 'email' => 'a@example.com']);
        $h = $this->insert('tblhosting', ['userid' => $c, 'domain' => 'one.example', 'domainstatus' => 'Active']);
        (new CustomerMap())->set($c, 'c1', null, 'manual');
        $page = fn (array $items): array => ['items' => $items, 'total' => count($items), 'page' => 1, 'perPage' => 100];
        $this->http
            ->on('GET', '/api/organization', 200, ['id' => 7, 'publicId' => 'o1', 'name' => 'Acme Org'])
            ->on('GET', '/api/customers', 200, $page([['id' => 1, 'publicId' => 'c1', 'name' => 'Acme', 'customFields' => ['whmcs_client_id' => (string) $c]]]))
            ->on('GET', '/api/websites', 200, $page([]))
            ->on('GET', '/api/customer-domains', 200, $page([]))
            ->on('GET', '/api/dns-monitors', 200, $page([]))
            ->on('GET', '/api/customer-ips', 200, $page([]))
            ->on('GET', '/api/tags', 200, [['id' => 1, 'publicId' => 't1', 'name' => 'whmcs', 'customerId' => null]])
            ->on('POST', '/api/monitor-tags', 200, ['id' => 1])
            ->on('POST', '/api/websites', 200, ['id' => 101, 'publicId' => 'w1', 'customerId' => 1, 'url' => 'https://one.example', 'status' => 'active'])
            ->on('GET', '/api/incidents', 200, ['incidents' => [], 'total' => 0, 'limit' => 500, 'offset' => 0]);

        $this->handle(['action' => 'enable'], ['key' => 'hosting:' . $h . ':website', 'back' => 'dashboard'], 'POST');
        self::assertSame('success', self::flash()['type']);
        self::assertSame('active', (new MonitorMap())->find('hosting', $h, 'website')['state']);

        unset($_SESSION['uptimeify_flash']);
        $this->handle(['action' => 'reconcile'], ['back' => 'dashboard'], 'POST');
        self::assertSame('success', self::flash()['type']);
        self::assertStringContainsString('Sync finished', self::flash()['message']);
    }

    public function testSyncAllIsGoneAndRefreshStatusIsDispatched(): void
    {
        $this->connect();
        $this->http
            ->on('GET', '/api/organization', 200, ['id' => 7, 'publicId' => 'o1', 'name' => 'Acme Org'])
            ->on('GET', '/api/customers', 200, ['items' => [], 'total' => 0, 'page' => 1, 'perPage' => 100]);

        $this->handle(['action' => 'sync_all'], ['back' => 'dashboard'], 'POST');
        self::assertSame(self::text('error.unknown_action'), View::e(self::flash()['message']));

        unset($_SESSION['uptimeify_flash']);
        $this->handle(['action' => 'refresh_status'], ['back' => 'dashboard'], 'POST');
        self::assertSame('success', self::flash()['type']);
        self::assertSame(2, $GLOBALS['uptimeify_test']['tokenChecked']);
        self::assertSame(['/api/organization', '/api/customers'], array_map(fn (array $r): string => (string) parse_url($r['url'], PHP_URL_PATH), $this->http->requests()));
    }

    // ---- organization binding (round 2, D9) ------------------------------------------------

    /** Bound to o1 with a working token; the API now answers with $publicId for every token. */
    private function bound(string $publicId, string $name): void
    {
        $this->connect('wsm_old-token');
        Container::settings()->set('organization_public_id', 'o1');
        $this->http->on('GET', '/api/organization', 200, ['id' => 9, 'publicId' => $publicId, 'name' => $name]);
        $this->http->on('GET', '/api/custom-fields', 200, []);
    }

    private function fillLocalTables(): void
    {
        (new CustomerMap())->set(1, 'c1', null, 'manual');
        (new MonitorMap())->upsert(['kind' => 'hosting', 'whmcs_ref_id' => 1, 'monitor_type' => 'website', 'monitor_public_id' => 'w1', 'customer_public_id' => 'c1', 'target' => 'one.example', 'state' => 'active']);
        (new Queue())->push('hosting.activate', 1);
        (new StatusCache())->set('org', ['total' => 1]);
        (new \WHMCS\Module\Addon\Uptimeify\Store\DetachedMonitors())->remember('website', 'w9', 'created', true);
    }

    /** @return array<string,int> rows per module table */
    private static function tableCounts(): array
    {
        $out = [];
        foreach (Schema::TABLES as $table) {
            $out[$table] = Capsule::table($table)->count();
        }
        return $out;
    }

    public function testConnectWithATokenOfAnotherOrganizationIsRefusedAndOffersTheSwitch(): void
    {
        $this->bound('o2', 'Other Org');
        $this->fillLocalTables();

        $this->handle(['action' => 'connect'], ['api_token' => 'wsm_new-token', 'back' => 'settings'], 'POST');

        self::assertSame('error', self::flash()['type']);
        self::assertStringContainsString('Other Org', self::flash()['message']);
        self::assertStringContainsString('Acme Org', self::flash()['message']);
        $s = new Settings();
        self::assertSame('wsm_old-token', $s->getApiToken(), 'the working connection stays');
        self::assertTrue($s->isConnected());
        self::assertSame('o1', $s->organizationPublicId());
        self::assertSame('o2', $s->pendingOrganization()['publicId'] ?? null);
        self::assertStringStartsWith('enc:', (string) $s->get('pending_api_token'), 'the pending token is stored encrypted');
        self::assertNull($s->organizationMismatch(), 'a refused connect does not stop the sync of the bound organization');
        self::assertSame(1, self::tableCounts()['mod_uptimeify_monitor_map'], 'nothing cleared without confirmation');

        $this->http->on('GET', '/api/package-configs', 200, []);
        $html = $this->handle(['action' => 'settings']);
        self::assertStringContainsString('action=switch_organization', $html);
        self::assertStringContainsString('name="organization_public_id" value="o2"', $html);
        self::assertStringContainsString('action=cancel_organization_switch', $html);
        self::assertStringContainsString(self::text('organization.switch_confirm'), $html);
        self::assertStringNotContainsString('wsm_new', $html);
        self::assertStringNotContainsString('enc:', $html);
    }

    public function testSwitchOrganizationClearsEveryLocalTableAndUsesThePendingToken(): void
    {
        $this->bound('o2', 'Other Org');
        $this->fillLocalTables();
        $this->handle(['action' => 'connect'], ['api_token' => 'wsm_new-token', 'back' => 'settings'], 'POST');
        unset($_SESSION['uptimeify_flash']);
        $requestsBefore = count($this->http->requests());

        $r = $this->handle(['action' => 'switch_organization'], ['organization_public_id' => 'o2', 'back' => 'settings'], 'POST');

        self::assertSame('REDIRECT:' . self::LINK . '&action=settings', $r);
        self::assertSame(2, $GLOBALS['uptimeify_test']['tokenChecked'], 'a separate POST, CSRF checked');
        self::assertSame('success', self::flash()['type']);
        self::assertSame(array_fill_keys(Schema::TABLES, 0), self::tableCounts(), 'customer_map, monitor_map, queue, status_cache and detached are empty');
        $s = new Settings();
        self::assertSame(['wsm_new-token', 'o2', 'Other Org', true], [$s->getApiToken(), $s->organizationPublicId(), $s->organizationName(), $s->isConnected()]);
        self::assertNull($s->pendingOrganization());
        self::assertNull($s->get('pending_api_token'));
        $switchRequests = array_slice($this->http->requests(), $requestsBefore);
        self::assertSame(['GET /api/organization'], array_map(fn (array $q): string => $q['method'] . ' ' . parse_url($q['url'], PHP_URL_PATH), $switchRequests), 'the new token is checked again; nothing is deleted remotely');
        self::assertSame('Bearer wsm_new-token', $switchRequests[0]['headers']['Authorization'] ?? null);
        self::assertNotSame([], array_filter(array_column($GLOBALS['uptimeify_test']['activity'], 0), fn (string $m): bool => str_contains($m, 'switched')));
    }

    public function testSwitchClearsADefaultPackageTheNewOrganizationDoesNotHave(): void
    {
        $this->bound('o2', 'Other Org');
        Container::settings()->set('default_package_type', 'starter');
        $this->http->on('GET', '/api/package-configs', 200, [['id' => 1, 'packageType' => 'business', 'displayName' => 'Business']]);
        $this->handle(['action' => 'connect'], ['api_token' => 'wsm_new-token', 'back' => 'settings'], 'POST');
        unset($_SESSION['uptimeify_flash']);

        $this->handle(['action' => 'switch_organization'], ['organization_public_id' => 'o2', 'back' => 'settings'], 'POST');

        self::assertSame('success', self::flash()['type']);
        self::assertSame('', (new Settings())->defaultPackageType(), 'customers of the new organization are not created against the old package key');
        self::assertStringContainsString(self::text('organization.package_cleared', ['package' => 'starter']), View::e(self::flash()['message']));
        $read = array_values(array_filter($this->http->requests(), fn (array $q): bool => parse_url($q['url'], PHP_URL_PATH) === '/api/package-configs'));
        self::assertSame('Bearer wsm_new-token', $read[0]['headers']['Authorization'] ?? null, 'read with the token of the new organization');
    }

    public function testSwitchKeepsADefaultPackageTheNewOrganizationHas(): void
    {
        $this->bound('o2', 'Other Org');
        Container::settings()->set('default_package_type', 'starter');
        $this->http->on('GET', '/api/package-configs', 200, [['id' => 1, 'packageType' => 'starter', 'displayName' => 'Starter']]);
        $this->handle(['action' => 'connect'], ['api_token' => 'wsm_new-token', 'back' => 'settings'], 'POST');

        $this->handle(['action' => 'switch_organization'], ['organization_public_id' => 'o2', 'back' => 'settings'], 'POST');

        self::assertSame('starter', (new Settings())->defaultPackageType());
    }

    public function testIgnoredTargetsAreListedAsIgnoredWithStopIgnoring(): void
    {
        $this->connect();
        Container::settings()->setIgnoredTargets(['x.example']);

        $html = $this->handle(['action' => 'dashboard']);

        self::assertStringContainsString('x.example', $html);
        self::assertStringContainsString(self::text('state.ignored'), $html);
        self::assertStringContainsString('action=unignore', $html);
        self::assertStringNotContainsString('value="ignored"', $html, 'no state filter for ignored rows: ignored targets have no rows');
    }

    public function testSwitchWithAnotherOrganizationThanOfferedOrWithoutCsrfDoesNothing(): void
    {
        $this->bound('o2', 'Other Org');
        $this->fillLocalTables();
        $this->handle(['action' => 'connect'], ['api_token' => 'wsm_new-token', 'back' => 'settings'], 'POST');
        $counts = self::tableCounts();

        $this->handle(['action' => 'switch_organization'], ['organization_public_id' => 'o3', 'back' => 'settings'], 'POST');
        self::assertSame('error', self::flash()['type']);
        self::assertSame($counts, self::tableCounts());
        self::assertSame('o1', (new Settings())->organizationPublicId());

        $GLOBALS['uptimeify_test']['tokenValid'] = false;
        try {
            $this->handle(['action' => 'switch_organization'], ['organization_public_id' => 'o2', 'back' => 'settings'], 'POST');
            self::fail('expected the CSRF check to stop the request');
        } catch (\RuntimeException) {
        }
        self::assertSame($counts, self::tableCounts());
        self::assertSame('wsm_old-token', (new Settings())->getApiToken());
    }

    public function testCancelDiscardsThePendingToken(): void
    {
        $this->bound('o2', 'Other Org');
        $this->handle(['action' => 'connect'], ['api_token' => 'wsm_new-token', 'back' => 'settings'], 'POST');
        $this->handle(['action' => 'cancel_organization_switch'], ['back' => 'settings'], 'POST');
        $s = new Settings();
        self::assertNull($s->pendingOrganization());
        self::assertNull($s->get('pending_api_token'));
        self::assertSame(['wsm_old-token', 'o1'], [$s->getApiToken(), $s->organizationPublicId()]);
        self::assertSame('success', self::flash()['type']);
    }

    public function testConnectWithATokenOfTheBoundOrganizationClearsAMismatch(): void
    {
        $this->bound('o1', 'Acme Org');
        Container::settings()->setJson('organization_mismatch', ['publicId' => 'o2', 'name' => 'Other Org']);
        $this->handle(['action' => 'connect'], ['api_token' => 'wsm_right-token', 'back' => 'settings'], 'POST');
        $s = new Settings();
        self::assertSame('wsm_right-token', $s->getApiToken());
        self::assertNull($s->organizationMismatch());
        self::assertSame('success', self::flash()['type']);
    }

    public function testRuntimeMismatchShowsABannerBlocksRemoteWritesAndCanBeSwitched(): void
    {
        $this->bound('o2', 'Other Org');
        $this->fillLocalTables();
        Container::settings()->setJson('organization_mismatch', ['publicId' => 'o2', 'name' => 'Other Org']);

        $html = $this->handle(['action' => 'dashboard']);
        self::assertStringContainsString(self::text('banner.organization_mismatch', ['new' => 'Other Org', 'bound' => 'Acme Org']), $html);

        foreach ([['pause', ['key' => 'hosting:1:website']], ['ignore', ['target' => 'one.example']], ['enable', ['key' => 'hosting:1:website']], ['set_customer', ['client_id' => '1', 'customer_public_id' => 'c1']]] as [$action, $post]) {
            unset($_SESSION['uptimeify_flash']);
            $this->handle(['action' => $action], $post + ['back' => 'dashboard'], 'POST');
            self::assertSame('error', self::flash()['type'], $action);
        }
        self::assertSame([], $this->http->requests(), 'no remote write while the token belongs to another organization');
        self::assertSame('active', (new MonitorMap())->find('hosting', 1, 'website')['state'] ?? null);

        $this->http->on('GET', '/api/package-configs', 200, []);
        $html = $this->handle(['action' => 'settings']);
        self::assertStringContainsString('name="organization_public_id" value="o2"', $html);
        self::assertStringNotContainsString('action=cancel_organization_switch', $html, 'nothing pending to discard');

        unset($_SESSION['uptimeify_flash']);
        $this->handle(['action' => 'switch_organization'], ['organization_public_id' => 'o2', 'back' => 'settings'], 'POST');
        self::assertSame('success', self::flash()['type']);
        $s = new Settings();
        self::assertSame(['wsm_old-token', 'o2', true], [$s->getApiToken(), $s->organizationPublicId(), $s->isConnected()]);
        self::assertNull($s->organizationMismatch());
        self::assertSame(array_fill_keys(Schema::TABLES, 0), self::tableCounts());
    }

    public function testRefusedConnectWhileDisconnectedOffersTheSwitchOnTheSetupPage(): void
    {
        $this->bound('o2', 'Other Org');
        Container::settings()->setApiToken(null);
        $this->handle(['action' => 'connect'], ['api_token' => 'wsm_new-token', 'back' => 'settings'], 'POST');
        self::assertFalse((new Settings())->isConnected());

        $html = $this->handle([]);
        self::assertStringContainsString('action=switch_organization', $html);

        $this->handle(['action' => 'switch_organization'], ['organization_public_id' => 'o2', 'back' => 'settings'], 'POST');
        self::assertTrue((new Settings())->isConnected());
        self::assertSame('o2', (new Settings())->organizationPublicId());
    }

    public function testRedirectOnlyGoesBackToAKnownTab(): void
    {
        $this->connect();
        $r = $this->handle(['action' => 'save_filter'], ['filter_mode' => 'whitelist', 'back' => 'https://evil.example'], 'POST');
        self::assertSame('REDIRECT:' . self::LINK . '&action=dashboard', $r);
        self::assertSame('whitelist', (new Settings())->filterMode());
    }

    public function testGetNeverRunsAnAction(): void
    {
        $this->connect();
        $html = $this->handle(['action' => 'disconnect']);
        self::assertTrue((new Settings())->isConnected());
        self::assertStringContainsString('name="q"', $html, 'unknown GET actions show the dashboard');
    }

    public function testActionsOtherThanConnectNeedAConnection(): void
    {
        $c = $this->insert('tblclients', ['companyname' => 'Acme', 'email' => 'a@example.com']);
        $this->insert('tblhosting', ['userid' => $c, 'domain' => 'x.example', 'domainstatus' => 'Active']);
        $this->handle(['action' => 'ignore'], ['target' => 'x.example'], 'POST');
        self::assertSame([], (new Settings())->ignoredTargets());
        self::assertSame('error', self::flash()['type']);
    }

    public function testUnauthorizedDuringASyncResetsTheConnection(): void
    {
        $this->connect();
        $this->http->on('GET', '/api/organization', 401, ['statusMessage' => 'Unauthorized']);

        $this->handle(['action' => 'reconcile'], ['back' => 'dashboard'], 'POST');

        self::assertFalse((new Settings())->isConnected());
        self::assertSame('error', self::flash()['type']);
        self::assertStringContainsString('HTTP 401', self::flash()['message']);
        $html = $this->handle(['action' => 'dashboard']);
        self::assertStringContainsString(self::text('banner.connection_reset'), $html);
    }

    public function testUnauthorizedInAnAdminActionResetsTheConnection(): void
    {
        $this->connect();
        (new MonitorMap())->upsert(['kind' => 'hosting', 'whmcs_ref_id' => 7, 'monitor_type' => 'website', 'monitor_public_id' => 'w1', 'customer_public_id' => 'c1', 'target' => 'one.example', 'state' => 'active']);
        $this->http->on('PATCH', '/api/websites/w1', 401, ['statusMessage' => 'Unauthorized']);

        $this->handle(['action' => 'pause'], ['key' => 'hosting:7:website'], 'POST');

        self::assertFalse((new Settings())->isConnected());
        self::assertStringContainsString('HTTP 401', self::flash()['message']);
        self::assertSame('active', (new MonitorMap())->find('hosting', 7, 'website')['state']);
    }

    public function testConnectWithANewBaseUrlThatFailsKeepsTheWorkingConnection(): void
    {
        $this->connect('wsm_old-token');
        $this->http->on('GET', '/api/organization', 401, ['statusMessage' => 'Unauthorized']);

        $this->handle(['action' => 'connect'], ['api_token' => 'wsm_new-token', 'api_base_url' => 'https://staging.uptimeify.test'], 'POST');

        self::assertSame(['https://staging.uptimeify.test/api/organization'], array_column($this->http->requests(), 'url'), 'the handshake used the posted URL');
        $s = new Settings();
        self::assertSame('https://uptimeify.io', $s->apiBaseUrl(), 'the URL is applied only after a successful handshake');
        self::assertSame('wsm_old-token', $s->getApiToken());
        self::assertTrue($s->isConnected());
    }

    public function testDisconnectAndTokenResetOnTheSettingsTab(): void
    {
        $this->connect('wsm_old-token');
        $this->http->on('GET', '/api/organization', 401, ['statusMessage' => 'Unauthorized']);
        $this->handle(['action' => 'connect'], ['api_token' => 'wsm_new-token', 'back' => 'settings'], 'POST');
        self::assertSame('wsm_old-token', (new Settings())->getApiToken(), 'a failed "set token again" keeps the working token');
        self::assertTrue((new Settings())->isConnected());

        $this->handle(['action' => 'disconnect'], ['back' => 'settings'], 'POST');
        self::assertFalse((new Settings())->hasApiToken());
    }

    // ---- rendering --------------------------------------------------------------------

    public function testSettingsPageNeverRendersTheToken(): void
    {
        $this->connect('wsm_secret-token1');
        $this->http->on('GET', '/api/package-configs', 200, [['id' => 1, 'packageType' => 'starter', 'displayName' => 'Starter', 'maxUrls' => 5]]);

        $html = $this->handle(['action' => 'settings']);

        self::assertStringNotContainsString('wsm_secret', $html);
        self::assertStringNotContainsString('enc:', $html, 'not even the stored cipher');
        self::assertStringContainsString('name="api_token"', $html, '"Token neu setzen"');
        self::assertStringContainsString('action=disconnect', $html);
        self::assertStringContainsString('Acme Org', $html);
        self::assertStringContainsString('name="api_base_url"', $html);
        self::assertStringContainsString('name="product_block_enabled"', $html);
        self::assertStringContainsString('name="drop_data_on_deactivate"', $html);
        self::assertStringContainsString('name="status_portal_url"', $html);
    }

    public function testDashboardRendersRowsWhenConnected(): void
    {
        $this->connect();
        $c = $this->insert('tblclients', ['companyname' => 'Acme', 'email' => 'a@example.com']);
        $this->insert('tblhosting', ['userid' => $c, 'domain' => 'site.example', 'domainstatus' => 'Active']);
        (new StatusCache())->set('last_reconcile', ['at' => '2026-09-14 03:00:00', 'summary' => 'customers=0 created=1', 'aborted' => false, 'abortReason' => null, 'messages' => []]);
        (new Queue())->push('hosting.activate', 5);

        $html = $this->handle(['action' => 'dashboard']);

        self::assertStringContainsString('site.example', $html);
        self::assertStringContainsString('Acme', $html);
        self::assertStringContainsString('data-state="not_monitored"', $html);
        self::assertStringContainsString('2026-09-14 03:00:00', $html);
        self::assertStringNotContainsString('action=sync_all', $html);
        self::assertStringContainsString('action=refresh_status', $html);
        self::assertStringContainsString('action=reconcile', $html);
        self::assertStringContainsString(self::text('dashboard.refresh_status'), $html);
        self::assertStringContainsString(self::text('dashboard.reconcile'), $html);
        self::assertStringContainsString('action=sync_selected', $html);
        self::assertStringContainsString('name="keys[]"', $html);
        self::assertStringContainsString(self::text('dashboard.queue', ['pending' => 1, 'running' => 0, 'dead' => 0]), $html);
        self::assertStringNotContainsString(self::text('dashboard.incidents_incomplete'), $html);

        (new StatusCache())->set('org', ['total' => 1, 'up' => 1, 'down' => 0, 'paused' => 0, 'incidents' => 0, 'incidentsAvailable' => true, 'incidentsComplete' => false]);
        self::assertStringContainsString(self::text('dashboard.incidents_incomplete'), $this->handle(['action' => 'dashboard']));
        (new StatusCache())->set('org', ['total' => 1, 'up' => 1, 'down' => 0, 'paused' => 0, 'incidents' => 0, 'incidentsAvailable' => false]);
        self::assertStringContainsString(self::text('dashboard.incidents_unavailable'), $this->handle(['action' => 'dashboard']));
    }

    public function testDashboardEscapesWhmcsAndRemoteData(): void
    {
        $this->connect();
        $c = $this->insert('tblclients', ['companyname' => '<script>alert(1)</script>', 'email' => 'a@example.com']);
        $h = $this->insert('tblhosting', ['userid' => $c, 'domain' => 'site.example', 'domainstatus' => 'Active']);
        (new MonitorMap())->upsert(['kind' => 'hosting', 'whmcs_ref_id' => $h, 'monitor_type' => 'website', 'target' => 'site.example', 'state' => 'error', 'last_error' => '"><img src=x onerror=alert(2)>']);

        $html = $this->handle(['action' => 'dashboard']);

        self::assertStringNotContainsString('<script>alert(1)', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringContainsString('&quot;&gt;&lt;img src=x', $html);

        $html = $this->handle(['action' => 'dashboard', 'q' => '"><b>', 'state' => '"><i>']);
        self::assertStringNotContainsString('"><b>', $html);
        self::assertStringContainsString('value="&quot;&gt;&lt;b&gt;"', $html);
    }

    public function testSetupErrorBannersForPackageAndTokenScope(): void
    {
        $this->connect();
        Container::settings()->set('default_package_type', 'gone');
        $map = new MonitorMap();
        $map->upsert(['kind' => 'hosting', 'whmcs_ref_id' => 1, 'monitor_type' => 'website', 'target' => 'a.example', 'state' => 'error', 'last_error' => 'invalidPackageType']);
        $map->upsert(['kind' => 'hosting', 'whmcs_ref_id' => 2, 'monitor_type' => 'website', 'target' => 'b.example', 'state' => 'error', 'last_error' => 'customerScopedTokenForbidden']);
        $map->upsert(['kind' => 'hosting', 'whmcs_ref_id' => 3, 'monitor_type' => 'website', 'target' => 'c.example', 'state' => 'error', 'last_error' => 'noDefaultPackage']);
        $map->upsert(['kind' => 'hosting', 'whmcs_ref_id' => 4, 'monitor_type' => 'website', 'target' => 'd.example', 'state' => 'error', 'last_error' => 'noDefaultPackage']);

        $html = $this->handle(['action' => 'log']);

        self::assertStringContainsString(self::text('banner.invalid_package', ['count' => 1, 'package' => 'gone']), $html);
        self::assertStringContainsString(self::text('banner.customer_scoped', ['count' => 1]), $html);
        self::assertStringContainsString(self::text('banner.no_default_package', ['count' => 2]), $html);
        self::assertStringContainsString(View::e(self::LINK . '&action=settings'), $html);
    }

    public function testCustomersTabShowsMappingSourceModeAndCachedName(): void
    {
        $this->connect();
        $a = $this->insert('tblclients', ['companyname' => 'Acme', 'email' => 'a@example.com']);
        $b = $this->insert('tblclients', ['firstname' => 'Bea', 'lastname' => 'Beta', 'email' => 'b@example.com']);
        (new CustomerMap())->set($a, 'c1', null, 'matched_email');
        Container::settings()->setCustomerFilter([$b => 'skip']);
        (new StatusCache())->set('org_customers', [['publicId' => 'c1', 'name' => 'Acme GmbH (remote)', 'email' => 'a@example.com', 'whmcsClientId' => $a]]);

        $html = $this->handle(['action' => 'customers']);

        self::assertStringContainsString('Acme GmbH (remote)', $html);
        self::assertStringContainsString(self::text('source.matched_email'), $html);
        self::assertStringContainsString('Bea Beta', $html);
        self::assertStringContainsString('<td>' . self::text('clientstatus.active') . '</td>', $html, 'WHMCS client status is translated');
        self::assertStringContainsString('<option value="skip" selected', $html);
        self::assertStringContainsString('action=set_customer', $html, '"Zuordnung neu setzen"');
        self::assertStringContainsString('<option value="c1"', $html, 'customer picker from the cached list');
        self::assertStringContainsString('name="filter_mode"', $html);
    }

    public function testClientModeOnlyForExistingClients(): void
    {
        $this->connect();
        $a = $this->insert('tblclients', ['companyname' => 'Acme', 'email' => 'a@example.com']);

        $this->handle(['action' => 'set_client_mode'], ['client_id' => '999', 'mode' => 'skip', 'back' => 'customers'], 'POST');
        self::assertSame([], (new Settings())->customerFilter());
        self::assertSame('error', self::flash()['type']);

        $this->handle(['action' => 'set_client_mode'], ['client_id' => (string) $a, 'mode' => 'sync', 'back' => 'customers'], 'POST');
        self::assertSame([$a => 'sync'], (new Settings())->customerFilter());
        $this->handle(['action' => 'set_client_mode'], ['client_id' => (string) $a, 'mode' => 'default'], 'POST');
        self::assertSame([], (new Settings())->customerFilter());
        $this->handle(['action' => 'set_client_mode'], ['client_id' => (string) $a, 'mode' => 'always'], 'POST');
        self::assertSame('error', self::flash()['type']);
    }

    public function testSetCustomerFromTheCustomersTabRefusesAHeldCustomer(): void
    {
        $this->connect();
        $a = $this->insert('tblclients', ['companyname' => 'Acme', 'email' => 'a@example.com']);
        $b = $this->insert('tblclients', ['companyname' => 'Beta', 'email' => 'b@example.com']);
        (new CustomerMap())->set($a, 'c1', null, 'created');

        $r = $this->handle(['action' => 'set_customer'], ['client_id' => (string) $b, 'customer_public_id' => 'c1', 'back' => 'customers'], 'POST');

        self::assertSame('REDIRECT:' . self::LINK . '&action=customers', $r);
        self::assertNull((new CustomerMap())->get($b));
        self::assertSame('error', self::flash()['type']);
    }

    public function testLogTabListsJobsAndRetriesOnlyDeadOnes(): void
    {
        $this->connect();
        $q = new Queue();
        $dead = $q->push('hosting.activate', 7);
        $q->markFailed($dead, 'quota <reached>', 8, null);
        $pending = $q->push('domain.upsert', 3);

        $html = $this->handle(['action' => 'log']);
        self::assertStringContainsString('hosting.activate', $html);
        self::assertStringContainsString('quota &lt;reached&gt;', $html);
        self::assertStringContainsString('action=retry_dead', $html);

        $this->handle(['action' => 'retry_dead'], ['id' => (string) $pending, 'back' => 'log'], 'POST');
        self::assertSame('error', self::flash()['type']);
        $this->handle(['action' => 'retry_dead'], ['id' => (string) $dead, 'back' => 'log'], 'POST');
        self::assertSame('success', self::flash()['type']);
        self::assertSame(['pending' => 2, 'running' => 0, 'dead' => 0], $q->counts());
    }

    public function testSidebarShowsBrandAndOrganizationEscaped(): void
    {
        self::assertStringContainsString(self::text('sidebar.not_connected'), uptimeify_sidebar($this->vars()));
        $this->connect();
        Container::settings()->set('organization_name', 'Acme & <Co>');
        Container::settings()->set('brand_name', 'Mon<i>tor');
        $html = uptimeify_sidebar($this->vars());
        self::assertStringContainsString('Acme &amp; &lt;Co&gt;', $html);
        self::assertStringContainsString('Mon&lt;i&gt;tor', $html);
    }

    public function testOutputEchoesTheRenderedPage(): void
    {
        $_GET = ['action' => 'dashboard'];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        ob_start();
        try {
            uptimeify_output($this->vars());
        } finally {
            $out = (string) ob_get_clean();
            $_GET = [];
            unset($_SERVER['REQUEST_METHOD']);
        }
        self::assertStringContainsString('name="api_token"', $out);
    }
}
