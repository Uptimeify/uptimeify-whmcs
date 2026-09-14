<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Uptimeify\Sync;

use WHMCS\Module\Addon\Uptimeify\Api\Client;
use WHMCS\Module\Addon\Uptimeify\Settings;
use WHMCS\Module\Addon\Uptimeify\Store\CustomerMap;
use WHMCS\Module\Addon\Uptimeify\Store\MonitorMap;
use WHMCS\Module\Addon\Uptimeify\Store\StatusCache;
use WHMCS\Module\Addon\Uptimeify\Whmcs\ClientRepository;
use WHMCS\Module\Addon\Uptimeify\Whmcs\HostingRepository;

/**
 * Runs one queue job (spec §7, §8.4) through the Reconciler's building blocks for one WHMCS object.
 *
 * The job name records which hook queued it; what happens follows the object's live WHMCS state, exactly like the
 * full reconcile (spec §8.5): a pause job for a hosting that is Active again by the time the cron runs changes
 * nothing, and jobs that arrive out of order cannot undo a newer state.
 *
 * - `hosting.*` (`ref_id` = tblhosting.id): the hosting's monitors plus the customer IP of its server for its client.
 * - `domain.*` (`ref_id` = tbldomains.id): the registrar domain's monitors.
 * - `client.upsert`, `client.pause_all` (ClientClose), `client.remove` (ClientDelete; `ref_id` = tblclients.id): every
 *   monitor of the client's customer. A Closed client keeps only paused websites and its other monitors; a deleted
 *   client's monitors end like Terminated, then its customer mapping and cached snapshot go (round 2, D6; the
 *   Uptimeify customer itself is never deleted).
 *
 * Not final: QueueWorkerTest mocks it.
 */
class JobHandler
{
    public const JOB_CLIENT_UPSERT = Reconciler::JOB_CLIENT_UPSERT;
    public const JOB_CLIENT_PAUSE_ALL = 'client.pause_all';
    /** Queued by the ClientDelete hook (Task 12); not named in spec §7, which only lists the §8.5 transition. */
    public const JOB_CLIENT_REMOVE = 'client.remove';
    public const JOB_HOSTING_ACTIVATE = Reconciler::JOB_HOSTING_ACTIVATE;
    public const JOB_HOSTING_PAUSE = 'hosting.pause';
    public const JOB_HOSTING_REMOVE = Reconciler::JOB_HOSTING_REMOVE;
    public const JOB_DOMAIN_UPSERT = Reconciler::JOB_DOMAIN_UPSERT;
    public const JOB_DOMAIN_REMOVE = Reconciler::JOB_DOMAIN_REMOVE;

    public const JOBS = [
        self::JOB_CLIENT_UPSERT,
        self::JOB_CLIENT_PAUSE_ALL,
        self::JOB_CLIENT_REMOVE,
        self::JOB_HOSTING_ACTIVATE,
        self::JOB_HOSTING_PAUSE,
        self::JOB_HOSTING_REMOVE,
        self::JOB_DOMAIN_UPSERT,
        self::JOB_DOMAIN_REMOVE,
    ];

    /**
     * `last_error` codes a retry cannot fix (spec §8.4, §5 setup errors): the job goes straight to dead.
     * `invalidResponse` retries. `packageOrgOnly` is left out: it only answers a package change, which the addon never
     * sends. `invalidUrl` retries too (round 2 review I1): the API's URL check resolves the host, so a
     * domain whose DNS is not set up yet gets the same code as a private target; the job backs off like any other failure.
     */
    public const FATAL_ERRORS = [
        ...Client::QUOTA_ERROR_CODES,
        Reconciler::ERROR_CUSTOMER_CONFLICT,
        Reconciler::ERROR_NO_DEFAULT_PACKAGE,
        Client::INVALID_PACKAGE_TYPE_CODE,
        Client::SCOPE_FORBIDDEN_CODE,
    ];

