<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Uptimeify\Sync;

use WHMCS\Module\Addon\Uptimeify\Api\Client;
use WHMCS\Module\Addon\Uptimeify\Api\Exception\ApiException;
use WHMCS\Module\Addon\Uptimeify\Api\Exception\RateLimitedException;
use WHMCS\Module\Addon\Uptimeify\Api\Exception\UnauthorizedException;
use WHMCS\Module\Addon\Uptimeify\Bridge;
use WHMCS\Module\Addon\Uptimeify\Settings;
use WHMCS\Module\Addon\Uptimeify\Store\Queue;
use WHMCS\Module\Addon\Uptimeify\Store\SyncLock;

/**
 * Drains the queue from the 5-minute cron (spec §8.4): under the sync lock shared with the reconcile, up to 50 due
 * jobs oldest first, one RemoteIndex for the batch, one status-cache refresh at the end.
 *
 * - A failed job backs off 1 / 5 / 30 / 120 / 120 … minutes and is dead after 8 attempts; a fatal one (quota,
 *   configuration) is dead at once with its plain-text reason. `invalidUrl` backs off like any other failure (a domain
 *   that does not resolve yet gets it too).
 * - 401 resets `connection_validated` and stops the run; an exhausted 429 stops the run. Neither costs the job an
 *   attempt (it is due again at once), and neither run refreshes the status cache.
 * - Every job writes one module log entry with the token masked.
 * - Jobs left `running` by a worker that died are reset at the start (T11-3).
 * - When jobs are due, the organization binding is checked once first (round 2, D9); a token of another organization
 *   stops the run and every job waits.
 */
final class QueueWorker
{
    public const DEFAULT_LIMIT = 50;
    public const MAX_ATTEMPTS = 8;
    /** Backoff after attempt n is BACKOFF_MINUTES[n-1], the last value for every later attempt. */
    public const BACKOFF_MINUTES = [1, 5, 30, 120];
    public const STALE_RUNNING_MINUTES = 15;
    public const PURGE_DONE_DAYS = 7;

    /** @var \Closure():string */
    private \Closure $now;

    /**
     * @param (callable():string)|null $now UTC 'Y-m-d H:i:s'; pass the Queue's clock
     */
    public function __construct(
        private readonly Queue $queue,
        private readonly JobHandler $handler,
        private readonly Client $api,
        private readonly RateLimitGuard $guard,
        private readonly SyncLock $lock,
        private readonly Reconciler $reconciler,
        private readonly Settings $settings,
        ?callable $now = null,
    ) {
        $this->now = $now === null ? static fn (): string => gmdate('Y-m-d H:i:s') : \Closure::fromCallable($now);
    }

    /** @return array{processed:int,failed:int,dead:int} processed = done */
    public function run(int $limit = self::DEFAULT_LIMIT): array
    {
        $stats = ['processed' => 0, 'failed' => 0, 'dead' => 0];
        if (!$this->settings->isConnected() || !$this->settings->autoSync()) {
            return $stats;
        }
        if (!$this->lock->acquire()) {
            return $stats;
        }
        try {
            $this->queue->resetStale(self::STALE_RUNNING_MINUTES, self::MAX_ATTEMPTS);
            $jobs = $this->queue->due($limit, ($this->now)());
            if ($jobs === []) {
                return $stats;
            }
            try {
                // Round 2, D9: once per run with due jobs, before anything else.
                (new OrganizationBinding($this->settings, $this->api, $this->guard))->verify();
                $index = RemoteIndex::load($this->api, $this->guard, Reconciler::ALL_TYPES);
            } catch (OrganizationMismatchException $e) {
                Bridge::logActivity('Queue worker stopped: ' . $e->getMessage() . '; the jobs wait');
                return $stats;
            } catch (UnauthorizedException $e) {
                $this->unauthorized($e);
                return $stats;
            } catch (ApiException $e) {
                Bridge::logActivity('Queue worker stopped: could not load the remote state: ' . $e->getMessage());
                return $stats;
            }

            $handled = false;
            foreach ($jobs as $job) {
                if (!$this->process($job, $index, $stats, $handled)) {
                    break;
                }
            }
            if ($handled) {
                try {
                    $this->reconciler->refreshCache($index);
                } catch (UnauthorizedException $e) {
                    $this->unauthorized($e);
                }
            }
            $this->queue->purgeDone(self::PURGE_DONE_DAYS);
        } finally {
            $this->lock->release();
        }
        return $stats;
    }

