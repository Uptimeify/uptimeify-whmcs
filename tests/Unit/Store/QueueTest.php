<?php

declare(strict_types=1);

namespace Uptimeify\Tests\Unit\Store;

use Uptimeify\Tests\Support\DbTestCase;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\Uptimeify\Store\Queue;
use WHMCS\Module\Addon\Uptimeify\Store\Schema;

final class QueueTest extends DbTestCase
{
    private string $now = '2026-09-13 12:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        Schema::install();
    }

    public function testPushDedupesPendingAndDueOrdersOldestFirst(): void
    {
        $q = new Queue();
        $a = $q->push('hosting.activate', 7);
        $b = $q->push('hosting.activate', 7);
        self::assertSame($a, $b);
        $q->push('domain.upsert', 3);
        self::assertTrue($q->isPending('hosting.activate', 7));
        self::assertFalse($q->isPending('hosting.activate', 8));
        self::assertFalse($q->isPending('domain.remove', 3));
        self::assertSame(['pending' => 2, 'running' => 0, 'dead' => 0], $q->counts());
        $due = $q->due(10);
        self::assertSame(['hosting.activate', 'domain.upsert'], array_column($due, 'job'));
    }

    public function testFailureBackoffAndDead(): void
    {
        $q = new Queue();
        $id = $q->push('hosting.activate', 7);
        $q->markRunning($id);
        self::assertFalse($q->isPending('hosting.activate', 7), 'a running job is not pending');
        $q->markFailed($id, 'boom', 1, '2999-01-01 00:00:00');
        self::assertSame([], $q->due(10, '2026-09-13 12:00:00'));
        self::assertSame(1, $q->counts()['pending']);
        $q->markFailed($id, 'boom again', 8, null);
        self::assertSame(1, $q->counts()['dead']);
        self::assertSame('boom again', $q->recent(5)[0]['last_error']);
        $again = $q->push('hosting.activate', 7);
        self::assertNotSame($id, $again, 'dead jobs do not block a fresh push');
    }

    public function testNewEventForAJobInBackoffMakesItDueNowWithoutResettingAttempts(): void
    {
        $q = new Queue(fn (): string => $this->now);
        $id = $q->push('hosting.activate', 7);
        $q->markRunning($id);
        $q->markFailed($id, 'boom', 4, '2026-09-13 14:00:00');
        self::assertSame([], $q->due(10));

        self::assertSame($id, $q->push('hosting.activate', 7));

        $row = $q->recent(1)[0];
        self::assertSame(['2026-09-13 12:00:00', 4, 'pending'], [$row['next_run_at'], $row['attempts'], $row['status']]);
        self::assertSame([$id], array_column($q->due(10), 'id'));

        $this->now = '2026-09-13 13:00:00';
        $q->push('hosting.activate', 7);
        self::assertSame('2026-09-13 12:00:00', $q->recent(1)[0]['next_run_at'], 'an already due job is not moved');
    }

    public function testMarkDoneAndPurge(): void
    {
        $q = new Queue();
        $id = $q->push('domain.remove', 1);
        $q->markDone($id);
        self::assertSame(0, $q->counts()['pending']);
        self::assertSame('done', $q->recent(1)[0]['status']);
        $q->purgeDone(0);
        self::assertSame([], $q->recent(1));
    }

    public function testInjectedClockStampsEveryTimestampSoTestsDoNotDependOnTheWallClock(): void
    {
        $q = $this->queue();
        $id = $q->push('hosting.activate', 1);
        $row = $q->recent(1)[0];
        self::assertSame($this->now, $row['next_run_at']);
        self::assertSame($this->now, $row['created_at']);
        self::assertSame([$id], array_column($q->due(10), 'id'), 'due() defaults to the injected clock');
        self::assertSame([], $q->due(10, '2026-09-13 11:59:59'));
        self::assertSame(1, $q->due(10, '2026-09-13 12:00:00')[0]['ref_id']);

        $this->now = '2026-09-13 12:05:00';
        $q->markDone($id);
        self::assertSame('2026-09-13 12:05:00', $q->recent(1)[0]['finished_at']);

        $this->now = '2026-09-20 12:04:59';
        $q->purgeDone(7);
        self::assertCount(1, $q->recent(5), 'finished less than 7 days ago');
        $this->now = '2026-09-20 12:05:00';
        $q->purgeDone(7);
        self::assertSame([], $q->recent(5));
    }

    public function testRealClockPushIsDueNowAndNotInThePast(): void
    {
        $q = new Queue();
        $q->push('client.upsert', 5);
        self::assertCount(1, $q->due(10, '2999-01-01 00:00:00'));
        self::assertSame([], $q->due(10, '2000-01-01 00:00:00'));
    }

    public function testDueHonoursLimitStatusAndRowShape(): void
    {
        $q = $this->queue();
        $first = $q->push('hosting.activate', 1);
        $running = $q->push('hosting.activate', 2);
        $q->push('domain.upsert', 3);
        $q->markRunning($running);
        $due = $q->due(1);
        self::assertCount(1, $due);
        self::assertSame($first, $due[0]['id']);
        self::assertSame(0, $due[0]['attempts']);
        self::assertSame([1, 3], array_column($q->due(50), 'ref_id'), 'running jobs are not due');
        self::assertNotSame($running, $q->push('hosting.activate', 2), 'a running job does not swallow a new event');
        self::assertSame(['pending' => 3, 'running' => 1, 'dead' => 0], $q->counts());
    }

    public function testFailedJobStoresAttemptsNextRunAndTruncatedError(): void
    {
        $q = $this->queue();
        $id = $q->push('hosting.activate', 1);
        $q->markRunning($id);
        $q->markFailed($id, str_repeat('ü', 2500), 2, '2026-09-13 12:05:00');
        $row = $q->recent(1)[0];
        self::assertSame('pending', $row['status']);
        self::assertSame(2, $row['attempts']);
        self::assertSame('2026-09-13 12:05:00', $row['next_run_at']);
        self::assertSame(2000, mb_strlen((string) $row['last_error']));
        self::assertNull($row['finished_at']);
        $q->markDone($id);
        self::assertNull($q->recent(1)[0]['last_error']);
    }

    public function testStaleRunningJobsAreResetToPendingOrDead(): void
    {
        $q = $this->queue();
        $crashed = $q->push('hosting.activate', 1);
        $exhausted = $q->push('hosting.activate', 2);
        $waiting = $q->push('domain.upsert', 3);
        Capsule::table('mod_uptimeify_queue')->where('id', $exhausted)->update(['attempts' => 7]);
        $q->markRunning($crashed);
        $q->markRunning($exhausted);

        $this->now = '2026-09-13 12:14:59';
        $fresh = $this->queue();
        self::assertSame(['pending' => 0, 'dead' => 0], $fresh->resetStale(15, 8), 'running for less than 15 minutes');
        $this->now = '2026-09-13 12:15:00';
        self::assertSame(['pending' => 1, 'dead' => 1], $fresh->resetStale(15, 8));

        $rows = [];
        foreach ($q->recent(10) as $row) {
            $rows[$row['id']] = $row;
        }
        self::assertSame('pending', $rows[$crashed]['status']);
        self::assertSame(1, $rows[$crashed]['attempts']);
        self::assertNotNull($rows[$crashed]['last_error']);
        self::assertSame('dead', $rows[$exhausted]['status']);
        self::assertSame(8, $rows[$exhausted]['attempts']);
        self::assertSame('2026-09-13 12:15:00', $rows[$exhausted]['finished_at']);
        self::assertSame('pending', $rows[$waiting]['status']);
        self::assertSame(0, $rows[$waiting]['attempts']);
        self::assertSame([$crashed, $waiting], array_column($q->due(50), 'id'), 'a reset job is due at once');
        self::assertSame(['pending' => 2, 'running' => 0, 'dead' => 1], $q->counts());
    }

    private function queue(): Queue
    {
        return new Queue(fn (): string => $this->now);
    }

    public function testRetryDeadResetsOnlyADeadJob(): void
    {
        $q = new Queue(fn (): string => $this->now);
        $dead = $q->push('hosting.activate', 7);
        $q->markFailed($dead, 'boom', 8, null);
        $pending = $q->push('domain.upsert', 3);

        self::assertFalse($q->retryDead($pending), 'a pending job is not a dead job');
        self::assertFalse($q->retryDead(999));
        self::assertTrue($q->retryDead($dead));
        $row = (array) Capsule::table('mod_uptimeify_queue')->where('id', $dead)->first();
        self::assertSame(['pending', 0, $this->now, null, null], [$row['status'], (int) $row['attempts'], $row['next_run_at'], $row['last_error'], $row['finished_at']]);
        self::assertFalse($q->retryDead($dead), 'already pending again');
    }
}
