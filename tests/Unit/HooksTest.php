<?php

declare(strict_types=1);

namespace Uptimeify\Tests\Unit;

use Uptimeify\Tests\Fake\FakeHttpClient;
use Uptimeify\Tests\Support\DbTestCase;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\Uptimeify\Container;
use WHMCS\Module\Addon\Uptimeify\Hooks\ClientHooks;
use WHMCS\Module\Addon\Uptimeify\Hooks\CronHooks;
use WHMCS\Module\Addon\Uptimeify\Hooks\DomainHooks;
use WHMCS\Module\Addon\Uptimeify\Hooks\NavigationHooks;
use WHMCS\Module\Addon\Uptimeify\Hooks\ServiceHooks;
use WHMCS\Module\Addon\Uptimeify\Settings;
use WHMCS\Module\Addon\Uptimeify\Store\Queue;
use WHMCS\Module\Addon\Uptimeify\Store\Schema;
use WHMCS\Module\Addon\Uptimeify\Widget\StatusWidget;
use WHMCS\View\Menu\Item;

final class HooksTest extends DbTestCase
{
    private FakeHttpClient $http;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::install();
        $s = new Settings();
        $s->setApiToken('wsm_test-token');
        $s->setBool('connection_validated', true);
        $this->http = new FakeHttpClient();
        Container::useHttp($this->http);
    }

    protected function tearDown(): void
    {
        Container::reset();
        parent::tearDown();
    }

    public function testHooksEnqueueTheRightJobs(): void
    {
        ServiceHooks::onAfterModuleCreate(['params' => ['serviceid' => 7]]);
        ServiceHooks::onAfterModuleSuspend(['params' => ['serviceid' => 7]]);
        ServiceHooks::onAfterModuleTerminate(['params' => ['serviceid' => 8]]);
        DomainHooks::onAfterRegistrarRegistration(['params' => ['domainid' => 3]]);
        DomainHooks::onDomainDelete(['domainid' => 4]);
        ClientHooks::onClientClose(['userid' => 9]);
        self::assertSame(['hosting.activate:7', 'hosting.pause:7', 'hosting.remove:8', 'domain.upsert:3', 'domain.remove:4', 'client.pause_all:9'], $this->jobs());
    }

    public function testEverySpecHookMapsToItsJob(): void
    {
        ClientHooks::onClientAdd(['client_id' => 1, 'user_id' => 500, 'userid' => 1]);
        ClientHooks::onClientEdit(['userid' => 2, 'olddata' => []]);
        ClientHooks::onClientClose(['userid' => 3]);
        ClientHooks::onClientDelete(['userid' => 4]);
        ServiceHooks::onAfterModuleCreate(['params' => ['serviceid' => 10, 'userid' => 1]]);
        ServiceHooks::onAfterModuleUnsuspend(['params' => ['serviceid' => 11]]);
        ServiceHooks::onAfterModuleSuspend(['params' => ['serviceid' => 12]]);
        ServiceHooks::onAfterModuleTerminate(['params' => ['serviceid' => 13]]);
        ServiceHooks::onServiceDelete(['userid' => 1, 'clientId' => 1, 'serviceid' => 14]);
        DomainHooks::onAfterRegistrarRegistration(['params' => ['domainid' => 20]]);
        DomainHooks::onAfterRegistrarTransfer(['params' => ['domainid' => 21], 'results' => [], 'functionExists' => true, 'functionSuccessful' => true]);
        DomainHooks::onDomainTransferCompleted(['domainId' => 22, 'domain' => 'example.com']);
        DomainHooks::onDomainDelete(['userid' => 1, 'domainid' => 23]);
        // developers.whmcs.com hooks-reference/service and /domain: ServiceEdit {userid, serviceid}, DomainEdit {userid, domainid}.
        ServiceHooks::onServiceEdit(['userid' => 1, 'serviceid' => 15]);
        DomainHooks::onDomainEdit(['userid' => 1, 'domainid' => 24]);

        self::assertSame([
            'client.upsert:1', 'client.upsert:2', 'client.pause_all:3', 'client.remove:4',
            'hosting.activate:10', 'hosting.activate:11', 'hosting.pause:12', 'hosting.remove:13', 'hosting.remove:14',
            'domain.upsert:20', 'domain.upsert:21', 'domain.upsert:22', 'domain.remove:23',
            'hosting.activate:15', 'domain.upsert:24',
        ], $this->jobs());
    }

    public function testDocumentedAlternativeVariableNamesAreAccepted(): void
    {
        ClientHooks::onClientAdd(['userid' => '5']);
        ClientHooks::onClientEdit(['client_id' => 6]);
        ServiceHooks::onAfterModuleCreate(['serviceid' => 30]);
        ServiceHooks::onServiceDelete(['params' => ['serviceid' => 31]]);
        DomainHooks::onAfterRegistrarTransfer(['domainid' => 40]);
        DomainHooks::onDomainTransferCompleted(['domainid' => 41]);
        DomainHooks::onDomainDelete(['params' => ['domainid' => 42]]);
        ServiceHooks::onServiceEdit(['serviceid' => '32']);
        DomainHooks::onDomainEdit(['domainId' => 43]);

        self::assertSame(['client.upsert:5', 'client.upsert:6', 'hosting.activate:30', 'hosting.remove:31', 'domain.upsert:40', 'domain.upsert:41', 'domain.remove:42', 'hosting.activate:32', 'domain.upsert:43'], $this->jobs());
    }

    public function testUserIdOfTheOwningUserIsNeverTakenForTheClientId(): void
    {
        ClientHooks::onClientAdd(['user_id' => 500]);
        self::assertSame([], $this->jobs());
        self::assertStringContainsString('client.upsert', $this->activity());
    }

    public function testAPendingJobIsNotDuplicated(): void
    {
        ServiceHooks::onAfterModuleCreate(['params' => ['serviceid' => 7]]);
        ServiceHooks::onAfterModuleUnsuspend(['params' => ['serviceid' => 7]]);
        self::assertSame(['hosting.activate:7'], $this->jobs());
    }

    public function testHooksAreSilentWhenNotConnectedOrAutoSyncOff(): void
    {
        (new Settings())->setBool('auto_sync', false);
        ServiceHooks::onAfterModuleCreate(['params' => ['serviceid' => 7]]);
        ServiceHooks::onAfterModuleCreate([]);
        self::assertSame([], $this->jobs());
        self::assertSame([], $GLOBALS['uptimeify_test']['activity']);
    }

    public function testHooksAreSilentWhenTheConnectionIsNotValidated(): void
    {
        (new Settings())->setBool('connection_validated', false);
        ClientHooks::onClientAdd(['client_id' => 1]);
        DomainHooks::onDomainDelete(['domainid' => 4]);
        self::assertSame([], $this->jobs());
        self::assertSame([], $GLOBALS['uptimeify_test']['activity']);
    }

    public function testHooksNeverThrow(): void
    {
        ServiceHooks::onAfterModuleCreate(['params' => []]);
        ServiceHooks::onAfterModuleCreate([]);
        ServiceHooks::onAfterModuleCreate(['params' => 'garbage']);
        ServiceHooks::onAfterModuleCreate(['params' => ['serviceid' => ['x']]]);
        DomainHooks::onDomainTransferCompleted(['domainId' => 'abc']);
        ClientHooks::onClientClose(['userid' => -3]);
        self::assertSame([], $this->jobs());
        self::assertNotSame([], $GLOBALS['uptimeify_test']['activity']);
    }

    public function testADatabaseFailureInAHookIsLoggedNotThrown(): void
    {
        Capsule::schema()->drop('mod_uptimeify_queue');
        ServiceHooks::onAfterModuleCreate(['params' => ['serviceid' => 7]]);
        self::assertStringContainsString('hosting.activate', $this->activity());
    }

    public function testHooksNeverCallTheApi(): void
    {
        ClientHooks::onClientAdd(['client_id' => 1]);
        ClientHooks::onClientEdit(['userid' => 1]);
        ClientHooks::onClientClose(['userid' => 1]);
        ClientHooks::onClientDelete(['userid' => 1]);
        ServiceHooks::onAfterModuleCreate(['params' => ['serviceid' => 1]]);
        ServiceHooks::onAfterModuleSuspend(['params' => ['serviceid' => 1]]);
        ServiceHooks::onAfterModuleUnsuspend(['params' => ['serviceid' => 1]]);
        ServiceHooks::onAfterModuleTerminate(['params' => ['serviceid' => 1]]);
        ServiceHooks::onServiceDelete(['serviceid' => 1]);
        DomainHooks::onAfterRegistrarRegistration(['params' => ['domainid' => 1]]);
        DomainHooks::onAfterRegistrarTransfer(['params' => ['domainid' => 1]]);
        DomainHooks::onDomainTransferCompleted(['domainId' => 1]);
        DomainHooks::onDomainDelete(['domainid' => 1]);
        ServiceHooks::onServiceEdit(['userid' => 1, 'serviceid' => 2]);
        DomainHooks::onDomainEdit(['userid' => 1, 'domainid' => 2]);
        self::assertSame([], $this->http->requests());
        self::assertCount(10, $this->jobs());
    }

    public function testHooksFileRegistersAllHookPoints(): void
    {
        $hooks = $this->loadHooksFile();
        $registered = array_keys($hooks);
        foreach (['ClientAdd', 'ClientEdit', 'ClientClose', 'ClientDelete', 'AfterModuleCreate', 'AfterModuleSuspend', 'AfterModuleUnsuspend', 'AfterModuleTerminate', 'ServiceEdit', 'ServiceDelete', 'AfterRegistrarRegistration', 'AfterRegistrarTransfer', 'DomainTransferCompleted', 'DomainEdit', 'DomainDelete', 'AfterCronJob', 'DailyCronJob', 'ClientAreaPrimaryNavbar', 'ClientAreaProductDetailsOutput', 'AdminHomeWidgets'] as $hook) {
            self::assertContains($hook, $registered, $hook);
        }
        foreach ($hooks as $hook => $callbacks) {
            self::assertCount(1, $callbacks, $hook);
        }
    }

    public function testRegisteredCallbacksRunThroughTheHookClasses(): void
    {
        $hooks = $this->loadHooksFile();

        $hooks['ClientDelete'][0](['userid' => 4]);
        $hooks['DomainTransferCompleted'][0](['domainId' => 22]);
        $hooks['ServiceEdit'][0](['userid' => 1, 'serviceid' => 15]);
        $hooks['DomainEdit'][0](['userid' => 1, 'domainid' => 24]);
        self::assertSame(['client.remove:4', 'domain.upsert:22', 'hosting.activate:15', 'domain.upsert:24'], $this->jobs());
        self::assertArrayNotHasKey('PreServiceEdit', $hooks, 'only the hook after the change is saved');

        self::assertInstanceOf(StatusWidget::class, $hooks['AdminHomeWidgets'][0]());
        self::assertSame('', $hooks['ClientAreaProductDetailsOutput'][0](['serviceid' => 1]), 'nobody logged in');
        $menu = new Item();
        $hooks['ClientAreaPrimaryNavbar'][0]($menu);
        self::assertSame([], $menu->children, 'nobody logged in');
    }

    public function testNavigationHooksAreInertWithoutLogin(): void
    {
        self::assertSame('', NavigationHooks::productDetailsOutput([]));
    }

    public function testCronHooksAreNoOpsWhenNotConnected(): void
    {
        Container::settings()->setBool('connection_validated', false);
        Container::queue()->push('hosting.activate', 1);
        CronHooks::afterCronJob();
        CronHooks::dailyCronJob();
        self::assertSame([], $this->http->requests());
        self::assertSame([], $GLOBALS['uptimeify_test']['activity']);
    }

    public function testCronHooksAreNoOpsWhenAutoSyncIsOff(): void
    {
        Container::settings()->setBool('auto_sync', false);
        Container::queue()->push('hosting.activate', 1);
        CronHooks::afterCronJob();
        CronHooks::dailyCronJob();
        self::assertSame([], $this->http->requests());
        self::assertSame([], $GLOBALS['uptimeify_test']['activity']);
        self::assertSame(['hosting.activate:1'], $this->jobs());
    }

    public function testAfterCronJobRunsTheQueueWorkerWithTheSharedSettings(): void
    {
        Container::queue()->push('hosting.activate', 1);
        $this->http->on('GET', '#^/api/#', 401, ['statusCode' => 401, 'statusMessage' => 'Unauthorized']);

        CronHooks::afterCronJob();

        self::assertNotSame([], $this->http->requests());
        self::assertStringContainsString('Queue worker stopped', $this->activity());
        self::assertFalse(Container::settings()->isConnected(), 'the worker reset is visible through the shared Settings');
        self::assertFalse((new Settings())->isConnected());
        self::assertSame(['hosting.activate:1'], $this->jobs(), 'job stays pending');
    }

    public function testDailyCronJobRunsTheFullReconcile(): void
    {
        $this->http->on('GET', '#^/api/#', 401, ['statusCode' => 401, 'statusMessage' => 'Unauthorized']);

        CronHooks::dailyCronJob();

        self::assertNotSame([], $this->http->requests());
        self::assertStringContainsString('Reconcile finished', $this->activity());
        self::assertFalse(Container::settings()->isConnected());
    }

    public function testCronWithAnUnusableTokenResetsTheConnectionWithoutHttp(): void
    {
        // The fake DecryptPassword turns this cipher into an empty token (as after a changed cc_encryption_hash).
        Capsule::table('tbladdonmodules')->where('module', 'uptimeify')->where('setting', 'api_token')->update(['value' => 'enc:']);
        Container::queue()->push('hosting.activate', 1);

        CronHooks::afterCronJob();
        CronHooks::dailyCronJob();

        self::assertSame([], $this->http->requests());
        self::assertFalse((new Settings())->isConnected());
        self::assertStringContainsString('no usable API token', $this->activity());
    }

    public function testCronWithADisallowedBaseUrlNeverSendsTheToken(): void
    {
        Container::settings()->set('api_base_url', 'http://evil.example');
        Container::queue()->push('hosting.activate', 1);

        CronHooks::afterCronJob();

        self::assertSame([], $this->http->requests());
        self::assertFalse((new Settings())->isConnected());
        self::assertStringContainsString('base URL', $this->activity());
    }

    public function testCronFailureIsLoggedNotThrown(): void
    {
        Capsule::schema()->drop('mod_uptimeify_queue');
        CronHooks::afterCronJob();
        self::assertStringContainsString('cron', $this->activity());
    }

    /** @return array<string,list<callable>> hook name => registered callbacks */
    private function loadHooksFile(): array
    {
        $GLOBALS['uptimeify_test']['hooks'] = [];
        require dirname(__DIR__, 2) . '/hooks.php';
        return $GLOBALS['uptimeify_test']['hooks'];
    }

    /** @return list<string> */
    private function jobs(): array
    {
        return array_map(static fn (array $r): string => $r['job'] . ':' . $r['ref_id'], (new Queue())->due(50, '2999-01-01 00:00:00'));
    }

    private function activity(): string
    {
        return implode("\n", array_map(static fn (array $a): string => (string) $a[0], $GLOBALS['uptimeify_test']['activity']));
    }
}