    /**
     * @param array<string,mixed> $job
     * @param array{processed:int,failed:int,dead:int} $stats
     * @param bool $handled set once the handler ran (the status cache is then refreshed), cleared again by a 401 or 429
     * @return bool false when the run must stop
     */
    private function process(array $job, RemoteIndex $index, array &$stats, bool &$handled): bool
    {
        $id = (int) $job['id'];
        $name = (string) $job['job'];
        $refId = (int) $job['ref_id'];
        $attempts = (int) $job['attempts'];
        $request = ['job' => $name, 'ref_id' => $refId, 'attempt' => $attempts + 1];

        if (!in_array($name, JobHandler::JOBS, true)) {
            $reason = 'unknown job ' . $name;
            $this->queue->markFailed($id, $reason, $attempts + 1, null);
            $stats['dead']++;
            $this->log($name, $request, ['status' => Queue::STATUS_DEAD, 'error' => $reason]);
            return true;
        }

        $handled = true;
        $this->queue->markRunning($id);
        try {
            $result = $this->handler->handle($name, $refId, $index);
        } catch (UnauthorizedException $e) {
            $this->queue->markFailed($id, $e->getMessage(), $attempts, ($this->now)());
            $handled = false; // no cache refresh: the API rejects the token
            $this->log($name, $request, ['status' => Queue::STATUS_PENDING, 'error' => $e->getMessage()]);
            $this->unauthorized($e);
            return false;
        } catch (RateLimitedException $e) {
            $this->queue->markFailed($id, $e->getMessage(), $attempts, ($this->now)());
            $handled = false; // no cache refresh: its incident walk would hit the limit and sleep under the lock
            $this->log($name, $request, ['status' => Queue::STATUS_PENDING, 'error' => $e->getMessage()]);
            Bridge::logActivity('Queue worker stopped: rate limited, the rest runs in the next cron');
            return false;
        } catch (JobFailedException $e) {
            $this->fail($job, $e->getMessage(), $e->fatal, $stats, $request, $e->result->summary());
            return true;
        } catch (\Throwable $e) {
            $this->fail($job, $e->getMessage(), false, $stats, $request, null);
            return true;
        }
        $this->queue->markDone($id);
        $stats['processed']++;
        $this->log($name, $request, ['status' => Queue::STATUS_DONE, 'summary' => $result->summary(), 'messages' => $result->messages]);
        return true;
    }

    /**
     * @param array<string,mixed> $job
     * @param array{processed:int,failed:int,dead:int} $stats
     * @param array<string,mixed> $request
     */
    private function fail(array $job, string $reason, bool $fatal, array &$stats, array $request, ?string $summary): void
    {
        $attempts = (int) $job['attempts'] + 1;
        $reason = $reason !== '' ? $reason : 'job failed';
        if ($fatal || $attempts >= self::MAX_ATTEMPTS) {
            $this->queue->markFailed((int) $job['id'], $reason, $attempts, null);
            $stats['dead']++;
            $status = Queue::STATUS_DEAD;
            Bridge::logActivity(sprintf('Queue job %s #%d is dead after %d attempt(s): %s', $job['job'], $job['ref_id'], $attempts, $reason));
        } else {
            $minutes = self::BACKOFF_MINUTES[min($attempts, count(self::BACKOFF_MINUTES)) - 1];
            $this->queue->markFailed((int) $job['id'], $reason, $attempts, gmdate('Y-m-d H:i:s', self::timestamp(($this->now)()) + $minutes * 60));
            $stats['failed']++;
            $status = Queue::STATUS_PENDING;
        }
        $this->log((string) $job['job'], $request, array_filter(['status' => $status, 'error' => $reason, 'summary' => $summary], static fn (mixed $v): bool => $v !== null));
    }

    private function unauthorized(UnauthorizedException $e): void
    {
        $this->settings->setBool('connection_validated', false);
        Bridge::logActivity('Queue worker stopped: the API token was rejected, connection reset: ' . $e->getMessage());
    }

    /**
     * @param array<string,mixed> $request
     * @param array<string,mixed> $response
     */
    private function log(string $job, array $request, array $response): void
    {
        Bridge::logModuleCall($job, $request, $response, $this->tokenMask());
    }

    /** @return list<string> */
    private function tokenMask(): array
    {
        try {
            $token = $this->settings->getApiToken();
        } catch (\RuntimeException) {
            return [];
        }
        return $token === null || $token === '' ? [] : [$token];
    }

    private static function timestamp(string $utc): int
    {
        $ts = strtotime($utc . ' UTC');
        if ($ts === false) {
            throw new \UnexpectedValueException(sprintf('Queue worker clock returned an invalid time "%s"', $utc));
        }
        return $ts;
    }
}
