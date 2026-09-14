<?php

declare(strict_types=1);

namespace Uptimeify\Tests\Unit\Sync;

use PHPUnit\Framework\MockObject\MockObject;
use Uptimeify\Tests\Fake\FakeHttpClient;
use Uptimeify\Tests\Support\DbTestCase;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\Uptimeify\Api\Client;
use WHMCS\Module\Addon\Uptimeify\Api\Exception\RateLimitedException;
use WHMCS\Module\Addon\Uptimeify\Api\Exception\UnauthorizedException;
use WHMCS\Module\Addon\Uptimeify\Settings;
use WHMCS\Module\Addon\Uptimeify\Store\CustomerMap;
use WHMCS\Module\Addon\Uptimeify\Store\MonitorMap;
use WHMCS\Module\Addon\Uptimeify\Store\Queue;
use WHMCS\Module\Addon\Uptimeify\Store\Schema;
use WHMCS\Module\Addon\Uptimeify\Store\StatusCache;
use WHMCS\Module\Addon\Uptimeify\Store\SyncLock;
use WHMCS\Module\Addon\Uptimeify\Sync\CustomerFilter;
use WHMCS\Module\Addon\Uptimeify\Sync\CustomerMirror;
use WHMCS\Module\Addon\Uptimeify\Sync\JobFailedException;
use WHMCS\Module\Addon\Uptimeify\Sync\JobHandler;
use WHMCS\Module\Addon\Uptimeify\Sync\MonitorPlanner;
use WHMCS\Module\Addon\Uptimeify\Sync\QueueWorker;
use WHMCS\Module\Addon\Uptimeify\Sync\RateLimitGuard;
use WHMCS\Module\Addon\Uptimeify\Sync\Reconciler;
use WHMCS\Module\Addon\Uptimeify\Sync\ReconcileResult;
use WHMCS\Module\Addon\Uptimeify\Sync\RemoteIndex;
use WHMCS\Module\Addon\Uptimeify\Whmcs\ClientRepository;
use WHMCS\Module\Addon\Uptimeify\Whmcs\DomainRepository;
use WHMCS\Module\Addon\Uptimeify\Whmcs\HostingRepository;
use WHMCS\Module\Addon\Uptimeify\Whmcs\ServerRepository;

final class QueueWorkerTest extends DbTestCase
{
    private const TOKEN = 'wsm_fake-token';

    /** Far future, so jobs pushed with the wall clock are due and results never depend on today's date (T11-1). */
    private string $now = '2999-01-01 00:00:00';

