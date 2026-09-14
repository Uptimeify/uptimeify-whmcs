<?php

declare(strict_types=1);

namespace Uptimeify\Tests\Unit\Sync;

use Uptimeify\Tests\Fake\FakeHttpClient;
use Uptimeify\Tests\Support\DbTestCase;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\Uptimeify\Api\Client;
use WHMCS\Module\Addon\Uptimeify\Api\Exception\UnauthorizedException;
use WHMCS\Module\Addon\Uptimeify\Settings;
use WHMCS\Module\Addon\Uptimeify\Store\CustomerMap;
use WHMCS\Module\Addon\Uptimeify\Store\MonitorMap;
use WHMCS\Module\Addon\Uptimeify\Store\Schema;
use WHMCS\Module\Addon\Uptimeify\Store\StatusCache;
use WHMCS\Module\Addon\Uptimeify\Store\SyncLock;
use WHMCS\Module\Addon\Uptimeify\Sync\CustomerFilter;
use WHMCS\Module\Addon\Uptimeify\Sync\CustomerMirror;
use WHMCS\Module\Addon\Uptimeify\Sync\JobFailedException;
use WHMCS\Module\Addon\Uptimeify\Sync\JobHandler;
use WHMCS\Module\Addon\Uptimeify\Sync\MonitorPlanner;
use WHMCS\Module\Addon\Uptimeify\Sync\RateLimitGuard;
use WHMCS\Module\Addon\Uptimeify\Sync\Reconciler;
use WHMCS\Module\Addon\Uptimeify\Sync\ReconcileResult;
use WHMCS\Module\Addon\Uptimeify\Sync\RemoteIndex;
use WHMCS\Module\Addon\Uptimeify\Whmcs\ClientRepository;
use WHMCS\Module\Addon\Uptimeify\Whmcs\DomainRepository;
use WHMCS\Module\Addon\Uptimeify\Whmcs\HostingRepository;
use WHMCS\Module\Addon\Uptimeify\Whmcs\ServerRepository;

final class JobHandlerTest extends DbTestCase
{
    private FakeHttpClient $http;
    private Settings $settings;
    private int $client;

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
    private static function customer(string $publicId, int $whmcsId): array
    {
        return ['id' => 1, 'publicId' => $publicId, 'name' => 'Acme', 'email' => 'acme@example.com', 'customFields' => ['whmcs_client_id' => (string) $whmcsId]];
    }

    /** @return array<string,mixed> a GET /api/websites item */
    private static function website(string $publicId, int $id, string $host, string $status = 'active'): array
    {
        return ['id' => $id, 'publicId' => $publicId, 'customerId' => 1, 'customerPublicId' => 'c1', 'name' => $host, 'url' => 'https://' . $host, 'status' => $status, 'isDown' => false];
    }

    /** @return array<string,mixed> a GET /api/customer-domains item */
    private static function domain(string $publicId, int $id, string $host): array
    {
        return ['id' => $id, 'publicId' => $publicId, 'customerId' => 1, 'customerPublicId' => 'c1', 'label' => $host, 'domainName' => $host, 'status' => 'active', 'expiry' => null];
    }

    /**
     * A fresh fake with every list a job may read. The first matching route answers, so register specific
     * answers on the returned fake only for routes not listed here.
     *
     * @param list<array<string,mixed>> $customers
     * @param list<array<string,mixed>> $websites
     * @param list<array<string,mixed>> $domains
     * @param list<array<string,mixed>> $dns
     * @param list<array<string,mixed>> $ips
     */
    private function remote(array $customers, array $websites = [], array $domains = [], array $dns = [], array $ips = []): FakeHttpClient
    {
        return $this->http = (new FakeHttpClient())
            ->on('GET', '/api/customers', 200, self::page($customers))
            ->on('GET', '/api/websites', 200, self::page($websites))
            ->on('GET', '/api/customer-domains', 200, self::page($domains))
            ->on('GET', '/api/dns-monitors', 200, self::page($dns))
            ->on('GET', '/api/customer-ips', 200, self::page($ips))
            ->on('GET', '/api/tags', 200, [['id' => 1, 'publicId' => 't1', 'name' => 'whmcs', 'color' => 'teal', 'customerId' => null]])
            ->on('POST', '/api/monitor-tags', 200, ['id' => 1, 'tagId' => 1, 'monitorType' => 'website', 'monitorId' => 1]);
    }

    private function handler(): JobHandler
    {
        $api = new Client($this->http, 'https://api.test', 'wsm_x');
        $guard = new RateLimitGuard(fn (int $s) => null);
        $customerMap = new CustomerMap();
        $planner = new MonitorPlanner($this->settings, new CustomerFilter($this->settings), new ClientRepository(), new HostingRepository(), new DomainRepository(), new ServerRepository());
        $reconciler = new Reconciler($api, $this->settings, $customerMap, new MonitorMap(), $planner, new CustomerMirror($api, $this->settings, $customerMap, $guard), new StatusCache(), new ClientRepository(), new HostingRepository(), new SyncLock(), $guard);
        return new JobHandler($reconciler, $planner, new MonitorMap(), new ClientRepository(), new HostingRepository(), $api, $guard, $this->settings, $customerMap);
    }

    private function index(): RemoteIndex
    {
        return RemoteIndex::load(new Client($this->http, 'https://api.test', 'wsm_x'), new RateLimitGuard(fn (int $s) => null), Reconciler::ALL_TYPES);
    }

