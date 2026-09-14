<?php

declare(strict_types=1);

namespace Uptimeify\Tests\Unit\Admin;

use Uptimeify\Tests\Fake\FakeHttpClient;
use Uptimeify\Tests\Support\DbTestCase;
use WHMCS\Module\Addon\Uptimeify\Admin\SettingsController;
use WHMCS\Module\Addon\Uptimeify\Container;
use WHMCS\Module\Addon\Uptimeify\Settings;
use WHMCS\Module\Addon\Uptimeify\Store\Schema;

final class SettingsControllerTest extends DbTestCase
{
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

    private function connect(): void
    {
        Container::settings()->setApiToken('wsm_x');
        Container::settings()->setBool('connection_validated', true);
    }

    /** @return array{type:string,message:string} */
    private static function flash(): array
    {
        self::assertIsArray($_SESSION['uptimeify_flash'] ?? null, 'a flash message was set');
        return $_SESSION['uptimeify_flash'];
    }

    public function testSaveSettingsWritesKnownKeysAndClampsInterval(): void
    {
        $this->connect();
        $this->http->on('GET', '/api/package-configs', 200, [['id' => 1, 'packageType' => 'starter', 'displayName' => 'Starter', 'maxUrls' => 5]]);
        (new SettingsController())->saveSettings([
            'default_package_type' => 'starter', 'auto_create_customers' => '1', 'sync_domain_dns' => '1',
            'default_check_interval' => '120', 'on_terminate' => 'delete', 'brand_name' => ' Acme ', 'unknown_key' => 'x',
            'api_base_url' => 'https://uptimeify.io/', 'default_monitoring_type' => 'ssl_check', 'status_portal_url' => 'https://status.example',
            'form_bools' => SettingsController::BOOL_KEYS,
        ]);
        $s = new Settings();
        self::assertSame('starter', $s->get('default_package_type'));
        self::assertTrue($s->typeEnabled('domain_dns'));
        self::assertFalse($s->typeEnabled('hosting_website'), 'unchecked checkbox of the submitted form means false');
        self::assertTrue($s->autoCreateCustomers());
        self::assertFalse($s->autoSync());
        self::assertSame(60, $s->defaultCheckInterval());
        self::assertSame('60', $s->get('default_check_interval'));
        self::assertSame('delete', $s->onTerminate());
        self::assertSame('Acme', $s->brandName());
        self::assertSame('ssl_check', $s->defaultMonitoringType());
        self::assertSame('https://status.example', $s->statusPortalUrl());
        self::assertNull($s->get('unknown_key'));
        self::assertTrue($s->isConnected(), 'an unchanged base URL keeps the connection');
        self::assertSame('success', self::flash()['type']);
    }

    public function testPartialPostDoesNotResetBooleans(): void
    {
        Container::settings()->setBool('sync_domain_dns', true);
        Container::settings()->setBool('auto_sync', true);

        (new SettingsController())->saveSettings(['brand_name' => 'Acme']);
        (new SettingsController())->saveSettings(['brand_name' => 'Acme', 'form_bools' => ['sync_domain_dns', 'not_a_setting'], 'auto_sync' => '0']);

        $s = new Settings();
        self::assertFalse($s->typeEnabled('domain_dns'), 'listed in form_bools and not posted: false');
        self::assertTrue($s->autoSync(), 'not listed in form_bools: untouched although posted');
        self::assertNull($s->get('not_a_setting'));
    }

    public function testInsecureBaseUrlIsRejectedUnlessLocal(): void
    {
        $c = new SettingsController();
        foreach (['http://evil.example', 'http://127.0.0.1:3001', 'http://localhost.evil.com', 'https://uptimeify.io@evil'] as $url) {
            $_SESSION = [];
            $c->saveSettings(['api_base_url' => $url]);
            self::assertSame('https://uptimeify.io', (new Settings())->apiBaseUrl(), $url);
            self::assertSame('error', self::flash()['type'], $url);
        }
        $c->saveSettings(['api_base_url' => 'http://host.docker.internal:3001/']);
        self::assertSame('http://host.docker.internal:3001', (new Settings())->apiBaseUrl());
    }

    public function testChangingTheBaseUrlResetsTheConnection(): void
    {
        $this->connect();
        Container::settings()->set('organization_name', 'Acme Org');

        (new SettingsController())->saveSettings(['api_base_url' => 'https://staging.uptimeify.test']);

        $s = new Settings();
        self::assertSame('https://staging.uptimeify.test', $s->apiBaseUrl());
        self::assertFalse($s->hasApiToken(), 'the token is never sent to a host it was not verified against');
        self::assertFalse($s->isConnected());
        self::assertSame([], $this->http->requests());
    }

