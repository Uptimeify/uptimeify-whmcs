<?php

declare(strict_types=1);

namespace Uptimeify\Tests\Unit;

use Uptimeify\Tests\Fake\FakeHttpClient;
use Uptimeify\Tests\Support\DbTestCase;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\Uptimeify\Container;
use WHMCS\Module\Addon\Uptimeify\Settings;
use WHMCS\Module\Addon\Uptimeify\Store\Schema;
use WHMCS\Module\Addon\Uptimeify\Sync\JobHandler;
use WHMCS\Module\Addon\Uptimeify\Sync\QueueWorker;
use WHMCS\Module\Addon\Uptimeify\Sync\Reconciler;

final class ContainerTest extends DbTestCase
{
    private FakeHttpClient $http;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::install();
        $this->http = (new FakeHttpClient())->on('GET', '/api/organization', 200, ['id' => 1, 'name' => 'Acme']);
        Container::useHttp($this->http);
    }

    protected function tearDown(): void
    {
        Container::reset();
        parent::tearDown();
    }

    public function testSettingsInstanceIsSharedUntilReset(): void
    {
        $a = Container::settings();
        self::assertSame($a, Container::settings());
        Container::reset();
        self::assertNotSame($a, Container::settings());
    }

    public function testNoTokenMeansNoApi(): void
    {
        self::assertNull(Container::api());
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not connected');
        Container::apiOrFail();
    }

    public function testEmptyDecryptedTokenMeansNoApi(): void
    {
        // The fake DecryptPassword turns this cipher into '' (as after a changed cc_encryption_hash).
        Capsule::table('tbladdonmodules')->insert(['module' => 'uptimeify', 'setting' => 'api_token', 'value' => 'enc:']);
        self::assertTrue(Container::settings()->hasApiToken());
        self::assertNull(Container::api());
    }

    public function testApiUsesTheStoredTokenAndBaseUrl(): void
    {
        Container::settings()->setApiToken('wsm_test-token');
        Container::settings()->set('api_base_url', 'http://host.docker.internal:3001/');
        Container::apiOrFail()->getOrganization();
        $request = $this->http->requests()[0];
        self::assertSame('http://host.docker.internal:3001/api/organization', $request['url']);
        self::assertSame('Bearer wsm_test-token', $request['headers']['Authorization']);
    }

    public function testDisallowedBaseUrlNeverGetsTheToken(): void
    {
        Container::settings()->setApiToken('wsm_test-token');
        Container::settings()->set('api_base_url', 'http://evil.example');
        self::assertNull(Container::api());
        try {
            Container::apiWithToken('wsm_other-token');
            self::fail('expected RuntimeException');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('base URL', $e->getMessage());
        }
        self::assertSame([], $this->http->requests());
    }

    public function testApiWithTokenUsesTheGivenToken(): void
    {
        Container::apiWithToken('wsm_new-token')->getOrganization();
        $request = $this->http->requests()[0];
        self::assertSame('https://uptimeify.io/api/organization', $request['url']);
        self::assertSame('Bearer wsm_new-token', $request['headers']['Authorization']);
    }

    public function testSyncGraphBuildsWhenConnected(): void
    {
        Container::settings()->setApiToken('wsm_test-token');
        Container::settings()->setBool('connection_validated', true);
        self::assertInstanceOf(Reconciler::class, Container::reconciler());
        self::assertInstanceOf(JobHandler::class, Container::jobHandler());
        self::assertInstanceOf(QueueWorker::class, Container::queueWorker());
        self::assertSame([], Container::planner()->planAll());
        self::assertSame([], $this->http->requests(), 'building the graph sends nothing');
    }

    public function testSyncGraphNeedsAConnection(): void
    {
        $this->expectException(\RuntimeException::class);
        Container::queueWorker();
    }

    public function testStoresAreFresh(): void
    {
        Container::queue()->push('client.upsert', 1);
        self::assertSame(['pending' => 1, 'running' => 0, 'dead' => 0], Container::queue()->counts());
        self::assertNull(Container::statusCache()->get('org'));
        self::assertSame([], Container::customerMap()->all());
        self::assertSame([], Container::monitorMap()->all());
        self::assertInstanceOf(Settings::class, Container::settings());
    }
}
