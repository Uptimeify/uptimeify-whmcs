<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Uptimeify\Store;

use WHMCS\Database\Capsule;

/**
 * Job queue written by hooks and drained by the queue worker (spec §6, §8.4).
 *
 * Every timestamp the queue writes or compares against comes from one clock, injectable for tests, so a test
 * that pins "now" gets matching `next_run_at`, `created_at` and `finished_at` values. The retry policy (backoff,
 * attempt limit) belongs to the worker; the queue only stores what it is told.
 *
 * While a job is `running`, `next_run_at` holds the time it was picked up; resetStale() uses that to find jobs
 * whose worker died (PHP fatal, timeout).
 */
final class Queue
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_DONE = 'done';
    public const STATUS_DEAD = 'dead';

    private const TABLE = 'mod_uptimeify_queue';

    private const STALE_ERROR = 'worker did not finish the job (stale running job reset)';

    /** @var \Closure():string */
    private \Closure $clock;

    /** @param (callable():string)|null $clock returns UTC 'Y-m-d H:i:s'; defaults to the wall clock */
    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock === null ? static fn (): string => Schema::now() : \Closure::fromCallable($clock);
    }

    public function isPending(string $job, int $refId): bool
    {
        return Capsule::table(self::TABLE)->where('job', $job)->where('ref_id', $refId)->where('status', self::STATUS_PENDING)->exists();
    }

    /**
     * Returns the id; a pending job with the same job + ref id is reused, not duplicated. A new event for a job waiting
     * in backoff makes it due now (attempts unchanged), so the event is not held back for up to two hours.
     */
    public function push(string $job, int $refId): int
    {
        $now = $this->now();
        $existing = Capsule::table(self::TABLE)->where('job', $job)->where('ref_id', $refId)->where('status', self::STATUS_PENDING)
            ->orderBy('id')->value('id');
        if ($existing !== null) {
            Capsule::table(self::TABLE)->where('id', $existing)->where('next_run_at', '>', $now)->update(['next_run_at' => $now]);
            return (int) $existing;
        }
        return (int) Capsule::table(self::TABLE)->insertGetId([
            'job' => $job, 'ref_id' => $refId, 'attempts' => 0, 'status' => self::STATUS_PENDING,
            'next_run_at' => $now, 'created_at' => $now,
        ]);
    }

    /**
     * Pending jobs with next_run_at <= $now (default: the queue clock), oldest first.
     *
     * @return list<array<string,mixed>>
     */
    public function due(int $limit, ?string $now = null): array
    {
        $rows = Capsule::table(self::TABLE)->where('status', self::STATUS_PENDING)->where('next_run_at', '<=', $now ?? $this->now())
            ->orderBy('id')->limit($limit)->get()->all();
        return $this->rows($rows);
    }

    public function markRunning(int $id): void
    {
        Capsule::table(self::TABLE)->where('id', $id)->update(['status' => self::STATUS_RUNNING, 'next_run_at' => $this->now()]);
    }

    public function markDone(int $id): void
    {
        Capsule::table(self::TABLE)->where('id', $id)->update(['status' => self::STATUS_DONE, 'last_error' => null, 'finished_at' => $this->now()]);
    }

    /** $nextRunAt === null marks the job dead; otherwise it goes back to pending at $nextRunAt. */
    public function markFailed(int $id, string $error, int $attempts, ?string $nextRunAt): void
    {
        $data = ['attempts' => $attempts, 'last_error' => mb_substr($error, 0, 2000)];
        if ($nextRunAt === null) {
            $data += ['status' => self::STATUS_DEAD, 'finished_at' => $this->now()];
        } else {
            $data += ['status' => self::STATUS_PENDING, 'next_run_at' => $nextRunAt];
        }
        Capsule::table(self::TABLE)->where('id', $id)->update($data);
    }

    /**
     * Jobs `running` for at least $olderThanMinutes lost their worker. Each counts as a failed attempt: it goes
     * back to pending (due at once), or to dead once it has used $maxAttempts. Call it under the sync lock.
     *
     * @return array{pending:int,dead:int}
     */
    public function resetStale(int $olderThanMinutes, int $maxAttempts): array
    {
        $now = $this->now();
        $cutoff = gmdate('Y-m-d H:i:s', $this->timestamp($now) - $olderThanMinutes * 60);
        $out = ['pending' => 0, 'dead' => 0];
        $stale = Capsule::table(self::TABLE)->where('status', self::STATUS_RUNNING)->where('next_run_at', '<=', $cutoff)->orderBy('id')->get();
        foreach ($stale as $row) {
            $attempts = (int) $row->attempts + 1;
            $this->markFailed((int) $row->id, self::STALE_ERROR, $attempts, $attempts >= $maxAttempts ? null : (string) $row->next_run_at);
            $out[$attempts >= $maxAttempts ? 'dead' : 'pending']++;
        }
        return $out;
    }

    /** A dead job goes back to pending, due now, with its attempts reset (admin "retry"). False when $id is no dead job. */
    public function retryDead(int $id): bool
    {
        return Capsule::table(self::TABLE)->where('id', $id)->where('status', self::STATUS_DEAD)->update([
            'status' => self::STATUS_PENDING, 'attempts' => 0, 'next_run_at' => $this->now(), 'last_error' => null, 'finished_at' => null,
        ]) > 0;
    }

    /** @return array{pending:int,running:int,dead:int} */
    public function counts(): array
    {
        $out = ['pending' => 0, 'running' => 0, 'dead' => 0];
        $rows = Capsule::table(self::TABLE)->select('status')->selectRaw('COUNT(*) AS n')->whereIn('status', array_keys($out))->groupBy('status')->get();
        foreach ($rows as $row) {
            $r = (array) $row;
            $out[(string) $r['status']] = (int) $r['n'];
        }
        return $out;
    }

    /** @return list<array<string,mixed>> newest first */
    public function recent(int $limit): array
    {
        return $this->rows(Capsule::table(self::TABLE)->orderByDesc('id')->limit($limit)->get()->all());
    }

    public function purgeDone(int $olderThanDays): void
    {
        $cutoff = gmdate('Y-m-d H:i:s', $this->timestamp($this->now()) - $olderThanDays * 86400);
        Capsule::table(self::TABLE)->where('status', self::STATUS_DONE)->where('finished_at', '<=', $cutoff)->delete();
    }

    private function now(): string
    {
        return ($this->clock)();
    }

    private function timestamp(string $utc): int
    {
        $ts = strtotime($utc . ' UTC');
        if ($ts === false) {
            throw new \UnexpectedValueException(sprintf('Queue clock returned an invalid time "%s"', $utc));
        }
        return $ts;
    }

    /**
     * @param array<int,object> $rows
     * @return list<array<string,mixed>>
     */
    private function rows(array $rows): array
    {
        return array_values(array_map(static function (object $r): array {
            $row = (array) $r;
            $row['id'] = (int) $row['id'];
            $row['ref_id'] = (int) $row['ref_id'];
            $row['attempts'] = (int) $row['attempts'];
            return $row;
        }, $rows));
    }
}
