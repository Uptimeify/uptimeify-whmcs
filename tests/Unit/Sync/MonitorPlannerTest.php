<?php

declare(strict_types=1);

namespace Uptimeify\Tests\Unit\Sync;

use Uptimeify\Tests\Support\DbTestCase;
use WHMCS\Module\Addon\Uptimeify\Settings;
use WHMCS\Module\Addon\Uptimeify\Store\MonitorMap;
use WHMCS\Module\Addon\Uptimeify\Sync\CustomerFilter;
use WHMCS\Module\Addon\Uptimeify\Sync\MonitorPlanner;
use WHMCS\Module\Addon\Uptimeify\Sync\PlannedMonitor;
use WHMCS\Module\Addon\Uptimeify\Whmcs\ClientRepository;
use WHMCS\Module\Addon\Uptimeify\Whmcs\DomainRepository;
use WHMCS\Module\Addon\Uptimeify\Whmcs\HostingRepository;
use WHMCS\Module\Addon\Uptimeify\Whmcs\ServerRepository;

final class MonitorPlannerTest extends DbTestCase
{
    private int $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = $this->insert('tblclients', ['companyname' => 'Acme', 'email' => 'acme@example.com']);
        $this->insert('tblservers', ['name' => 'web1', 'ipaddress' => '203.0.113.10']);
        $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'WWW.Site.Example', 'domainstatus' => 'Active', 'server' => 1]);
        $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'gone.example', 'domainstatus' => 'Terminated', 'server' => 1]);
        $this->insert('tbldomains', ['userid' => $this->client, 'domain' => 'reg.example', 'status' => 'Active']);
    }

    private function planner(Settings $s): MonitorPlanner
    {
        return new MonitorPlanner($s, new CustomerFilter($s), new ClientRepository(), new HostingRepository(), new DomainRepository(), new ServerRepository());
    }

    /**
     * @param list<PlannedMonitor> $plans
     * @return list<string>
     */
    private static function keys(array $plans): array
    {
        return array_map(fn (PlannedMonitor $p): string => $p->key(), $plans);
    }

    private static function allTypes(): Settings
    {
        $s = new Settings();
        foreach (['sync_hosting_domain_expiry', 'sync_domain_dns', 'sync_server_ips'] as $k) {
            $s->setBool($k, true);
        }
        return $s;
    }

    public function testDefaultsPlanWebsiteAndDomainExpiryOnly(): void
    {
        $plans = $this->planner(new Settings())->planAll();
        self::assertSame(['hosting:1:website', 'domain:1:customer_domain'], self::keys($plans));
        self::assertSame('site.example', $plans[0]->target);
        self::assertSame('site.example', $plans[0]->name);
        self::assertSame('https://site.example', $plans[0]->extra['url']);
        self::assertSame($this->client, $plans[0]->clientId);
        self::assertSame('reg.example', $plans[1]->target);
        self::assertSame('reg.example', $plans[1]->extra['label']);
    }

    public function testWebsiteCarriesSettingsAndHostingCustomField(): void
    {
        $s = new Settings();
        $s->set('default_check_interval', '15');
        $s->set('default_monitoring_type', 'ssl_check');
        $site = $this->planner($s)->planAll()[0];
        self::assertSame(15, $site->extra['checkInterval']);
        self::assertSame('ssl_check', $site->extra['monitoringType']);
        self::assertSame(['whmcs_hosting_id' => '1'], $site->extra['customFields']);

        $s->set('default_check_interval', '9999');
        $s->set('default_monitoring_type', 'playwright');
        $site = $this->planner($s)->planAll()[0];
        self::assertSame(Settings::CHECK_INTERVAL_MAX, $site->extra['checkInterval'], 'validated by Settings');
        self::assertSame('combined', $site->extra['monitoringType'], 'validated by Settings');
    }

    public function testAllTypesEnabled(): void
    {
        $s = self::allTypes();
        $keys = self::keys($this->planner($s)->planAll());
        sort($keys);
        $expectedIpRef = 1 * 10_000_000_000 + $this->client;
        self::assertSame(
            ['domain:1:customer_domain', 'domain:1:dns', 'hosting:1:customer_domain', 'hosting:1:website', "server_ip:{$expectedIpRef}:customer_ip"],
            $keys,
        );
        $ip = array_values(array_filter($this->planner($s)->planAll(), fn ($p) => $p->monitorType === MonitorMap::TYPE_CUSTOMER_IP))[0];
        self::assertSame(MonitorMap::KIND_SERVER_IP, $ip->kind);
        self::assertSame('203.0.113.10', $ip->target);
        self::assertSame('web1', $ip->name);
        self::assertSame('web1', $ip->extra['label']);
        self::assertSame(1, $ip->extra['serverId']);
        self::assertSame($this->client, $ip->clientId);
    }

    public function testDnsMonitorUsesApiAcceptedConfig(): void
    {
        $dns = array_values(array_filter($this->planner(self::allTypes())->planAll(), fn ($p) => $p->monitorType === MonitorMap::TYPE_DNS))[0];
        self::assertSame('reg.example', $dns->target);
        self::assertSame('reg.example', $dns->name);
        self::assertSame(
            ['rrtypes' => ['A', 'NS'], 'matchMode' => 'contains', 'expectedValues' => ['A' => ['.'], 'NS' => ['.']], 'triggerOn' => ['resolveError' => true, 'mismatch' => false]],
            $dns->extra['dnsConfig'],
        );
        self::assertArrayNotHasKey('customFields', $dns->extra, 'only websites carry customFields');
    }

    public function testTypeSwitchesCanDisableDefaults(): void
    {
        $s = new Settings();
        $s->setBool('sync_hosting_website', false);
        $s->setBool('sync_domain_expiry', false);
        self::assertSame([], $this->planner($s)->planAll());
    }

    public function testFilterAndIgnoreRemovePlans(): void
    {
        $s = new Settings();
        $s->setIgnoredTargets(['site.example']);
        self::assertSame(['domain:1:customer_domain'], self::keys($this->planner($s)->planAll()));
        $s->setCustomerFilter([$this->client => 'skip']);
        self::assertSame([], $this->planner($s)->planAll());
    }

    public function testFilterAndIgnoreApplyToServerIps(): void
    {
        $s = self::allTypes();
        $s->setBool('sync_hosting_website', false);
        $s->setBool('sync_hosting_domain_expiry', false);
        $s->setBool('sync_domain_expiry', false);
        $s->setBool('sync_domain_dns', false);
        self::assertCount(1, $this->planner($s)->planAll());

        $s->setIgnoredTargets(['203.0.113.10']);
        self::assertSame([], $this->planner($s)->planAll(), 'ignored IP');

        $s->setIgnoredTargets([]);
        $s->set('filter_mode', 'whitelist');
        self::assertSame([], $this->planner($s)->planAll(), 'whitelist without explicit sync');
        $s->setCustomerFilter([$this->client => 'sync']);
        self::assertCount(1, $this->planner($s)->planAll());
    }

    public function testServerIpNeedsActiveHostingAndEnabledServer(): void
    {
        $s = self::allTypes();
        $other = $this->insert('tblclients', ['companyname' => 'Other', 'email' => 'other@example.com']);
        $this->insert('tblhosting', ['userid' => $other, 'domain' => 'other.example', 'domainstatus' => 'Suspended', 'server' => 1]);
        $this->insert('tblservers', ['name' => 'off', 'ipaddress' => '203.0.113.20', 'disabled' => 1]);
        $v6 = $this->insert('tblservers', ['name' => 'v6', 'ipaddress' => '2001:DB8:0:0:0:0:0:1']);
        $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'two.example', 'domainstatus' => 'Active', 'server' => $v6]);
        $this->insert('tblhosting', ['userid' => $this->client, 'domain' => 'off.example', 'domainstatus' => 'Active', 'server' => 2]);

        $ips = array_values(array_filter($this->planner($s)->planAll(), fn ($p) => $p->monitorType === MonitorMap::TYPE_CUSTOMER_IP));
        self::assertSame(['203.0.113.10', '2001:db8::1'], array_map(fn ($p) => $p->target, $ips));
        self::assertSame([$this->client, $this->client], array_map(fn ($p) => $p->clientId, $ips));
        self::assertSame(MonitorPlanner::serverIpRefId($v6, $this->client), $ips[1]->refId);
    }

    public function testPlanForSingleRefs(): void
    {
        $p = $this->planner(new Settings());
        self::assertSame(['hosting:1:website'], self::keys($p->planForHosting(1)));
        self::assertSame([], $p->planForHosting(2), 'terminated hosting has no plan');
        self::assertSame([], $p->planForHosting(99), 'unknown hosting has no plan');
        self::assertSame(['domain:1:customer_domain'], self::keys($p->planForDomain(1)));
        self::assertSame([], $p->planForDomain(99));
        self::assertCount(2, $p->planForClient($this->client));
        self::assertSame([], $p->planForClient(99));
    }

    public function testPlanForHostingIncludesItsServerIp(): void
    {
        $all = $this->planner(self::allTypes());
        $ref = MonitorPlanner::serverIpRefId(1, $this->client);
        self::assertSame(['hosting:1:website', 'hosting:1:customer_domain', "server_ip:{$ref}:customer_ip"], self::keys($all->planForHosting(1)));
        self::assertSame(['domain:1:customer_domain', 'domain:1:dns'], self::keys($all->planForDomain(1)));
        self::assertCount(5, $all->planForClient($this->client));
    }

    public function testDomainlessActiveHostingCountsForTheServerIp(): void
    {
        $s = self::allTypes();
        $vps = $this->insert('tblservers', ['name' => 'vps-node', 'ipaddress' => '203.0.113.30']);
        $other = $this->insert('tblclients', ['companyname' => 'VPS Ltd', 'email' => 'vps@example.com']);
        $h = $this->insert('tblhosting', ['userid' => $other, 'domain' => '', 'domainstatus' => 'Active', 'server' => $vps]);
        $ref = MonitorPlanner::serverIpRefId($vps, $other);

        self::assertContains("server_ip:{$ref}:customer_ip", self::keys($this->planner($s)->planAll()));
        self::assertSame(["server_ip:{$ref}:customer_ip"], self::keys($this->planner($s)->planForHosting($h)), 'no website or domain expiry without a domain');
    }

    public function testConfigExclusionNamesTheSettingThatLeavesAWantedMonitorOut(): void
    {
        $ipRef = MonitorPlanner::serverIpRefId(1, $this->client);
        $all = self::allTypes();
        foreach ([['hosting', 1, 'website'], ['hosting', 1, 'customer_domain'], ['domain', 1, 'customer_domain'], ['domain', 1, 'dns'], ['server_ip', $ipRef, 'customer_ip']] as [$kind, $ref, $type]) {
            self::assertNull($this->planner($all)->configExclusion($kind, $ref, $type), "{$kind}:{$type} is planned, nothing excludes it");
        }

        $off = new Settings();
        foreach (['sync_hosting_website', 'sync_hosting_domain_expiry', 'sync_domain_expiry', 'sync_domain_dns', 'sync_server_ips'] as $key) {
            $off->setBool($key, false);
        }
        foreach ([['hosting', 1, 'website'], ['hosting', 1, 'customer_domain'], ['domain', 1, 'customer_domain'], ['domain', 1, 'dns'], ['server_ip', $ipRef, 'customer_ip']] as [$kind, $ref, $type]) {
            self::assertSame(MonitorPlanner::EXCLUDED_TYPE_DISABLED, $this->planner($off)->configExclusion($kind, $ref, $type), "{$kind}:{$type} switched off");
        }
        foreach (['sync_hosting_website', 'sync_domain_expiry'] as $key) {
            $off->setBool($key, true); // settings share one table: back on for the cases below
        }

        $never = self::allTypes();
        $never->setCustomerFilter([$this->client => 'skip']);
        self::assertSame(MonitorPlanner::EXCLUDED_CLIENT_FILTER, $this->planner($never)->configExclusion('hosting', 1, 'website'));
        self::assertSame(MonitorPlanner::EXCLUDED_CLIENT_FILTER, $this->planner($never)->configExclusion('server_ip', $ipRef, 'customer_ip'));
        $whitelist = self::allTypes();
        $whitelist->set('filter_mode', 'whitelist');
        self::assertSame(MonitorPlanner::EXCLUDED_CLIENT_FILTER, $this->planner($whitelist)->configExclusion('domain', 1, 'dns'));
        $whitelist->set('filter_mode', 'blacklist');
        $whitelist->setCustomerFilter([]);

        $ignored = self::allTypes();
        $ignored->setIgnoredTargets(['site.example', 'reg.example', '203.0.113.10']);
        self::assertSame(MonitorPlanner::EXCLUDED_IGNORED_TARGET, $this->planner($ignored)->configExclusion('hosting', 1, 'customer_domain'));
        self::assertSame(MonitorPlanner::EXCLUDED_IGNORED_TARGET, $this->planner($ignored)->configExclusion('domain', 1, 'customer_domain'));
        self::assertSame(MonitorPlanner::EXCLUDED_IGNORED_TARGET, $this->planner($ignored)->configExclusion('server_ip', $ipRef, 'customer_ip'));

        \WHMCS\Database\Capsule::table('tblservers')->where('id', 1)->update(['disabled' => 1]);
        self::assertSame(MonitorPlanner::EXCLUDED_SERVER_UNAVAILABLE, $this->planner($all)->configExclusion('server_ip', $ipRef, 'customer_ip'));
    }

    public function testConfigExclusionIsNullWhenWhmcsNoLongerWantsTheMonitor(): void
    {
        $off = new Settings();
        $off->setBool('sync_hosting_website', false);
        $off->setBool('sync_domain_expiry', false);
        $off->setBool('sync_server_ips', false);
        $p = $this->planner($off);
        $nodomain = $this->insert('tblhosting', ['userid' => $this->client, 'domain' => '', 'domainstatus' => 'Active', 'server' => 0]);
        $expired = $this->insert('tbldomains', ['userid' => $this->client, 'domain' => 'old.example', 'status' => 'Expired']);
        $lonely = $this->insert('tblclients', ['companyname' => 'Nobody', 'email' => 'n@example.com']);

        self::assertNull($p->configExclusion('hosting', 2, 'website'), 'Terminated');
        self::assertNull($p->configExclusion('hosting', 999, 'website'), 'deleted');
        self::assertNull($p->configExclusion('hosting', $nodomain, 'website'), 'no domain');
        self::assertNull($p->configExclusion('domain', $expired, 'customer_domain'), 'Expired');
        self::assertNull($p->configExclusion('domain', 999, 'customer_domain'), 'deleted');
        self::assertNull($p->configExclusion('server_ip', MonitorPlanner::serverIpRefId(1, $lonely), 'customer_ip'), 'no Active or Suspended hosting of the client on the server');
        self::assertNull($p->configExclusion('hosting', 1, 'dns'), 'no such combination');
    }

    public function testConfigExclusionAlsoAppliesToObjectsThatExistButAreNotActive(): void
    {
        $off = new Settings();
        foreach (['sync_hosting_website', 'sync_domain_expiry', 'sync_server_ips'] as $key) {
            $off->setBool($key, false);
        }
        $p = $this->planner($off);
        $server = $this->insert('tblservers', ['name' => 'web2', 'ipaddress' => '203.0.113.20']);
        $other = $this->insert('tblclients', ['companyname' => 'Sus', 'email' => 's@example.com']);
        $suspended = $this->insert('tblhosting', ['userid' => $other, 'domain' => 'sus.example', 'domainstatus' => 'Suspended', 'server' => $server]);
        $pending = $this->insert('tblhosting', ['userid' => $other, 'domain' => 'pend.example', 'domainstatus' => 'Pending']);
        $grace = $this->insert('tbldomains', ['userid' => $other, 'domain' => 'grace.example', 'status' => 'Grace']);

        self::assertSame(MonitorPlanner::EXCLUDED_TYPE_DISABLED, $p->configExclusion('hosting', $suspended, 'website'), 'Suspended: excluded by the switch, not paused by the lifecycle');
        self::assertSame(MonitorPlanner::EXCLUDED_TYPE_DISABLED, $p->configExclusion('hosting', $pending, 'website'));
        self::assertSame(MonitorPlanner::EXCLUDED_TYPE_DISABLED, $p->configExclusion('domain', $grace, 'customer_domain'));
        self::assertSame(MonitorPlanner::EXCLUDED_TYPE_DISABLED, $p->configExclusion('server_ip', MonitorPlanner::serverIpRefId($server, $other), 'customer_ip'), 'only suspended hostings on the server');
    }

    public function testInactiveDomainHasNoPlan(): void
    {
        $d = $this->insert('tbldomains', ['userid' => $this->client, 'domain' => 'old.example', 'status' => 'Expired']);
        self::assertSame([], $this->planner(new Settings())->planForDomain($d));
    }

    public function testServerIpRefIdRoundTripsAtBoundaries(): void
    {
        self::assertSame(10_000_000_000, MonitorPlanner::SERVER_IP_REF_FACTOR);
        self::assertSame(10_000_000_001, MonitorPlanner::serverIpRefId(1, 1));
        self::assertSame(19_999_999_999, MonitorPlanner::serverIpRefId(1, 9_999_999_999));
        self::assertSame(['serverId' => 1, 'clientId' => 9_999_999_999], MonitorPlanner::decodeServerIpRefId(19_999_999_999));
        self::assertSame(['serverId' => 2, 'clientId' => 0], MonitorPlanner::decodeServerIpRefId(20_000_000_000));
        self::assertNotSame(MonitorPlanner::serverIpRefId(1, 9_999_999_999), MonitorPlanner::serverIpRefId(2, 0));

        $maxServer = intdiv(PHP_INT_MAX, MonitorPlanner::SERVER_IP_REF_FACTOR) - 1;
        $ref = MonitorPlanner::serverIpRefId($maxServer, 9_999_999_999);
        self::assertSame(['serverId' => $maxServer, 'clientId' => 9_999_999_999], MonitorPlanner::decodeServerIpRefId($ref));

        $top = intdiv(PHP_INT_MAX, MonitorPlanner::SERVER_IP_REF_FACTOR);
        $topClient = PHP_INT_MAX % MonitorPlanner::SERVER_IP_REF_FACTOR;
        self::assertSame(PHP_INT_MAX, MonitorPlanner::serverIpRefId($top, $topClient));
        self::assertSame(['serverId' => $top, 'clientId' => $topClient], MonitorPlanner::decodeServerIpRefId(PHP_INT_MAX));
    }

    public function testServerIpRefIdRejectsOverflowAtIntMax(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MonitorPlanner::serverIpRefId(intdiv(PHP_INT_MAX, MonitorPlanner::SERVER_IP_REF_FACTOR), PHP_INT_MAX % MonitorPlanner::SERVER_IP_REF_FACTOR + 1);
    }

    public function testServerIpRefIdRejectsClientIdAtFactor(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MonitorPlanner::serverIpRefId(1, 10_000_000_000);
    }

    public function testServerIpRefIdRejectsNegativeIds(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MonitorPlanner::serverIpRefId(-1, 1);
    }

    public function testServerIpRefIdRejectsOverflow(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MonitorPlanner::serverIpRefId(intdiv(PHP_INT_MAX, MonitorPlanner::SERVER_IP_REF_FACTOR) + 1, 0);
    }
}
