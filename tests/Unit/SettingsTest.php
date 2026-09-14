<?php

declare(strict_types=1);

namespace Uptimeify\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use Uptimeify\Tests\Support\DbTestCase;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\Uptimeify\Settings;

final class SettingsTest extends DbTestCase
{
    public function testDefaultsWhenNothingStored(): void
    {
        $s = new Settings();
        self::assertSame('https://uptimeify.io', $s->apiBaseUrl());
        self::assertSame('Uptimeify', $s->brandName());
        self::assertSame(5, $s->defaultCheckInterval());
        self::assertSame('combined', $s->defaultMonitoringType());
        self::assertSame('pause', $s->onTerminate());
        self::assertTrue($s->typeEnabled('hosting_website'));
        self::assertFalse($s->typeEnabled('domain_dns'));
        self::assertTrue($s->autoCreateCustomers());
        self::assertFalse($s->hasApiToken());
        self::assertFalse($s->isConnected());
        self::assertSame([], $s->customerFilter());
    }

    public function testRemainingDefaultsWhenNothingStored(): void
    {
        $s = new Settings();
        self::assertFalse($s->typeEnabled('hosting_domain_expiry'));
        self::assertTrue($s->typeEnabled('domain_expiry'));
        self::assertFalse($s->typeEnabled('server_ips'));
        self::assertFalse($s->typeEnabled('unknown_type'));
        self::assertTrue($s->autoSync());
        self::assertSame('blacklist', $s->filterMode());
        self::assertSame([], $s->ignoredTargets());
        self::assertNull($s->getApiToken());
        self::assertSame('', $s->organizationName());
        self::assertSame('', $s->defaultPackageType());
        self::assertNull($s->statusPortalUrl());
        self::assertTrue($s->productBlockEnabled());
        self::assertFalse($s->dropDataOnDeactivate());
    }

    public function testEverySpecKeyIsListed(): void
    {
        self::assertSame([
            'api_token', 'api_base_url', 'connection_validated', 'organization_name', 'default_package_type',
            'auto_create_customers', 'auto_sync', 'sync_hosting_website', 'sync_hosting_domain_expiry',
            'sync_domain_expiry', 'sync_domain_dns', 'sync_server_ips', 'default_check_interval',
            'default_monitoring_type', 'on_terminate', 'filter_mode', 'customer_filter', 'ignored_targets',
            'brand_name', 'status_portal_url', 'product_block_enabled', 'drop_data_on_deactivate',
            'organization_public_id', 'organization_mismatch', 'organization_pending', 'pending_api_token',
        ], Settings::KEYS);
    }

    public function testSetAndGetRoundTripAndDelete(): void
    {
        $s = new Settings();
        $s->set('brand_name', 'Acme Hosting');
        self::assertSame('Acme Hosting', (new Settings())->brandName());
        self::assertSame(1, Capsule::table('tbladdonmodules')->where('module', 'uptimeify')->count());
        $s->set('brand_name', 'Acme 2');
        self::assertSame(1, Capsule::table('tbladdonmodules')->where('module', 'uptimeify')->count());
        self::assertSame('Acme 2', $s->brandName(), 'the writing instance sees its own write');
        $s->set('brand_name', null);
        self::assertSame('Uptimeify', (new Settings())->brandName());
        self::assertSame(0, Capsule::table('tbladdonmodules')->where('module', 'uptimeify')->count());
    }

    public function testOtherModulesRowsAreIgnoredAndUntouched(): void
    {
        $this->insert('tbladdonmodules', ['module' => 'other', 'setting' => 'brand_name', 'value' => 'Other']);
        $s = new Settings();
        self::assertSame('Uptimeify', $s->brandName());
        $s->set('brand_name', 'Mine');
        $s->set('brand_name', null);
        self::assertSame('Other', Capsule::table('tbladdonmodules')->where('module', 'other')->value('value'));
    }