    /** Loads the index, then runs the job with a fresh handler, the way the worker does. */
    private function runJob(string $job, int $refId): ReconcileResult
    {
        return $this->handler()->handle($job, $refId, $this->index());
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

    private function mapClient(): void
    {
        (new CustomerMap())->set($this->client, 'c1', null, 'manual');
    }

    private function hosting(string $domain, string $status, int $server = 0): int
    {
        return $this->insert('tblhosting', ['userid' => $this->client, 'domain' => $domain, 'domainstatus' => $status, 'server' => $server]);
    }

    private function expectJobFailure(callable $fn, bool $fatal, string $reasonContains): JobFailedException
    {
        try {
            $fn();
        } catch (JobFailedException $e) {
            self::assertSame($fatal, $e->fatal, 'fatal flag: ' . $e->getMessage());
            self::assertStringContainsString($reasonContains, $e->getMessage());
            return $e;
        }
        self::fail('JobFailedException expected');
    }

    // ---- job names ----------------------------------------------------------------

    public function testJobNamesFollowTheSpecHooksPlusClientRemove(): void
    {
        self::assertSame(
            ['client.upsert', 'client.pause_all', 'client.remove', 'hosting.activate', 'hosting.pause', 'hosting.remove', 'domain.upsert', 'domain.remove'],
            JobHandler::JOBS,
        );
        foreach ([Reconciler::JOB_CLIENT_UPSERT, Reconciler::JOB_HOSTING_ACTIVATE, Reconciler::JOB_HOSTING_REMOVE, Reconciler::JOB_DOMAIN_UPSERT, Reconciler::JOB_DOMAIN_REMOVE] as $pushedByReconcile) {
            self::assertContains($pushedByReconcile, JobHandler::JOBS);
        }
    }

    public function testFatalErrorsAreQuotaAndConfigErrorsButNotInvalidUrlOrInvalidResponse(): void
    {
        foreach ([...Client::QUOTA_ERROR_CODES, 'customerConflict', 'noDefaultPackage', 'invalidPackageType', 'customerScopedTokenForbidden'] as $code) {
            self::assertContains($code, JobHandler::FATAL_ERRORS);
        }
        self::assertCount(count(Client::QUOTA_ERROR_CODES) + 4, JobHandler::FATAL_ERRORS);
        self::assertNotContains(Client::INVALID_RESPONSE_CODE, JobHandler::FATAL_ERRORS);
        self::assertNotContains('invalidUrl', JobHandler::FATAL_ERRORS, 'round 2 review I1: a domain that does not resolve yet gets invalidUrl too');
    }

    public function testUnknownJobThrows(): void
    {
        $this->remote([]);
        $this->expectException(\InvalidArgumentException::class);
        $this->runJob('nope', 1);
    }

    // ---- §8.5 Hosting Active (new / unsuspend) ----------------------------------------

    public function testHostingActivateCreatesAndTagsTheWebsite(): void
    {
        $h = $this->hosting('site.example', 'Active');
        $this->mapClient();
        $this->remote([self::customer('c1', $this->client)])
            ->on('POST', '/api/websites', 200, ['id' => 11, 'publicId' => 'w1', 'customerId' => 1, 'name' => 'site.example', 'url' => 'https://site.example', 'status' => 'active']);

        $result = $this->runJob('hosting.activate', $h);

        self::assertSame(1, $result->monitorsCreated);
        $sent = self::body($this->sent('POST', '/api/websites')[0]);
        self::assertSame(['https://site.example', ['whmcs_hosting_id' => (string) $h]], [$sent['url'], $sent['customFields']]);
        self::assertSame(['monitorType' => 'website', 'monitorId' => 11, 'tagId' => 1], self::body($this->sent('POST', '/api/monitor-tags')[0]));
        self::assertSame('w1', self::row('hosting', $h, 'website')['monitor_public_id']);
        self::assertSame(MonitorMap::STATE_ACTIVE, self::row('hosting', $h, 'website')['state']);
    }

    public function testUnsuspendResumesThePausedWebsite(): void
    {
        $h = $this->hosting('site.example', 'Active');
        self::mapRow(['kind' => 'hosting', 'whmcs_ref_id' => $h, 'monitor_type' => 'website', 'monitor_public_id' => 'w1', 'monitor_numeric_id' => 11, 'target' => 'site.example', 'state' => MonitorMap::STATE_PAUSED]);
        $this->mapClient();
        $this->remote([self::customer('c1', $this->client)], [self::website('w1', 11, 'site.example', 'inactive')])
            ->on('PATCH', '/api/websites/w1', 200, self::website('w1', 11, 'site.example'));

        $result = $this->runJob('hosting.activate', $h);

        self::assertSame(1, $result->resumed);
        self::assertSame(['status' => 'active'], self::body($this->sent('PATCH', '/api/websites/w1')[0]));
        self::assertSame(MonitorMap::STATE_ACTIVE, self::row('hosting', $h, 'website')['state']);
        self::assertSame([], $this->sent('POST', '/api/websites'));
    }

    public function testHostingActivateCreatesTheDomainMonitorOfTheHosting(): void
    {
        $this->settings->setBool('sync_hosting_website', false);
        $this->settings->setBool('sync_hosting_domain_expiry', true);
        $h = $this->hosting('site.example', 'Active');
        $this->mapClient();
        $this->remote([self::customer('c1', $this->client)])
            ->on('POST', '/api/customers/c1/domains', 200, ['id' => 21, 'publicId' => 'd1', 'customerId' => 1, 'domainName' => 'site.example', 'label' => 'site.example', 'status' => 'active', 'deduplicated' => false]);

        $this->runJob('hosting.activate', $h);

        self::assertSame('d1', self::row('hosting', $h, 'customer_domain')['monitor_public_id']);
    }

    // ---- §8.5 Hosting Suspended ---------------------------------------------------------

    public function testHostingPausePausesTheWebsiteAndKeepsTheDomainMonitor(): void
    {
        $this->settings->setBool('sync_hosting_domain_expiry', true);
        $h = $this->hosting('site.example', 'Suspended');
        self::mapRow(['kind' => 'hosting', 'whmcs_ref_id' => $h, 'monitor_type' => 'website', 'monitor_public_id' => 'w1', 'target' => 'site.example']);
        self::mapRow(['kind' => 'hosting', 'whmcs_ref_id' => $h, 'monitor_type' => 'customer_domain', 'monitor_public_id' => 'd1', 'target' => 'site.example']);
        $this->mapClient();
        $this->remote([self::customer('c1', $this->client)], [self::website('w1', 11, 'site.example')], [self::domain('d1', 21, 'site.example')])
            ->on('PATCH', '/api/websites/w1', 200, self::website('w1', 11, 'site.example', 'inactive'));

        $result = $this->runJob('hosting.pause', $h);

        self::assertSame(1, $result->paused);
        self::assertSame(['status' => 'inactive'], self::body($this->sent('PATCH', '/api/websites/w1')[0]));
        self::assertSame(MonitorMap::STATE_PAUSED, self::row('hosting', $h, 'website')['state']);
        self::assertSame(MonitorMap::STATE_ACTIVE, self::row('hosting', $h, 'customer_domain')['state']);
        self::assertSame([], $this->sent('DELETE', '#.#'));
    }

    public function testStalePauseJobDoesNotPauseAHostingThatIsActiveAgain(): void
    {
        $h = $this->hosting('site.example', 'Active');
        self::mapRow(['kind' => 'hosting', 'whmcs_ref_id' => $h, 'monitor_type' => 'website', 'monitor_public_id' => 'w1', 'monitor_numeric_id' => 11, 'target' => 'site.example']);
        $this->mapClient();
        $this->remote([self::customer('c1', $this->client)], [self::website('w1', 11, 'site.example')]);

        $result = $this->runJob('hosting.pause', $h);

        self::assertSame(0, $result->paused);
        self::assertSame([], $this->sent('PATCH', '#.#'), 'the job follows the live WHMCS status, not the hook that queued it');
        self::assertSame(MonitorMap::STATE_ACTIVE, self::row('hosting', $h, 'website')['state']);
    }

    /**
     * ServiceEdit and DomainEdit queue `hosting.activate` / `domain.upsert` for any admin edit: the job must follow the
     * edited live state (changed domain, status set by hand), whatever the job name says.
     */
    public function testEditJobsFollowTheEditedLiveState(): void
    {
        $h = $this->hosting('moved.example', 'Active');
        self::mapRow(['origin' => 'created', 'kind' => 'hosting', 'whmcs_ref_id' => $h, 'monitor_type' => 'website', 'monitor_public_id' => 'w1', 'monitor_numeric_id' => 11, 'target' => 'site.example']);
        $this->mapClient();
        $this->remote([self::customer('c1', $this->client)], [self::website('w1', 11, 'site.example')])
            ->on('PATCH', '/api/websites/w1', 200, self::website('w1', 11, 'moved.example'));

        $result = $this->runJob('hosting.activate', $h);

        self::assertSame([['url' => 'https://moved.example', 'name' => 'moved.example']], array_map(fn (array $r): array => self::body($r), $this->sent('PATCH', '/api/websites/w1')), 'domain edited in the admin area: updated in place');
        self::assertSame([1, 0], [$result->updated, $result->monitorsCreated]);

        Capsule::table('tblhosting')->where('id', $h)->update(['domainstatus' => 'Suspended']);
        $this->remote([self::customer('c1', $this->client)], [self::website('w1', 11, 'moved.example')])
            ->on('PATCH', '/api/websites/w1', 200, self::website('w1', 11, 'moved.example', 'inactive'));

        $result = $this->runJob('hosting.activate', $h);

        self::assertSame([['status' => 'inactive']], array_map(fn (array $r): array => self::body($r), $this->sent('PATCH', '/api/websites/w1')), 'status set to Suspended by hand: paused');
        self::assertSame(MonitorMap::STATE_PAUSED, self::row('hosting', $h, 'website')['state']);

        $d = $this->insert('tbldomains', ['userid' => $this->client, 'domain' => 'reg.example', 'status' => 'Cancelled']);
        self::mapRow(['origin' => 'created', 'kind' => 'domain', 'whmcs_ref_id' => $d, 'monitor_type' => 'customer_domain', 'monitor_public_id' => 'd1', 'target' => 'reg.example']);
        $this->remote([self::customer('c1', $this->client)], [], [self::domain('d1', 21, 'reg.example')])
            ->on('DELETE', '/api/customer-domains/d1', 200, ['success' => true]);

        $result = $this->runJob('domain.upsert', $d);

        self::assertCount(1, $this->sent('DELETE', '/api/customer-domains/d1'), 'domain set to Cancelled by hand: the created monitor ends');
        self::assertSame(1, $result->deleted);
    }

    // ---- §8.5 Hosting Terminated / Cancelled / deleted ------------------------------------

    public function testHostingRemoveFollowsOnTerminateAndDeletesTheDomainMonitor(): void
    {
        $this->settings->setBool('sync_hosting_domain_expiry', true);
        $h = $this->hosting('site.example', 'Terminated');
        self::mapRow(['origin' => 'created', 'kind' => 'hosting', 'whmcs_ref_id' => $h, 'monitor_type' => 'website', 'monitor_public_id' => 'w1', 'target' => 'site.example']);
        self::mapRow(['origin' => 'created', 'kind' => 'hosting', 'whmcs_ref_id' => $h, 'monitor_type' => 'customer_domain', 'monitor_public_id' => 'd1', 'target' => 'site.example']);
        $this->mapClient();
        $this->remote([self::customer('c1', $this->client)], [self::website('w1', 11, 'site.example')], [self::domain('d1', 21, 'site.example')])
            ->on('PATCH', '/api/websites/w1', 200, self::website('w1', 11, 'site.example', 'inactive'))
            ->on('DELETE', '/api/customer-domains/d1', 200, ['success' => true]);

        $result = $this->runJob('hosting.remove', $h);

        self::assertSame([1, 1], [$result->paused, $result->deleted], 'default on_terminate=pause');
        self::assertSame(MonitorMap::STATE_PAUSED, self::row('hosting', $h, 'website')['state']);
        self::assertNull((new MonitorMap())->find('hosting', $h, 'customer_domain'));

        // ServiceDelete: the hosting row is gone, on_terminate=delete
        Capsule::table('tblhosting')->where('id', $h)->delete();
        $this->settings->set('on_terminate', 'delete');
        $this->remote([self::customer('c1', $this->client)], [self::website('w1', 11, 'site.example', 'inactive')])
            ->on('DELETE', '/api/websites/w1', 200, ['success' => true]);

        $result = $this->runJob('hosting.remove', $h);

        self::assertSame(1, $result->deleted);
        self::assertCount(1, $this->sent('DELETE', '/api/websites/w1'));
        self::assertSame([], (new MonitorMap())->all());
    }

    public function testHostingJobRetiresTheServerIpWhenNoActiveHostingIsLeftOnTheServer(): void
    {
        $this->settings->setBool('sync_server_ips', true);
        $server = $this->insert('tblservers', ['name' => 'web1', 'ipaddress' => '203.0.113.10']);
        $h = $this->hosting('site.example', 'Terminated', $server);
        $ipRef = MonitorPlanner::serverIpRefId($server, $this->client);
        self::mapRow(['origin' => 'created', 'kind' => 'server_ip', 'whmcs_ref_id' => $ipRef, 'monitor_type' => 'customer_ip', 'monitor_public_id' => 'i1', 'target' => '203.0.113.10']);
        $this->mapClient();
        $ip = ['id' => 3, 'publicId' => 'i1', 'customerId' => 1, 'customer' => ['publicId' => 'c1'], 'ipAddress' => '203.0.113.10', 'label' => 'web1', 'status' => 'active'];
        $this->remote([self::customer('c1', $this->client)], [], [], [], [$ip])
            ->on('DELETE', '/api/customer-ips/i1', 200, ['success' => true]);

        $this->runJob('hosting.remove', $h);

        self::assertCount(1, $this->sent('DELETE', '/api/customer-ips/i1'));

        // another active hosting of the client on the same server keeps the IP monitor
        $this->hosting('other.example', 'Active', $server);
        self::mapRow(['origin' => 'created', 'kind' => 'server_ip', 'whmcs_ref_id' => $ipRef, 'monitor_type' => 'customer_ip', 'monitor_public_id' => 'i1', 'target' => '203.0.113.10']);
        $this->remote([self::customer('c1', $this->client)], [], [], [], [$ip]);

        $this->runJob('hosting.remove', $h);

        self::assertSame([], $this->sent('DELETE', '#.#'));
        self::assertSame(MonitorMap::STATE_ACTIVE, self::row('server_ip', $ipRef, 'customer_ip')['state']);
    }

    public function testSingleJobNeverRetiresAMonitorThatAnotherPlannedRowStillTargets(): void
    {
        $this->settings->set('on_terminate', 'delete');
        $this->settings->setBool('sync_hosting_domain_expiry', true);
        $old = $this->hosting('site.example', 'Terminated');
        $this->hosting('www.site.example', 'Active'); // planned (same host), not mapped yet
        $this->insert('tbldomains', ['userid' => $this->client, 'domain' => 'site.example', 'status' => 'Active']); // planned, not mapped yet
        self::mapRow(['origin' => 'created', 'kind' => 'hosting', 'whmcs_ref_id' => $old, 'monitor_type' => 'website', 'monitor_public_id' => 'w1', 'target' => 'site.example']);
        self::mapRow(['origin' => 'created', 'kind' => 'hosting', 'whmcs_ref_id' => $old, 'monitor_type' => 'customer_domain', 'monitor_public_id' => 'd1', 'target' => 'site.example']);
        $this->mapClient();
        $this->remote([self::customer('c1', $this->client)], [self::website('w1', 11, 'site.example')], [self::domain('d1', 21, 'site.example')]);

        $this->runJob('hosting.remove', $old);

        self::assertSame([], $this->sent('DELETE', '#.#'), 'the new hosting and the registrar domain still target both monitors');
        self::assertSame([], $this->sent('PATCH', '#.#'));
        self::assertSame([], (new MonitorMap())->all(), 'only the rows of the terminated hosting are removed');
    }

    public function testChangedHostingDomainInAJobKeepsTheOldDomainMonitorARegistrarDomainPlans(): void
    {
        $this->settings->setBool('sync_hosting_website', false);
        $this->settings->setBool('sync_hosting_domain_expiry', true);
        $h = $this->hosting('new.example', 'Active');
        $this->insert('tbldomains', ['userid' => $this->client, 'domain' => 'old.example', 'status' => 'Active']); // planned, not mapped yet
        self::mapRow(['origin' => 'created', 'kind' => 'hosting', 'whmcs_ref_id' => $h, 'monitor_type' => 'customer_domain', 'monitor_public_id' => 'd-old', 'target' => 'old.example']);
        $this->mapClient();
        $this->remote([self::customer('c1', $this->client)], [], [self::domain('d-old', 22, 'old.example')])
            ->on('POST', '/api/customers/c1/domains', 200, ['id' => 21, 'publicId' => 'd-new', 'customerId' => 1, 'domainName' => 'new.example', 'label' => 'new.example', 'status' => 'active', 'deduplicated' => false]);

        $this->runJob('hosting.activate', $h);

        self::assertSame([], $this->sent('DELETE', '#.#'), 'the registrar domain old.example still wants that monitor');
        self::assertSame('d-new', self::row('hosting', $h, 'customer_domain')['monitor_public_id']);
    }

    // ---- §8.5 Domain Active / ended ------------------------------------------------------

    public function testDomainUpsertCreatesTheDomainMonitors(): void
    {
        $this->settings->setBool('sync_domain_dns', true);
        $d = $this->insert('tbldomains', ['userid' => $this->client, 'domain' => 'reg.example', 'status' => 'Active']);
        $this->mapClient();
        $this->remote([self::customer('c1', $this->client)])
            ->on('POST', '/api/customers/c1/domains', 200, ['id' => 21, 'publicId' => 'd1', 'customerId' => 1, 'domainName' => 'reg.example', 'label' => 'reg.example', 'status' => 'active', 'deduplicated' => false])
            ->on('POST', '/api/dns-monitors', 200, ['id' => 31, 'publicId' => 'n1', 'customerId' => 1, 'name' => 'reg.example', 'hostname' => 'reg.example', 'status' => 'active']);

        $result = $this->runJob('domain.upsert', $d);

        self::assertSame(2, $result->monitorsCreated);
        self::assertSame('reg.example', self::body($this->sent('POST', '/api/customers/c1/domains')[0])['domainName']);
        self::assertSame('d1', self::row('domain', $d, 'customer_domain')['monitor_public_id']);
        self::assertSame('n1', self::row('domain', $d, 'dns')['monitor_public_id']);
    }

    public function testDomainRemoveDeletesTheDomainMonitorsOfAnEndedOrDeletedDomain(): void
    {
        $this->settings->setBool('sync_domain_dns', true);
        $d = $this->insert('tbldomains', ['userid' => $this->client, 'domain' => 'reg.example', 'status' => 'Expired']);
        self::mapRow(['origin' => 'created', 'kind' => 'domain', 'whmcs_ref_id' => $d, 'monitor_type' => 'customer_domain', 'monitor_public_id' => 'd1', 'target' => 'reg.example']);
        self::mapRow(['origin' => 'created', 'kind' => 'domain', 'whmcs_ref_id' => $d, 'monitor_type' => 'dns', 'monitor_public_id' => 'n1', 'target' => 'reg.example']);
        self::mapRow(['origin' => 'created', 'kind' => 'domain', 'whmcs_ref_id' => 77, 'monitor_type' => 'customer_domain', 'monitor_public_id' => 'dx', 'target' => 'deleted.example']);
        $this->mapClient();
        $this->remote(
            [self::customer('c1', $this->client)],
            [],
            [self::domain('d1', 21, 'reg.example'), self::domain('dx', 22, 'deleted.example')],
            [['id' => 31, 'publicId' => 'n1', 'customerId' => 1, 'customerPublicId' => 'c1', 'name' => 'reg.example', 'hostname' => 'reg.example', 'status' => 'active']],
        )
            ->on('DELETE', '#^/api/customer-domains/d[1x]$#', 200, ['success' => true])
            ->on('DELETE', '/api/dns-monitors/n1', 200, ['success' => true]);

        $result = $this->runJob('domain.remove', $d);

        self::assertSame(2, $result->deleted);
        self::assertCount(1, $this->sent('DELETE', '/api/customer-domains/d1'));
        self::assertCount(1, $this->sent('DELETE', '/api/dns-monitors/n1'));

        $this->runJob('domain.remove', 77); // DomainDelete: tbldomains row gone

        self::assertCount(1, $this->sent('DELETE', '/api/customer-domains/dx'));
        self::assertSame([], (new MonitorMap())->all());
    }

    // ---- §8.5 Client Closed / deleted ----------------------------------------------------

    public function testClientPauseAllPausesWebsitesAndKeepsOtherMonitors(): void
    {
        Capsule::table('tblclients')->where('id', $this->client)->update(['status' => 'Closed']);
        $h = $this->hosting('site.example', 'Active');
        $h2 = $this->hosting('shop.example', 'Active');
        $d = $this->insert('tbldomains', ['userid' => $this->client, 'domain' => 'reg.example', 'status' => 'Active']);
        self::mapRow(['kind' => 'hosting', 'whmcs_ref_id' => $h, 'monitor_type' => 'website', 'monitor_public_id' => 'w1', 'target' => 'site.example']);
        self::mapRow(['kind' => 'hosting', 'whmcs_ref_id' => $h2, 'monitor_type' => 'website', 'monitor_public_id' => 'w2', 'target' => 'shop.example']);
        self::mapRow(['kind' => 'domain', 'whmcs_ref_id' => $d, 'monitor_type' => 'customer_domain', 'monitor_public_id' => 'd1', 'target' => 'reg.example']);
        $this->mapClient();
        $this->remote([self::customer('c1', $this->client)], [self::website('w1', 11, 'site.example'), self::website('w2', 12, 'shop.example')], [self::domain('d1', 21, 'reg.example')])
            ->on('PATCH', '#^/api/websites/w[12]$#', 200, self::website('w1', 11, 'site.example', 'inactive'));

        $result = $this->runJob('client.pause_all', $this->client);

        self::assertSame(2, $result->paused);
        self::assertSame(MonitorMap::STATE_PAUSED, self::row('hosting', $h, 'website')['state']);
        self::assertSame(MonitorMap::STATE_PAUSED, self::row('hosting', $h2, 'website')['state']);
        self::assertSame(MonitorMap::STATE_ACTIVE, self::row('domain', $d, 'customer_domain')['state']);
        self::assertSame([], $this->sent('DELETE', '#.#'));
        self::assertSame([], $this->sent('POST', '#.#'));
    }

    public function testClientRemoveTreatsAllMonitorsLikeTerminated(): void
    {
        $this->settings->setBool('sync_server_ips', true);
        $server = $this->insert('tblservers', ['name' => 'web1', 'ipaddress' => '203.0.113.10']);
        // WHMCS may still have the services when the job runs: the missing client alone ends them
        $h = $this->hosting('site.example', 'Active', $server);
        $d = $this->insert('tbldomains', ['userid' => $this->client, 'domain' => 'reg.example', 'status' => 'Active']);
        $ipRef = MonitorPlanner::serverIpRefId($server, $this->client);
        self::mapRow(['origin' => 'created', 'kind' => 'hosting', 'whmcs_ref_id' => $h, 'monitor_type' => 'website', 'monitor_public_id' => 'w1', 'target' => 'site.example']);
        self::mapRow(['origin' => 'created', 'kind' => 'domain', 'whmcs_ref_id' => $d, 'monitor_type' => 'customer_domain', 'monitor_public_id' => 'd1', 'target' => 'reg.example']);
        self::mapRow(['origin' => 'created', 'kind' => 'server_ip', 'whmcs_ref_id' => $ipRef, 'monitor_type' => 'customer_ip', 'monitor_public_id' => 'i1', 'target' => '203.0.113.10']);
        $this->mapClient();
        Capsule::table('tblclients')->where('id', $this->client)->delete();
        $this->settings->set('on_terminate', 'delete');
        $this->remote(
            [self::customer('c1', $this->client)],
            [self::website('w1', 11, 'site.example')],
            [self::domain('d1', 21, 'reg.example')],
            [],
            [['id' => 3, 'publicId' => 'i1', 'customerId' => 1, 'customer' => ['publicId' => 'c1'], 'ipAddress' => '203.0.113.10', 'label' => 'web1', 'status' => 'active']],
        )
            ->on('DELETE', '/api/websites/w1', 200, ['success' => true])
            ->on('DELETE', '/api/customer-domains/d1', 200, ['success' => true])
            ->on('DELETE', '/api/customer-ips/i1', 200, ['success' => true]);

        $result = $this->runJob('client.remove', $this->client);

        self::assertSame(3, $result->deleted, 'website by on_terminate=delete, domain and IP monitors deleted');
        self::assertSame([], (new MonitorMap())->all());
        self::assertSame([], $this->sent('POST', '#.#'));
    }

    public function testClientRemoveForgetsTheMappingAndCacheButNeverTheRemoteCustomer(): void
    {
        $other = $this->insert('tblclients', ['companyname' => 'Other', 'email' => 'o@example.com']);
        $h = $this->hosting('site.example', 'Active');
        self::mapRow(['kind' => 'hosting', 'whmcs_ref_id' => $h, 'monitor_type' => 'website', 'monitor_public_id' => 'w1', 'target' => 'site.example']);
        $this->mapClient();
        (new CustomerMap())->set($other, 'c2', null, 'manual');
        $cache = new StatusCache();
        foreach (['client:' . $this->client, 'client_retry:' . $this->client, 'client_conflict_log:' . $this->client, 'client:' . $other, 'org'] as $key) {
            $cache->set($key, ['x' => 1]);
        }
        Capsule::table('tblclients')->where('id', $this->client)->delete();
        $this->remote([self::customer('c1', $this->client)], [self::website('w1', 11, 'site.example')])
            ->on('PATCH', '/api/websites/w1', 200, self::website('w1', 11, 'site.example', 'inactive'));

        $result = $this->runJob('client.remove', $this->client);

        self::assertSame(1, $result->paused, 'the adopted website of the deleted client is paused first (D1)');
        self::assertNull((new CustomerMap())->get($this->client), 'the customer is no longer reserved for a deleted client id');
        self::assertNotNull((new CustomerMap())->get($other));
        foreach (['client:', 'client_retry:', 'client_conflict_log:'] as $prefix) {
            self::assertNull($cache->get($prefix . $this->client), $prefix);
        }
        self::assertNotNull($cache->get('client:' . $other));
        self::assertNotNull($cache->get('org'));
        self::assertSame([], $this->sent('DELETE', '#^/api/customers#'), 'the Uptimeify customer is never deleted');
        self::assertStringContainsString('mapping to customer c1 removed', implode("\n", $result->messages));
    }

    public function testClientRemoveKeepsTheMappingWhileTheJobFailsOrTheClientStillExists(): void
    {
        $this->settings->set('on_terminate', 'delete');
        $h = $this->hosting('site.example', 'Active');
        self::mapRow(['origin' => 'created', 'kind' => 'hosting', 'whmcs_ref_id' => $h, 'monitor_type' => 'website', 'monitor_public_id' => 'w1', 'target' => 'site.example']);
        $this->mapClient();
        (new StatusCache())->set('client:' . $this->client, ['x' => 1]);
        $this->remote([self::customer('c1', $this->client)], [self::website('w1', 11, 'site.example')]);

        $this->runJob('client.remove', $this->client); // a stale job: WHMCS still has the client

        self::assertNotNull((new CustomerMap())->get($this->client));
        self::assertNotNull((new StatusCache())->get('client:' . $this->client));

        Capsule::table('tblclients')->where('id', $this->client)->delete();
        $this->remote([self::customer('c1', $this->client)], [self::website('w1', 11, 'site.example')])
            ->on('DELETE', '/api/websites/w1', 500, ['statusCode' => 500, 'statusMessage' => 'boom']);

        $this->expectJobFailure(fn () => $this->runJob('client.remove', $this->client), false, 'w1');

        self::assertNotNull((new CustomerMap())->get($this->client), 'kept for the retry that still has to find the rows');
    }

    public function testHostingPauseAndActivatePauseAndResumeTheCustomerIpOfADomainlessVps(): void
    {
        $this->settings->setBool('sync_server_ips', true);
        $server = $this->insert('tblservers', ['name' => 'vps1', 'ipaddress' => '203.0.113.30']);
        $h = $this->hosting('', 'Active', $server);
        $ipRef = MonitorPlanner::serverIpRefId($server, $this->client);
        $this->mapClient();
        $ip = fn (string $status): array => ['id' => 5, 'publicId' => 'i1', 'customerId' => 1, 'customer' => ['publicId' => 'c1'], 'ipAddress' => '203.0.113.30', 'label' => 'vps1', 'status' => $status];
        $this->remote([self::customer('c1', $this->client)])
            ->on('POST', '/api/customers/c1/ips', 200, ['id' => 5, 'publicId' => 'i1', 'customerId' => 1, 'ipAddress' => '203.0.113.30', 'label' => 'vps1', 'status' => 'active', 'deduplicated' => false]);

        $this->runJob('hosting.activate', $h);

        self::assertSame(['ipAddress' => '203.0.113.30', 'label' => 'vps1', 'status' => 'active'], self::body($this->sent('POST', '/api/customers/c1/ips')[0]), 'a hosting without a domain still gets its server IP (D3)');
        self::assertSame([], $this->sent('POST', '/api/websites'));

        Capsule::table('tblhosting')->where('id', $h)->update(['domainstatus' => 'Suspended']);
        $this->remote([self::customer('c1', $this->client)], [], [], [], [$ip('active')])
            ->on('PATCH', '/api/customer-ips/i1', 200, $ip('disabled'));
        $result = $this->runJob('hosting.pause', $h);

        self::assertSame([['status' => 'disabled']], array_map(fn (array $r): array => self::body($r), $this->sent('PATCH', '/api/customer-ips/i1')));
        self::assertSame([1, 0], [$result->paused, $result->deleted]);
        self::assertSame(MonitorMap::STATE_PAUSED, self::row('server_ip', $ipRef, 'customer_ip')['state']);

        Capsule::table('tblhosting')->where('id', $h)->update(['domainstatus' => 'Active']);
        $this->remote([self::customer('c1', $this->client)], [], [], [], [$ip('disabled')])
            ->on('PATCH', '/api/customer-ips/i1', 200, $ip('active'));
        $result = $this->runJob('hosting.activate', $h);

        self::assertSame([['status' => 'active']], array_map(fn (array $r): array => self::body($r), $this->sent('PATCH', '/api/customer-ips/i1')));
        self::assertSame(1, $result->resumed);
    }

    public function testUnsuspendDoesNotResumeAnAdminPausedWebsite(): void
    {
        $h = $this->hosting('site.example', 'Active');
        self::mapRow(['admin_paused' => true, 'kind' => 'hosting', 'whmcs_ref_id' => $h, 'monitor_type' => 'website', 'monitor_public_id' => 'w1', 'monitor_numeric_id' => 11, 'target' => 'site.example', 'state' => MonitorMap::STATE_PAUSED]);
        $this->mapClient();
        $this->remote([self::customer('c1', $this->client)], [self::website('w1', 11, 'site.example', 'inactive')]);

        $result = $this->runJob('hosting.activate', $h);

        self::assertSame([], $this->sent('PATCH', '#.#'));
        self::assertSame(0, $result->resumed);
        self::assertSame([MonitorMap::STATE_PAUSED, true], [self::row('hosting', $h, 'website')['state'], self::row('hosting', $h, 'website')['admin_paused']]);
    }

    public function testClientJobDetachesMonitorsTheCustomerFilterNowExcludes(): void
    {
        $this->settings->set('on_terminate', 'delete');
        $h = $this->hosting('site.example', 'Active');
        $d = $this->insert('tbldomains', ['userid' => $this->client, 'domain' => 'reg.example', 'status' => 'Active']);
        self::mapRow(['origin' => 'created', 'kind' => 'hosting', 'whmcs_ref_id' => $h, 'monitor_type' => 'website', 'monitor_public_id' => 'w1', 'target' => 'site.example']);
        self::mapRow(['origin' => 'created', 'kind' => 'domain', 'whmcs_ref_id' => $d, 'monitor_type' => 'customer_domain', 'monitor_public_id' => 'd1', 'target' => 'reg.example']);
        $this->mapClient();
        $this->settings->setCustomerFilter([$this->client => 'skip']);
        $this->remote([self::customer('c1', $this->client)], [self::website('w1', 11, 'site.example')], [self::domain('d1', 21, 'reg.example')]);

        $result = $this->runJob('client.upsert', $this->client);

        self::assertSame([], $this->sent('DELETE', '#.#'));
        self::assertSame([], $this->sent('PATCH', '#.#'));
        self::assertSame([2, 0], [$result->detached, $result->deleted]);
        self::assertSame([], (new MonitorMap())->all());
    }

    public function testClientUpsertCreatesTheCustomerOnlyWithTheFirstMonitor(): void
    {
        $this->remote([])
            ->on('POST', '/api/customers', 200, ['customer' => ['id' => 1, 'publicId' => 'c9', 'name' => 'Acme', 'email' => 'acme@example.com', 'packageType' => 'starter', 'customFields' => ['whmcs_client_id' => (string) $this->client]]]);

        $this->runJob('client.upsert', $this->client);

        self::assertSame([], $this->sent('POST', '/api/customers'), 'no monitor, no customer (spec §7)');
        self::assertNull((new CustomerMap())->get($this->client));

        $this->hosting('site.example', 'Active');
        $this->remote([])
            ->on('POST', '/api/customers', 200, ['customer' => ['id' => 1, 'publicId' => 'c9', 'name' => 'Acme', 'email' => 'acme@example.com', 'packageType' => 'starter', 'customFields' => ['whmcs_client_id' => (string) $this->client]]])
            ->on('POST', '/api/websites', 200, ['id' => 11, 'publicId' => 'w1', 'customerId' => 1, 'name' => 'site.example', 'url' => 'https://site.example', 'status' => 'active']);

        $result = $this->runJob('client.upsert', $this->client);

        self::assertSame([1, 1], [$result->customersCreated, $result->monitorsCreated]);
        $mapping = (new CustomerMap())->get($this->client);
        self::assertNotNull($mapping);
        self::assertSame('c9', $mapping['customer_public_id']);
    }

    // ---- failures -----------------------------------------------------------------------

    public function testQuotaFailsTheJobFatallyWithAPlainReason(): void
    {
        $h = $this->hosting('site.example', 'Active');
        $this->mapClient();
        $this->remote([self::customer('c1', $this->client)])
            ->on('POST', '/api/websites', 403, ['statusCode' => 403, 'statusMessage' => 'Active monitor limit reached', 'data' => ['code' => 'activeMonitorLimitReached']]);

        $e = $this->expectJobFailure(fn () => $this->runJob('hosting.activate', $h), true, 'activeMonitorLimitReached');

        self::assertStringContainsString('quota', $e->getMessage());
        self::assertStringContainsString('site.example', $e->getMessage());
        self::assertSame(1, $e->result->quotaSkipped);
        self::assertSame('activeMonitorLimitReached', self::row('hosting', $h, 'website')['last_error']);
    }

    public function testInvalidUrlFailsTheJobForARetry(): void
    {
        $h = $this->hosting('new-shop.example', 'Active');
        $this->mapClient();
        $this->remote([self::customer('c1', $this->client)])
            ->on('POST', '/api/websites', 400, ['statusCode' => 400, 'statusMessage' => 'Monitor URL could not be resolved', 'data' => ['code' => 'invalidUrl']]);

        $this->expectJobFailure(fn () => $this->runJob('hosting.activate', $h), false, 'invalidUrl');
    }

    public function testMissingDefaultPackageFailsTheJobFatally(): void
    {
        $this->settings->set('default_package_type', null);
        $h = $this->hosting('site.example', 'Active');
        $this->remote([]);

        $this->expectJobFailure(fn () => $this->runJob('hosting.activate', $h), true, 'noDefaultPackage');
        self::assertSame([], $this->sent('POST', '#.#'));
    }

    public function testTransientCreateErrorFailsTheJobForARetry(): void
    {
        $h = $this->hosting('site.example', 'Active');
        $this->mapClient();
        $this->remote([self::customer('c1', $this->client)])
            ->on('POST', '/api/websites', 500, ['statusCode' => 500, 'statusMessage' => 'boom']);

        $e = $this->expectJobFailure(fn () => $this->runJob('hosting.activate', $h), false, 'create failed');

        self::assertSame(1, $e->result->errors);
    }

    public function testFailedRetirementFailsTheJobInsteadOfMarkingItDone(): void
    {
        $this->settings->set('on_terminate', 'delete');
        $h = $this->hosting('site.example', 'Terminated');
        self::mapRow(['origin' => 'created', 'kind' => 'hosting', 'whmcs_ref_id' => $h, 'monitor_type' => 'website', 'monitor_public_id' => 'w1', 'target' => 'site.example']);
        $this->mapClient();
        $this->remote([self::customer('c1', $this->client)], [self::website('w1', 11, 'site.example')])
            ->on('DELETE', '/api/websites/w1', 500, ['statusCode' => 500, 'statusMessage' => 'boom']);

        $this->expectJobFailure(fn () => $this->runJob('hosting.remove', $h), false, 'w1');

        self::assertSame(MonitorMap::STATE_ERROR, self::row('hosting', $h, 'website')['state']);
    }

    public function testUnauthorizedIsThrownToTheWorker(): void
    {
        $h = $this->hosting('site.example', 'Active');
        $this->mapClient();
        $this->remote([self::customer('c1', $this->client)])
            ->on('POST', '/api/websites', 401, ['statusCode' => 401, 'statusMessage' => 'Unauthorized']);

        $this->expectException(UnauthorizedException::class);
        $this->runJob('hosting.activate', $h);
    }

    // ---- one remote index shared by the jobs of a worker run (fix round 1) ---------------

    public function testJobsOfOneBatchOnlyOrphanAMissingMonitorAndTheNextLoadRecreatesIt(): void
    {
        $h = $this->hosting('site.example', 'Active');
        self::mapRow(['kind' => 'hosting', 'whmcs_ref_id' => $h, 'monitor_type' => 'website', 'monitor_public_id' => 'w1', 'monitor_numeric_id' => 11, 'target' => 'site.example']);
        $this->mapClient();
        $this->remote([self::customer('c1', $this->client)]);
        $handler = $this->handler();
        $index = $this->index();

        $handler->handle('client.upsert', $this->client, $index);
        $handler->handle('hosting.activate', $h, $index);

        self::assertSame([], $this->sent('POST', '#.#'), 'missing once in this load: never recreated in the same batch');
        self::assertSame(MonitorMap::STATE_ORPHANED, self::row('hosting', $h, 'website')['state']);
        self::assertSame('w1', self::row('hosting', $h, 'website')['monitor_public_id']);

        $this->remote([self::customer('c1', $this->client)])
            ->on('POST', '/api/websites', 200, ['id' => 12, 'publicId' => 'w2', 'customerId' => 1, 'name' => 'site.example', 'url' => 'https://site.example', 'status' => 'active']);
        $this->runJob('hosting.activate', $h);

        self::assertCount(1, $this->sent('POST', '/api/websites'), 'still missing on the next load: recreated');
        self::assertSame('w2', self::row('hosting', $h, 'website')['monitor_public_id']);
    }

    public function testJobsOfOneBatchDoNotRemoveARetiredRowOrphanedInThatBatch(): void
    {
        $h = $this->hosting('site.example', 'Suspended');
        self::mapRow(['kind' => 'hosting', 'whmcs_ref_id' => $h, 'monitor_type' => 'website', 'monitor_public_id' => 'w1', 'target' => 'site.example']);
        $this->mapClient();
        $this->remote([self::customer('c1', $this->client)]);
        $handler = $this->handler();
        $index = $this->index();

        $handler->handle('hosting.pause', $h, $index);
        $handler->handle('client.pause_all', $this->client, $index);
        $handler->handle('hosting.remove', $h, $index);

        self::assertSame(MonitorMap::STATE_ORPHANED, self::row('hosting', $h, 'website')['state'], 'the row stays for one round');
        self::assertSame([], array_filter($this->http->requests(), fn (array $r): bool => $r['method'] !== 'GET'));
    }

    public function testQuotaHitIsSharedByTheJobsOfOneBatch(): void
    {
        $h1 = $this->hosting('one.example', 'Active');
        $h2 = $this->hosting('two.example', 'Active');
        $this->mapClient();
        $this->remote([self::customer('c1', $this->client)])
            ->on('POST', '/api/websites', 403, ['statusCode' => 403, 'statusMessage' => 'Active monitor limit reached', 'data' => ['code' => 'activeMonitorLimitReached']]);
        $handler = $this->handler();
        $index = $this->index();

        $this->expectJobFailure(fn () => $handler->handle('hosting.activate', $h1, $index), true, 'activeMonitorLimitReached');
        $e = $this->expectJobFailure(fn () => $handler->handle('hosting.activate', $h2, $index), true, 'activeMonitorLimitReached');

        self::assertStringContainsString('two.example', $e->getMessage());
        self::assertCount(1, $this->sent('POST', '/api/websites'), 'the second job does not post into the quota again');
        self::assertSame('activeMonitorLimitReached', self::row('hosting', $h2, 'website')['last_error']);
    }

    // ---- errors of this job only (fix round 1) --------------------------------------------

    public function testStaleErrorCodeOnARowTheJobDidNotTouchDoesNotFailTheJob(): void
    {
        $this->settings->setBool('auto_create_customers', false);
        $h = $this->hosting('site.example', 'Active');
        self::mapRow(['kind' => 'hosting', 'whmcs_ref_id' => $h, 'monitor_type' => 'website', 'target' => 'site.example', 'state' => MonitorMap::STATE_ERROR, 'last_error' => 'activeMonitorLimitReached', 'customer_public_id' => null]);
        $this->remote([]);

        $result = $this->runJob('hosting.activate', $h);

        self::assertSame(0, $result->errors);
        self::assertSame([], $this->sent('POST', '#.#'));
    }

    public function testEachJobAttemptTriesAnInvalidUrlRowAgainAndCreatesItOnceTheDomainResolves(): void
    {
        $h = $this->hosting('new-shop.example', 'Active');
        self::mapRow(['kind' => 'hosting', 'whmcs_ref_id' => $h, 'monitor_type' => 'website', 'target' => 'new-shop.example', 'state' => MonitorMap::STATE_ERROR, 'last_error' => 'invalidUrl']);
        $this->mapClient();
        $this->remote([self::customer('c1', $this->client)])
            ->on('POST', '/api/websites', 400, ['statusCode' => 400, 'statusMessage' => 'Monitor URL could not be resolved', 'data' => ['code' => 'invalidUrl']]);

        $this->expectJobFailure(fn () => $this->runJob('hosting.activate', $h), false, 'invalidUrl');
        self::assertCount(1, $this->sent('POST', '/api/websites'), 'the job attempt sends it again');

        $this->remote([self::customer('c1', $this->client)])
            ->on('POST', '/api/websites', 200, ['id' => 12, 'publicId' => 'w2', 'customerId' => 1, 'name' => 'new-shop.example', 'url' => 'https://new-shop.example', 'status' => 'active']);
        $result = $this->runJob('hosting.activate', $h);

        self::assertSame([1, []], [$result->monitorsCreated, $result->rowErrors]);
        self::assertSame([MonitorMap::STATE_ACTIVE, null], [self::row('hosting', $h, 'website')['state'], self::row('hosting', $h, 'website')['last_error']]);
    }

    public function testASecondJobOfTheBatchDoesNotResendARefusedTargetAndDoesNotFail(): void
    {
        $h = $this->hosting('new-shop.example', 'Active');
        $this->mapClient();
        $this->remote([self::customer('c1', $this->client)])
            ->on('POST', '/api/websites', 400, ['statusCode' => 400, 'statusMessage' => 'Monitor URL could not be resolved', 'data' => ['code' => 'invalidUrl']]);
        $handler = $this->handler();
        $index = $this->index();

        $this->expectJobFailure(fn () => $handler->handle('hosting.activate', $h, $index), false, 'invalidUrl');
        $result = $handler->handle('client.upsert', $this->client, $index);

        self::assertCount(1, $this->sent('POST', '/api/websites'));
        self::assertSame([[], 0], [$result->rowErrors, $result->errors], 'an unchanged known error the job did not try again is not its failure');
    }

    public function testAServiceEditJobForARowWithAKnownQuotaErrorInABatchThatHitTheQuotaIsDone(): void
    {
        $h1 = $this->hosting('one.example', 'Active');
        $h2 = $this->hosting('two.example', 'Active');
        self::mapRow(['kind' => 'hosting', 'whmcs_ref_id' => $h2, 'monitor_type' => 'website', 'target' => 'two.example', 'state' => MonitorMap::STATE_ERROR, 'last_error' => 'activeMonitorLimitReached']);
        $this->mapClient();
        $this->remote([self::customer('c1', $this->client)])
            ->on('POST', '/api/websites', 403, ['statusCode' => 403, 'statusMessage' => 'Active monitor limit reached', 'data' => ['code' => 'activeMonitorLimitReached']]);
        $handler = $this->handler();
        $index = $this->index();

        $this->expectJobFailure(fn () => $handler->handle('hosting.activate', $h1, $index), true, 'activeMonitorLimitReached');
        $result = $handler->handle('hosting.activate', $h2, $index);

        self::assertCount(1, $this->sent('POST', '/api/websites'));
        self::assertSame([], $result->rowErrors);
    }

    // ---- ServiceDelete and the server IP (fix round 1) --------------------------------------

    public function testHostingRemoveAfterServiceDeleteRetiresTheServerIpOfTheClient(): void
    {
        $this->settings->set('on_terminate', 'delete');
        $this->settings->setBool('sync_server_ips', true);
        $server = $this->insert('tblservers', ['name' => 'web1', 'ipaddress' => '203.0.113.10']);
        $deleted = 99; // tblhosting row already gone
        $ipRef = MonitorPlanner::serverIpRefId($server, $this->client);
        self::mapRow(['origin' => 'created', 'kind' => 'hosting', 'whmcs_ref_id' => $deleted, 'monitor_type' => 'website', 'monitor_public_id' => 'w1', 'target' => 'site.example']);
        self::mapRow(['origin' => 'created', 'kind' => 'server_ip', 'whmcs_ref_id' => $ipRef, 'monitor_type' => 'customer_ip', 'monitor_public_id' => 'i1', 'target' => '203.0.113.10']);
        $this->mapClient();
        $ip = ['id' => 3, 'publicId' => 'i1', 'customerId' => 1, 'customer' => ['publicId' => 'c1'], 'ipAddress' => '203.0.113.10', 'label' => 'web1', 'status' => 'active'];
        $this->remote([self::customer('c1', $this->client)], [self::website('w1', 11, 'site.example')], [], [], [$ip])
            ->on('DELETE', '/api/websites/w1', 200, ['success' => true])
            ->on('DELETE', '/api/customer-ips/i1', 200, ['success' => true]);

        $this->runJob('hosting.remove', $deleted);

        self::assertCount(1, $this->sent('DELETE', '/api/customer-ips/i1'), 'no active hosting of the client is left on the server');
        self::assertSame([], (new MonitorMap())->all());

        // with another active hosting of the client on that server the IP monitor stays
        $this->hosting('other.example', 'Active', $server);
        self::mapRow(['origin' => 'created', 'kind' => 'hosting', 'whmcs_ref_id' => $deleted, 'monitor_type' => 'website', 'monitor_public_id' => 'w1', 'target' => 'site.example']);
        self::mapRow(['origin' => 'created', 'kind' => 'server_ip', 'whmcs_ref_id' => $ipRef, 'monitor_type' => 'customer_ip', 'monitor_public_id' => 'i1', 'target' => '203.0.113.10']);
        $this->remote([self::customer('c1', $this->client)], [self::website('w1', 11, 'site.example')], [], [], [$ip])
            ->on('DELETE', '/api/websites/w1', 200, ['success' => true]);

        $this->runJob('hosting.remove', $deleted);

        self::assertSame([], $this->sent('DELETE', '/api/customer-ips/i1'));
        self::assertSame(MonitorMap::STATE_ACTIVE, self::row('server_ip', $ipRef, 'customer_ip')['state']);
    }
}