    private const REASONS = [
        Client::IP_LIMIT_CODE => 'IP monitor quota of the customer reached',
        Reconciler::ERROR_CUSTOMER_CONFLICT => 'the customer is mapped to several WHMCS clients',
        Reconciler::ERROR_NO_DEFAULT_PACKAGE => 'no default package configured for new customers',
        Client::INVALID_PACKAGE_TYPE_CODE => 'the default package does not exist in Uptimeify, choose it again',
        Client::SCOPE_FORBIDDEN_CODE => 'the API token is bound to a customer, an organization-wide token is needed',
    ];

    private const MAX_REASON_MESSAGES = 5;

    /** status_cache keys a deleted client leaves behind (client snapshot, client-area retry and conflict-log markers). */
    private const CLIENT_CACHE_KEYS = ['client:%d', 'client_retry:%d', 'client_conflict_log:%d'];

    public function __construct(
        private readonly Reconciler $reconciler,
        private readonly MonitorPlanner $planner,
        private readonly MonitorMap $map,
        private readonly ClientRepository $clients,
        private readonly HostingRepository $hostings,
        // @phpstan-ignore property.onlyWritten (constructor signature shared with Container, Task 12)
        private readonly Client $api,
        // @phpstan-ignore property.onlyWritten (constructor signature shared with Container, Task 12)
        private readonly RateLimitGuard $guard,
        // @phpstan-ignore property.onlyWritten (constructor signature shared with Container, Task 12)
        private readonly Settings $settings,
        private readonly CustomerMap $customerMap,
        private readonly StatusCache $cache = new StatusCache(),
    ) {
    }

    /**
     * @throws \InvalidArgumentException for a job name not in JOBS
     * @throws JobFailedException when a monitor ended in an error in this job (fatal: a retry cannot help)
     * @throws \WHMCS\Module\Addon\Uptimeify\Api\Exception\UnauthorizedException
     * @throws \WHMCS\Module\Addon\Uptimeify\Api\Exception\RateLimitedException after the guard's retries
     */
    public function handle(string $job, int $refId, RemoteIndex $index): ReconcileResult
    {
        if (!in_array($job, self::JOBS, true)) {
            throw new \InvalidArgumentException('unknown job ' . $job);
        }
        $result = new ReconcileResult();
        $object = strstr($job, '.', true);
        if ($object === 'hosting') {
            $this->hosting($refId, $index, $result);
        } elseif ($object === 'domain') {
            $this->domain($refId, $index, $result);
        } else {
            $this->client($refId, $index, $result);
        }
        $this->assertSucceeded($result);
        if ($job === self::JOB_CLIENT_REMOVE) {
            $this->forgetDeletedClient($refId, $result);
        }
        return $result;
    }

    /**
     * The hosting's rows plus the customer-IP rows of its client(s): whether an IP monitor is still wanted depends on
     * the other active hostings on that server, which the planner decides. That also covers ServiceDelete, when the
     * hosting row (and with it the server id) is gone.
     */
    private function hosting(int $hostingId, RemoteIndex $index, ReconcileResult $result): void
    {
        $hosting = $this->hostings->find($hostingId);
        $rows = $this->map->forKindRef(MonitorMap::KIND_HOSTING, $hostingId);
        $clientIds = [];
        $customers = [];
        $ipRefIds = [];
        if ($hosting !== null) {
            $clientIds[] = $hosting->clientId;
            $customers[] = $this->customerMap->get($hosting->clientId)['customer_public_id'] ?? null;
            if ($hosting->serverId > 0) {
                $ipRefIds[MonitorPlanner::serverIpRefId($hosting->serverId, $hosting->clientId)] = true;
            }
        }
        foreach ($rows as $row) {
            $customers[] = $row['customer_public_id'];
        }
        foreach (array_unique(array_filter($customers, static fn (mixed $c): bool => is_string($c) && $c !== '')) as $customer) {
            foreach ($this->map->forCustomer($customer) as $row) {
                if ($row['kind'] === MonitorMap::KIND_SERVER_IP) {
                    $rows[] = $row;
                    $ipRefIds[(int) $row['whmcs_ref_id']] = true;
                }
            }
        }
        $belongs = static fn (PlannedMonitor $p): bool => ($p->kind === MonitorMap::KIND_HOSTING && $p->refId === $hostingId)
            || ($p->kind === MonitorMap::KIND_SERVER_IP && isset($ipRefIds[$p->refId]));
        $this->reconciler->reconcileObject($clientIds, $belongs, $rows, $index, $result);
    }