    private FakeHttpClient $http;
    private Settings $settings;
    private Queue $queue;
    private JobHandler&MockObject $handler;
    private Reconciler&MockObject $reconciler;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::install();
        $this->http = (new FakeHttpClient())
            ->on('GET', '/api/organization', 200, ['id' => 7, 'publicId' => 'o1', 'name' => 'Acme Org'])
            ->on('GET', '/api/customers', 200, ['items' => [], 'total' => 0, 'page' => 1, 'perPage' => 100])
            ->on('GET', '/api/websites', 200, ['items' => [], 'total' => 0, 'page' => 1, 'perPage' => 100])
            ->on('GET', '/api/customer-domains', 200, ['items' => [], 'total' => 0, 'page' => 1, 'perPage' => 100])
            ->on('GET', '/api/dns-monitors', 200, ['items' => [], 'total' => 0, 'page' => 1, 'perPage' => 100])
            ->on('GET', '/api/customer-ips', 200, ['items' => [], 'total' => 0, 'page' => 1, 'perPage' => 100]);
        $this->settings = new Settings();
        $this->settings->setApiToken(self::TOKEN);
        $this->settings->setBool('connection_validated', true);
        $this->queue = new Queue(fn (): string => $this->now);
        $this->handler = $this->createMock(JobHandler::class);
        $this->reconciler = $this->createMock(Reconciler::class);
    }

    private function worker(): QueueWorker
    {
        $clock = fn (): string => $this->now;
        return new QueueWorker(
            new Queue($clock),
            $this->handler,
            new Client($this->http, 'https://api.test', self::TOKEN),
            new RateLimitGuard(fn (int $s) => null),
            new SyncLock(),
            $this->reconciler,
            $this->settings,
            $clock,
        );
    }

    /** @return array<string,mixed> */
    private function job(int $id): array
    {
        $row = Capsule::table('mod_uptimeify_queue')->where('id', $id)->first();
        self::assertNotNull($row);
        $row = (array) $row;
        $row['attempts'] = (int) $row['attempts'];
        return $row;
    }

    /** @return list<array<string,mixed>> logModuleCall entries written for queue jobs (not for API requests) */
    private static function jobLogs(): array
    {
        return array_values(array_filter(
            $GLOBALS['uptimeify_test']['moduleCalls'],
            fn (array $c): bool => in_array($c['action'], JobHandler::JOBS, true) || $c['action'] === 'unknown.job',
        ));
    }

    public function testProcessesDueJobsOldestFirstLogsEachAndRefreshesTheCacheOnce(): void
    {
        $a = $this->queue->push('hosting.activate', 1);
        $b = $this->queue->push('domain.upsert', 2);
        $seen = [];
        $this->handler->expects(self::exactly(2))->method('handle')
            ->willReturnCallback(function (string $job, int $refId, RemoteIndex $index) use (&$seen): ReconcileResult {
                $seen[] = [$job, $refId];
                $result = new ReconcileResult();
                $result->monitorsCreated = 1;
                return $result;
            });
        $this->reconciler->expects(self::once())->method('refreshCache')->with(self::isInstanceOf(RemoteIndex::class));

        $stats = $this->worker()->run();

        self::assertSame(['processed' => 2, 'failed' => 0, 'dead' => 0], $stats);
        self::assertSame([['hosting.activate', 1], ['domain.upsert', 2]], $seen);
        self::assertSame(['done', 'done'], [$this->job($a)['status'], $this->job($b)['status']]);
        self::assertSame(['pending' => 0, 'running' => 0, 'dead' => 0], $this->queue->counts());
        self::assertCount(4, array_filter($this->http->requests(), fn (array $r): bool => $r['method'] === 'GET' && !str_contains($r['url'], 'customers') && !str_contains($r['url'], 'organization')), 'one remote index for the whole batch');
        self::assertCount(1, array_filter($this->http->requests(), fn (array $r): bool => str_ends_with($r['url'], '/api/organization')), 'the organization is checked once per run');

        $logs = self::jobLogs();
        self::assertSame(['hosting.activate', 'domain.upsert'], array_column($logs, 'action'));
        self::assertSame('uptimeify', $logs[0]['module']);
        self::assertSame([self::TOKEN], $logs[0]['replaceVars'], 'the token is masked in every job log');
        self::assertSame(1, $logs[0]['request']['ref_id']);
        self::assertSame('done', $logs[0]['response']['status']);
        self::assertStringContainsString('created=1', $logs[0]['response']['summary']);
    }

    public function testTakesAtMostTheLimitOldestFirst(): void
    {
        $first = $this->queue->push('hosting.activate', 1);
        $this->queue->push('hosting.activate', 2);
        $third = $this->queue->push('hosting.activate', 3);
        $this->handler->expects(self::exactly(2))->method('handle')->willReturn(new ReconcileResult());

        $stats = $this->worker()->run(2);

        self::assertSame(2, $stats['processed']);
        self::assertSame('done', $this->job($first)['status']);
        self::assertSame('pending', $this->job($third)['status']);
    }

    public function testDefaultLimitIsFifty(): void
    {
        for ($i = 1; $i <= 51; $i++) {
            $this->queue->push('hosting.activate', $i);
        }
        $this->handler->expects(self::exactly(50))->method('handle')->willReturn(new ReconcileResult());

        self::assertSame(50, $this->worker()->run()['processed']);
        self::assertSame(1, $this->queue->counts()['pending']);
    }

    public function testFailureBacksOffOneFiveThirtyThenHundredTwentyMinutesAndDiesAfterEightAttempts(): void
    {
        $id = $this->queue->push('hosting.activate', 1);
        $this->handler->method('handle')->willThrowException(new JobFailedException('hosting:1:website: create failed: http500', false, new ReconcileResult()));

        $expected = [1 => 1, 2 => 5, 3 => 30, 4 => 120, 5 => 120, 6 => 120, 7 => 120];
        foreach ($expected as $attempt => $minutes) {
            $stats = $this->worker()->run();
            self::assertSame(['processed' => 0, 'failed' => 1, 'dead' => 0], $stats, "attempt {$attempt}");
            $row = $this->job($id);
            self::assertSame($attempt, $row['attempts']);
            self::assertSame('pending', $row['status']);
            self::assertSame(gmdate('Y-m-d H:i:s', strtotime($this->now . ' UTC') + $minutes * 60), $row['next_run_at'], "backoff after attempt {$attempt}");
            self::assertSame('hosting:1:website: create failed: http500', $row['last_error']);
            self::assertSame(0, $this->worker()->run()['failed'], 'not due before the backoff ends');
            $this->now = (string) $row['next_run_at'];
        }

        $stats = $this->worker()->run();

        self::assertSame(['processed' => 0, 'failed' => 0, 'dead' => 1], $stats);
        self::assertSame(['dead', 8], [$this->job($id)['status'], $this->job($id)['attempts']]);
        self::assertSame(1, $this->queue->counts()['dead']);
        self::assertNotSame([], array_filter($GLOBALS['uptimeify_test']['activity'], fn (array $a): bool => str_contains($a[0], 'dead')));
    }

    public function testFatalFailureGoesStraightToDeadWithThePlainReason(): void
    {
        $id = $this->queue->push('hosting.activate', 1);
        $reason = 'hosting:1:website site.example: monitor quota of the customer reached (activeMonitorLimitReached)';
        $this->handler->method('handle')->willThrowException(new JobFailedException($reason, true, new ReconcileResult()));

        $stats = $this->worker()->run();

        self::assertSame(['processed' => 0, 'failed' => 0, 'dead' => 1], $stats);
        self::assertSame(['dead', 1, $reason], [$this->job($id)['status'], $this->job($id)['attempts'], $this->job($id)['last_error']]);
        self::assertSame('dead', self::jobLogs()[0]['response']['status']);
        self::assertSame($reason, self::jobLogs()[0]['response']['error']);
    }

    public function testUnknownJobIsDeadWithoutCallingTheHandler(): void
    {
        $id = $this->queue->push('unknown.job', 1);
        $this->handler->expects(self::never())->method('handle');

        self::assertSame(1, $this->worker()->run()['dead']);
        self::assertSame('dead', $this->job($id)['status']);
        self::assertStringContainsString('unknown job', (string) $this->job($id)['last_error']);
    }

    public function testUnexpectedExceptionIsRetried(): void
    {
        $id = $this->queue->push('hosting.activate', 1);
        $this->handler->method('handle')->willThrowException(new \RuntimeException('database gone'));

        self::assertSame(1, $this->worker()->run()['failed']);
        self::assertSame(['pending', 1, 'database gone'], [$this->job($id)['status'], $this->job($id)['attempts'], $this->job($id)['last_error']]);
    }

    public function testUnauthorizedResetsTheConnectionAndStopsTheRunWithoutUsingAnAttempt(): void
    {
        $a = $this->queue->push('hosting.activate', 1);
        $b = $this->queue->push('hosting.activate', 2);
        $this->handler->expects(self::once())->method('handle')->willThrowException(new UnauthorizedException('uptimeify API error (HTTP 401): Unauthorized', 401, 'unauthorized'));
        $this->reconciler->expects(self::never())->method('refreshCache');

        $stats = $this->worker()->run();

        self::assertSame(['processed' => 0, 'failed' => 0, 'dead' => 0], $stats);
        self::assertFalse((new Settings())->isConnected());
        self::assertSame(['pending', 0], [$this->job($a)['status'], $this->job($a)['attempts']]);
        self::assertSame(['pending', 0], [$this->job($b)['status'], $this->job($b)['attempts']]);
        self::assertTrue((new SyncLock())->acquire(), 'lock released');
    }

    public function testUnauthorizedWhileLoadingTheRemoteIndexResetsTheConnection(): void
    {
        $id = $this->queue->push('hosting.activate', 1);
        $this->http = (new FakeHttpClient())->on('GET', '/api/organization', 200, ['id' => 7, 'publicId' => 'o1'])->on('GET', '/api/customers', 401, ['statusCode' => 401, 'statusMessage' => 'Unauthorized']);
        $this->handler->expects(self::never())->method('handle');

        self::assertSame(['processed' => 0, 'failed' => 0, 'dead' => 0], $this->worker()->run());
        self::assertFalse((new Settings())->isConnected());
        self::assertSame(['pending', 0], [$this->job($id)['status'], $this->job($id)['attempts']]);
    }

    public function testRateLimitStopsTheRunAndLeavesTheRestForTheNextCron(): void
    {
        $a = $this->queue->push('hosting.activate', 1);
        $b = $this->queue->push('hosting.activate', 2);
        $this->handler->expects(self::once())->method('handle')->willThrowException(new RateLimitedException('uptimeify API error (HTTP 429)', 30));
        $this->reconciler->expects(self::never())->method('refreshCache');

        $stats = $this->worker()->run();

        self::assertSame(['processed' => 0, 'failed' => 0, 'dead' => 0], $stats);
        self::assertSame(['pending', 0], [$this->job($a)['status'], $this->job($a)['attempts']]);
        self::assertSame(['pending', 0], [$this->job($b)['status'], $this->job($b)['attempts']]);
        self::assertTrue((new Settings())->isConnected());
    }

    public function testRemoteListFailureTouchesNoJob(): void
    {
        $id = $this->queue->push('hosting.activate', 1);
        $this->http = (new FakeHttpClient())->on('GET', '/api/organization', 200, ['id' => 7, 'publicId' => 'o1'])->on('GET', '/api/customers', 500, ['statusCode' => 500, 'statusMessage' => 'boom']);
        $this->handler->expects(self::never())->method('handle');
        $this->reconciler->expects(self::never())->method('refreshCache');

        self::assertSame(['processed' => 0, 'failed' => 0, 'dead' => 0], $this->worker()->run());
        self::assertSame(['pending', 0], [$this->job($id)['status'], $this->job($id)['attempts']]);
        self::assertNotSame([], $GLOBALS['uptimeify_test']['activity']);
    }

    public function testAnotherOrganizationStopsTheRunBeforeAnyJob(): void
    {
        $id = $this->queue->push('hosting.activate', 1);
        $this->settings->set('organization_public_id', 'o-bound');
        $this->handler->expects(self::never())->method('handle');
        $this->reconciler->expects(self::never())->method('refreshCache');

        self::assertSame(['processed' => 0, 'failed' => 0, 'dead' => 0], $this->worker()->run());

        self::assertSame(['GET https://api.test/api/organization'], array_map(fn (array $r): string => $r['method'] . ' ' . $r['url'], $this->http->requests()));
        self::assertSame(['pending', 0], [$this->job($id)['status'], $this->job($id)['attempts']]);
        self::assertSame('o1', (new Settings())->organizationMismatch()['publicId'] ?? null);
        self::assertTrue((new Settings())->isConnected());
        self::assertNotSame([], array_filter(array_column($GLOBALS['uptimeify_test']['activity'], 0), fn (string $m): bool => str_contains($m, 'Queue worker stopped') && str_contains($m, 'organization')));
    }

    public function testNothingDueMeansNoApiCallsAndNoCacheRefresh(): void
    {
        $this->queue->push('hosting.activate', 1);
        Capsule::table('mod_uptimeify_queue')->update(['next_run_at' => '2999-01-01 00:05:00']);
        $this->handler->expects(self::never())->method('handle');
        $this->reconciler->expects(self::never())->method('refreshCache');

        self::assertSame(['processed' => 0, 'failed' => 0, 'dead' => 0], $this->worker()->run());
        self::assertSame([], $this->http->requests());
    }

    public function testDoesNothingWhenNotConnectedOrAutoSyncIsOff(): void
    {
        $this->queue->push('hosting.activate', 1);
        $this->handler->expects(self::never())->method('handle');

        $this->settings->setBool('auto_sync', false);
        self::assertSame(['processed' => 0, 'failed' => 0, 'dead' => 0], $this->worker()->run());

        $this->settings->setBool('auto_sync', true);
        $this->settings->setBool('connection_validated', false);
        self::assertSame(['processed' => 0, 'failed' => 0, 'dead' => 0], $this->worker()->run());

        self::assertSame([], $this->http->requests());
        self::assertSame(1, $this->queue->counts()['pending']);
    }

    public function testHeldLockMeansNoWork(): void
    {
        $this->queue->push('hosting.activate', 1);
        $this->handler->expects(self::never())->method('handle');
        $lock = new SyncLock();
        self::assertTrue($lock->acquire());

        self::assertSame(['processed' => 0, 'failed' => 0, 'dead' => 0], $this->worker()->run());

        $lock->release();
        self::assertSame([], $this->http->requests());
    }

    public function testJobsRunWhileTheWorkerHoldsTheLockAndItIsReleasedAfterwards(): void
    {
        $this->queue->push('hosting.activate', 1);
        $heldDuringJob = null;
        $this->handler->method('handle')->willReturnCallback(function () use (&$heldDuringJob): ReconcileResult {
            $probe = new SyncLock();
            $heldDuringJob = !$probe->acquire();
            if (!$heldDuringJob) {
                $probe->release();
            }
            return new ReconcileResult();
        });

        $this->worker()->run();

        self::assertTrue($heldDuringJob, 'the worker holds the sync lock while jobs run');
        $lock = new SyncLock();
        self::assertTrue($lock->acquire(), 'released after the run');
        $lock->release();
    }

    public function testStaleRunningJobsAreResetAtStart(): void
    {
        $stale = $this->queue->push('hosting.activate', 1);
        $fresh = $this->queue->push('hosting.activate', 2);
        Capsule::table('mod_uptimeify_queue')->where('id', $stale)->update(['status' => 'running', 'next_run_at' => '2998-12-31 23:44:00']);
        Capsule::table('mod_uptimeify_queue')->where('id', $fresh)->update(['status' => 'running', 'next_run_at' => '2998-12-31 23:50:00']);
        $this->handler->expects(self::once())->method('handle')->with('hosting.activate', 1)->willReturn(new ReconcileResult());

        $stats = $this->worker()->run();

        self::assertSame(1, $stats['processed']);
        self::assertSame(['done', 1], [$this->job($stale)['status'], $this->job($stale)['attempts']], 'a job running for 15 minutes lost its worker: counted as an attempt and run again');
        self::assertSame('running', $this->job($fresh)['status'], 'a job running for 10 minutes is left alone');
    }

    public function testPurgesDoneJobsOlderThanSevenDays(): void
    {
        $old = $this->queue->push('hosting.activate', 1);
        Capsule::table('mod_uptimeify_queue')->where('id', $old)->update(['status' => 'done', 'finished_at' => '2998-12-24 00:00:00']);
        $recent = $this->queue->push('hosting.activate', 2);
        Capsule::table('mod_uptimeify_queue')->where('id', $recent)->update(['status' => 'done', 'finished_at' => '2998-12-30 00:00:00']);
        $this->queue->push('domain.upsert', 3);
        $this->handler->method('handle')->willReturn(new ReconcileResult());

        $this->worker()->run();

        self::assertNull(Capsule::table('mod_uptimeify_queue')->where('id', $old)->first());
        self::assertNotNull(Capsule::table('mod_uptimeify_queue')->where('id', $recent)->first());
    }

    public function testSetupErrorFromTheApiIsDeadAtOnceWithAPlainReason(): void
    {
        $client = $this->insert('tblclients', ['companyname' => 'Acme', 'email' => 'acme@example.com']);
        $hosting = $this->insert('tblhosting', ['userid' => $client, 'domain' => 'site.example', 'domainstatus' => 'Active']);
        $this->settings->set('default_package_type', 'gone');
        $this->http->on('POST', '/api/customers', 400, ['statusCode' => 400, 'statusMessage' => 'Unknown package', 'data' => ['code' => 'invalidPackageType']])
            ->on('GET', '/api/incidents', 200, ['incidents' => [], 'total' => 0, 'limit' => 500, 'offset' => 0]);
        $id = $this->queue->push('hosting.activate', $hosting);

        $api = new Client($this->http, 'https://api.test', self::TOKEN);
        $guard = new RateLimitGuard(fn (int $s) => null);
        $customerMap = new CustomerMap();
        $planner = new MonitorPlanner($this->settings, new CustomerFilter($this->settings), new ClientRepository(), new HostingRepository(), new DomainRepository(), new ServerRepository());
        $reconciler = new Reconciler($api, $this->settings, $customerMap, new MonitorMap(), $planner, new CustomerMirror($api, $this->settings, $customerMap, $guard), new StatusCache(), new ClientRepository(), new HostingRepository(), new SyncLock(), $guard);
        $handler = new JobHandler($reconciler, $planner, new MonitorMap(), new ClientRepository(), new HostingRepository(), $api, $guard, $this->settings, $customerMap);
        $clock = fn (): string => $this->now;
        $worker = new QueueWorker(new Queue($clock), $handler, $api, $guard, new SyncLock(), $reconciler, $this->settings, $clock);

        $stats = $worker->run();

        self::assertSame(['processed' => 0, 'failed' => 0, 'dead' => 1], $stats);
        $row = $this->job($id);
        self::assertSame(['dead', 1], [$row['status'], $row['attempts']]);
        self::assertStringContainsString('default package', (string) $row['last_error']);
        self::assertStringContainsString('(invalidPackageType)', (string) $row['last_error']);
    }
}