    public function testTokenIsStoredEncryptedAndResetsValidation(): void
    {
        $s = new Settings();
        $s->set('connection_validated', '1');
        $s->setApiToken('wsm_abc');
        $raw = (string) Capsule::table('tbladdonmodules')->where('setting', 'api_token')->value('value');
        self::assertStringNotContainsString('wsm_abc', $raw);
        self::assertSame('wsm_abc', $s->getApiToken());
        self::assertFalse($s->isConnected());
    }

    public function testConnectedNeedsTokenAndValidationAndClearingTokenDisconnects(): void
    {
        $s = new Settings();
        $s->setBool('connection_validated', true);
        self::assertFalse($s->isConnected(), 'validated flag without token is not connected');
        $s->setApiToken('wsm_abc');
        $s->setBool('connection_validated', true);
        self::assertTrue($s->hasApiToken());
        self::assertTrue((new Settings())->isConnected());
        $s->setApiToken(null);
        self::assertFalse($s->hasApiToken());
        self::assertNull($s->getApiToken());
        self::assertFalse($s->isConnected());
        self::assertNull(Capsule::table('tbladdonmodules')->where('setting', 'api_token')->value('value'));
        $s->setApiToken('');
        self::assertFalse($s->hasApiToken());
    }

    public function testBoolParsingAcceptsWhmcsCheckboxValues(): void
    {
        $s = new Settings();
        foreach (['1' => true, 'on' => true, 'true' => true, '0' => false, '' => false, 'off' => false] as $raw => $expected) {
            $s->set('auto_sync', (string) $raw);
            self::assertSame($expected, $s->autoSync(), 'raw value "' . $raw . '"');
        }
        $s->setBool('product_block_enabled', false);
        self::assertFalse($s->productBlockEnabled());
        $s->setBool('drop_data_on_deactivate', true);
        self::assertTrue($s->dropDataOnDeactivate());
        $s->setBool('sync_domain_dns', true);
        self::assertTrue($s->typeEnabled('domain_dns'));
    }

    public function testGetIntFallsBackOnNonNumeric(): void
    {
        $s = new Settings();
        $s->set('default_check_interval', 'abc');
        self::assertSame(5, $s->defaultCheckInterval());
        $s->set('default_check_interval', '15');
        self::assertSame(15, $s->defaultCheckInterval());
    }

    public function testCheckIntervalIsClamped(): void
    {
        $s = new Settings();
        $s->set('default_check_interval', '999');
        self::assertSame(60, $s->defaultCheckInterval());
        $s->set('default_check_interval', '0');
        self::assertSame(1, $s->defaultCheckInterval());
    }

    public function testMonitoringTypeOnlyAllowsUrlBasedTypes(): void
    {
        $s = new Settings();
        foreach (['combined', 'http_status', 'ssl_check'] as $type) {
            $s->set('default_monitoring_type', $type);
            self::assertSame($type, $s->defaultMonitoringType());
        }
        foreach (['heartbeat', 'playwright', 'dns', '', 'bogus'] as $type) {
            $s->set('default_monitoring_type', $type);
            self::assertSame('combined', $s->defaultMonitoringType(), 'stored "' . $type . '"');
        }
        self::assertSame(['combined', 'http_status', 'ssl_check'], Settings::MONITORING_TYPES);
    }

    public function testEnumsFallBackToDefaults(): void
    {
        $s = new Settings();
        $s->set('on_terminate', 'delete');
        $s->set('filter_mode', 'whitelist');
        self::assertSame('delete', $s->onTerminate());
        self::assertSame('whitelist', $s->filterMode());
        $s->set('on_terminate', 'nuke');
        $s->set('filter_mode', 'greylist');
        self::assertSame('pause', $s->onTerminate());
        self::assertSame('blacklist', $s->filterMode());
    }