    private function domain(int $domainId, RemoteIndex $index, ReconcileResult $result): void
    {
        // An Active domain names its client through its plans; an ended or deleted one through its rows' customer.
        $clientIds = array_map(static fn (PlannedMonitor $p): int => $p->clientId, $this->planner->planForDomain($domainId));
        $belongs = static fn (PlannedMonitor $p): bool => $p->kind === MonitorMap::KIND_DOMAIN && $p->refId === $domainId;
        $this->reconciler->reconcileObject($clientIds, $belongs, $this->map->forKindRef(MonitorMap::KIND_DOMAIN, $domainId), $index, $result);
    }

    private function client(int $clientId, RemoteIndex $index, ReconcileResult $result): void
    {
        $mapping = $this->customerMap->get($clientId);
        $rows = $mapping === null ? [] : $this->map->forCustomer($mapping['customer_public_id']);
        $belongs = static fn (PlannedMonitor $p): bool => $p->clientId === $clientId;
        $this->reconciler->reconcileObject([$clientId], $belongs, $rows, $index, $result);
    }

    /**
     * After the monitors of a deleted client were handled without error (round 2, D6): its customer_map row and its
     * status_cache entries go, so the Uptimeify customer is no longer reserved for a client id that no longer exists.
     * Only when WHMCS really has no such client (a stale job for a client that exists keeps everything). The remote
     * customer stays: deleting it is the hoster's decision in Uptimeify.
     */
    private function forgetDeletedClient(int $clientId, ReconcileResult $result): void
    {
        if ($this->clients->find($clientId) !== null) {
            return;
        }
        $mapping = $this->customerMap->get($clientId);
        $this->customerMap->remove($clientId);
        foreach (self::CLIENT_CACHE_KEYS as $key) {
            $this->cache->delete(sprintf($key, $clientId));
        }
        if ($mapping !== null) {
            $result->messages[] = sprintf('WHMCS client #%d deleted: mapping to customer %s removed, the customer stays in Uptimeify', $clientId, $mapping['customer_public_id']);
        }
    }

    /**
     * Only errors this job produced count (`ReconcileResult::rowErrors`), never a stale `last_error` of a row the job
     * did not handle, nor a known `invalidUrl` or quota error of a row the job did not send again (a refused target or a
     * quota hit already seen against this load). A fatal code makes the job fatal (checked first: retrying would only repeat it; the daily
     * reconcile still retries the rest). Any other error fails the job for a retry (T11-2).
     */
    private function assertSucceeded(ReconcileResult $result): void
    {
        $fatal = [];
        foreach ($result->rowErrors as $key => $code) {
            if (in_array($code, self::FATAL_ERRORS, true)) {
                [$kind, $refId, $type] = explode(':', $key, 3);
                $target = $this->map->find($kind, (int) $refId, $type)['target'] ?? '';
                $fatal[] = trim(sprintf('%s %s', $key, $target)) . sprintf(': %s (%s)', self::reason($code), $code);
            }
        }
        if ($fatal !== []) {
            throw new JobFailedException(implode('; ', $fatal), true, $result);
        }
        if ($result->errors > 0 || $result->rowErrors !== []) {
            $messages = array_slice($result->messages, 0, self::MAX_REASON_MESSAGES);
            throw new JobFailedException($messages === [] ? $result->summary() : implode('; ', $messages), false, $result);
        }
    }

    private static function reason(string $code): string
    {
        return self::REASONS[$code] ?? 'monitor quota of the customer reached';
    }
}