    public function testInvalidMonitoringTypeAndPortalUrlAreRejected(): void
    {
        (new SettingsController())->saveSettings(['default_monitoring_type' => 'playwright', 'status_portal_url' => 'javascript:alert(1)', 'brand_name' => 'Kept']);

        $s = new Settings();
        self::assertNull($s->get('default_monitoring_type'));
        self::assertNull($s->get('status_portal_url'));
        self::assertSame('Kept', $s->brandName(), 'valid keys of the same form are still saved');
        self::assertSame('error', self::flash()['type']);
    }

    public function testEmptyPortalUrlClearsIt(): void
    {
        Container::settings()->set('status_portal_url', 'https://status.example');
        (new SettingsController())->saveSettings(['status_portal_url' => ' ']);
        self::assertNull((new Settings())->statusPortalUrl());
    }

    public function testUnknownDefaultPackageIsRejected(): void
    {
        $this->connect();
        $this->http->on('GET', '/api/package-configs', 200, [['id' => 1, 'packageType' => 'starter', 'displayName' => 'Starter']]);

        (new SettingsController())->saveSettings(['default_package_type' => 'Starter']);

        self::assertNull((new Settings())->get('default_package_type'), 'the display name is not the package key');
        self::assertSame('error', self::flash()['type']);
        self::assertStringContainsString('Starter', self::flash()['message']);
    }

    public function testPackageIsNotChangedWhenThePackageListCannotBeLoaded(): void
    {
        $this->connect();
        Container::settings()->set('default_package_type', 'starter');
        $this->http->on('GET', '/api/package-configs', 500, ['statusMessage' => 'boom']);

        (new SettingsController())->saveSettings(['default_package_type' => 'pro']);
        self::assertSame('starter', (new Settings())->get('default_package_type'));
        self::assertSame('error', self::flash()['type']);

        $_SESSION = [];
        (new SettingsController())->saveSettings(['default_package_type' => 'starter']);
        self::assertCount(1, $this->http->requests(), 'an unchanged package is not looked up again');
        self::assertSame('success', self::flash()['type']);
    }

    public function testSettingsPageListsPackagesAndFlagsAMissingDefaultPackage(): void
    {
        $this->connect();
        Container::settings()->set('default_package_type', 'gone');
        $this->http->on('GET', '/api/package-configs', 200, [['id' => 1, 'packageType' => 'starter', 'displayName' => 'Starter <b>', 'maxUrls' => 5]]);

        $html = (new SettingsController())->showSettings(self::ctx());

        self::assertStringContainsString('<option value="starter"', $html);
        self::assertStringContainsString('Starter &lt;b&gt;', $html);
        self::assertStringContainsString('Choose the default package again', $html);
        self::assertStringContainsString('name="form_bools[]" value="sync_server_ips"', $html);
    }

    public function testSettingsPageSurvivesAnUnreachableApi(): void
    {
        $this->connect();
        Container::settings()->set('default_package_type', 'starter');
        $this->http->on('GET', '/api/package-configs', 401, ['statusMessage' => 'Unauthorized']);

        $html = (new SettingsController())->showSettings(self::ctx());

        self::assertStringContainsString('name="default_package_type"', $html);
        self::assertStringContainsString('<option value="starter" selected', $html, 'the stored package stays selectable');
        self::assertStringContainsString('could not be loaded', $html);
        self::assertFalse((new Settings())->isConnected(), 'spec 5: a 401 resets the connection');
    }

    public function testDisconnectForgetsTheTokenButKeepsTheOrganizationBinding(): void
    {
        $this->connect();
        Container::settings()->set('organization_name', 'Acme Org');
        Container::settings()->set('organization_public_id', 'o1');
        Container::settings()->setJson('organization_pending', ['publicId' => 'o2', 'name' => 'Other', 'apiBaseUrl' => 'https://uptimeify.io']);
        Container::settings()->set('pending_api_token', 'enc:x');
        (new SettingsController())->disconnect();
        $s = new Settings();
        self::assertFalse($s->hasApiToken());
        self::assertFalse($s->isConnected());
        self::assertSame(['Acme Org', 'o1'], [$s->organizationName(), $s->organizationPublicId()], 'the local data still belongs to that organization (D9)');
        self::assertNull($s->pendingOrganization());
        self::assertNull($s->get('pending_api_token'));
    }

    /** @return array<string,mixed> */
    private static function ctx(): array
    {
        return [
            't' => static fn (string $key, array $vars = []): string => \WHMCS\Module\Addon\Uptimeify\Admin\View::t($key, $vars),
            'modulelink' => 'addonmodules.php?module=uptimeify',
            'tokenField' => '<input type="hidden" name="token" value="test-token">',
            'brand' => 'Uptimeify',
            'flash' => null,
            'banners' => [],
            'version' => '0.1.0',
            'tab' => 'settings',
        ];
    }
}