    public function testTextGettersTrim(): void
    {
        $s = new Settings();
        $s->set('brand_name', '   ');
        $s->set('organization_name', ' Acme Org ');
        $s->set('default_package_type', ' starter ');
        $s->set('status_portal_url', '  ');
        self::assertSame('Uptimeify', $s->brandName());
        self::assertSame('Acme Org', $s->organizationName());
        self::assertSame('starter', $s->defaultPackageType());
        self::assertNull($s->statusPortalUrl());
        $s->set('status_portal_url', 'https://status.example.test');
        self::assertSame('https://status.example.test', $s->statusPortalUrl());
    }

    public function testJsonHelpersAndBaseUrlTrim(): void
    {
        $s = new Settings();
        $s->setCustomerFilter([3 => 'skip', 9 => 'sync']);
        self::assertSame([3 => 'skip', 9 => 'sync'], (new Settings())->customerFilter());
        $s->setIgnoredTargets(['a.test', 'b.test']);
        self::assertSame(['a.test', 'b.test'], (new Settings())->ignoredTargets());
        $s->set('api_base_url', 'http://host.docker.internal:3001/');
        self::assertSame('http://host.docker.internal:3001', $s->apiBaseUrl());
    }

    public function testJsonGettersTolerateGarbage(): void
    {
        $s = new Settings();
        $s->set('customer_filter', '{not json');
        self::assertSame([], $s->customerFilter());
        $s->set('customer_filter', '{"3":"skip","4":"maybe","5":["x"]}');
        self::assertSame([3 => 'skip'], $s->customerFilter());
        $s->set('ignored_targets', '["a.test",["nested"],null,7]');
        self::assertSame(['a.test', '7'], $s->ignoredTargets());
        $s->set('ignored_targets', '"scalar"');
        self::assertSame([], $s->ignoredTargets());
        $s->setIgnoredTargets(['a.test', 'a.test', 'b.test']);
        self::assertSame(['a.test', 'b.test'], $s->ignoredTargets());
        $s->setJson('customer_filter', []);
        self::assertSame([], $s->getJson('customer_filter'));
    }

    public function testBlankBaseUrlFallsBackToDefault(): void
    {
        $s = new Settings();
        $s->set('api_base_url', '  ');
        self::assertSame('https://uptimeify.io', $s->apiBaseUrl());
    }

    #[DataProvider('baseUrls')]
    public function testBaseUrlIsHttpsOnlyExceptForTheSandboxHosts(string $url, bool $allowed): void
    {
        self::assertSame($allowed, Settings::isAllowedBaseUrl($url), $url);
    }

    /** @return iterable<string,array{string,bool}> */
    public static function baseUrls(): iterable
    {
        yield 'default' => ['https://uptimeify.io', true];
        yield 'https with port and path' => ['https://api.example.test:8443/base', true];
        yield 'https upper-case scheme' => ['HTTPS://uptimeify.io', true];
        yield 'http localhost' => ['http://localhost:3001', true];
        yield 'http sandbox host' => ['http://host.docker.internal:3001', true];
        yield 'http sandbox host upper-case' => ['http://HOST.DOCKER.INTERNAL:3001', true];
        yield 'http remote host' => ['http://evil.example', false];
        yield 'http loopback ip' => ['http://127.0.0.1:3001', false];
        yield 'http localhost as userinfo' => ['http://localhost@evil.example', false];
        yield 'https with userinfo' => ['https://user:pass@uptimeify.io', false];
        yield 'http localhost as subdomain prefix' => ['http://localhost.evil.com', false];
        yield 'https host as userinfo' => ['https://uptimeify.io@evil', false];
        yield 'query string' => ['https://uptimeify.io/?x=1', false];
        yield 'fragment' => ['https://uptimeify.io/#x', false];
        yield 'no scheme' => ['uptimeify.io', false];
        yield 'other scheme' => ['ftp://uptimeify.io', false];
        yield 'empty' => ['', false];
        yield 'no host' => ['https://', false];
    }
}
