<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Uptimeify\Admin;

use WHMCS\Module\Addon\Uptimeify\Api\Client;
use WHMCS\Module\Addon\Uptimeify\Api\Exception\ApiException;
use WHMCS\Module\Addon\Uptimeify\Api\Exception\NotFoundException;
use WHMCS\Module\Addon\Uptimeify\Api\Exception\RateLimitedException;
use WHMCS\Module\Addon\Uptimeify\Api\Exception\UnauthorizedException;
use WHMCS\Module\Addon\Uptimeify\Container;
use WHMCS\Module\Addon\Uptimeify\Store\DetachedMonitors;
use WHMCS\Module\Addon\Uptimeify\Store\MonitorMap;
use WHMCS\Module\Addon\Uptimeify\Store\SyncLock;
use WHMCS\Module\Addon\Uptimeify\Sync\JobFailedException;
use WHMCS\Module\Addon\Uptimeify\Sync\JobHandler;
use WHMCS\Module\Addon\Uptimeify\Sync\MonitorPlanner;
use WHMCS\Module\Addon\Uptimeify\Sync\OrganizationMismatchException;
use WHMCS\Module\Addon\Uptimeify\Sync\Reconciler;
use WHMCS\Module\Addon\Uptimeify\Sync\ReconcileResult;
use WHMCS\Module\Addon\Uptimeify\Sync\RemoteIndex;
use WHMCS\Module\Addon\Uptimeify\Sync\TargetNormalizer;
use WHMCS\Module\Addon\Uptimeify\Whmcs\ClientRepository;

/**
 * Dashboard tab (spec §9.1): the desired monitors (planner) merged with the known ones (monitor_map), and the actions
 * on them.
 *
 * Synchronous actions run with a 20 s budget and an interactive rate-limit guard (no Retry-After sleep in the request):
 * what is left when the budget is used up or the API answers 429 is pushed as queue jobs ("Rest läuft im Cron
 * weiter"). Every action that calls the API takes the sync lock itself (never nested: "Jetzt abgleichen" leaves it to
 * the Reconciler), and every key or target posted must exist in the plan or the map (spec §10). Runs that write or read
 * lists check the organization binding first (round 2, D9); single-call actions are refused by the Dispatcher while a
 * mismatch is recorded. No admin request walks the incident history (round 2, D2).
 */
final class DashboardController
{
    public const SYNC_BUDGET_SECONDS = 20.0;

    /**
     * Display states of the table rows; `quota` is a map row in `error` with a quota code, `paused_admin` a paused row under
     * the admin's persistent pause (round 2, D7). An ignored target has no rows (its monitors are detached); the page lists
     * it from `ignored_targets` as "ignored".
     */
    public const STATES = ['not_monitored', 'no_customer', 'active', 'paused', 'paused_admin', 'error', 'quota', 'orphaned'];

    /** Rows rendered at most; the search narrows the rest. */
    private const MAX_ROWS = 500;

    /** @var \Closure():float */
    private \Closure $clock;

    /** @param (\Closure():float)|null $clock seconds, default microtime(true) */
    public function __construct(?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    /**
     * @param array<string,mixed> $ctx
     * @param array<string,mixed> $get
     */
    public function show(array $ctx, array $get): string
    {
        $rows = $this->rows($get);
        $cache = Container::statusCache();
        $vars = $ctx + [
            'rows' => array_slice($rows, 0, self::MAX_ROWS),
            'totalRows' => count($rows),
            'q' => self::string($get['q'] ?? ''),
            'stateFilter' => self::string($get['state'] ?? ''),
            'states' => self::STATES,
            'queueCounts' => Container::queue()->counts(),
            'lastReconcile' => $cache->value('last_reconcile'),
            'org' => $cache->value('org'),
            'ignoredTargets' => Container::settings()->ignoredTargets(),
            'customerOptions' => CustomersController::customerOptions(),
        ];
        return View::render('layout', $vars + ['content' => View::render('dashboard', $vars)]);
    }

    /**
     * One row per (kind, refId, monitorType) of the plan and the map, sorted by target and type, filtered by `q`
     * (substring of target or client name) and `state`.
     *
     * @param array<string,mixed> $get
     * @return list<array{key:string,kind:string,refId:int,monitorType:string,target:string,clientId:int,clientName:string,customerPublicId:?string,state:string,lastError:?string,publicId:?string,liveStatus:?string,planned:bool}>
     */
    public function rows(array $get): array
    {
        $clients = (new ClientRepository())->all();
        $customerMap = Container::customerMap()->all();
        $clientByCustomer = [];
        foreach ($customerMap as $clientId => $mapping) {
            $clientByCustomer[$mapping['customer_public_id']] ??= $clientId;
        }
        $autoCreate = Container::settings()->autoCreateCustomers();
        $rows = [];
        foreach (Container::planner()->planAll() as $plan) {
            $mapped = isset($customerMap[$plan->clientId]);
            $rows[$plan->key()] = [
                'key' => $plan->key(),
                'kind' => $plan->kind,
                'refId' => $plan->refId,
                'monitorType' => $plan->monitorType,
                'target' => $plan->target,
                'clientId' => $plan->clientId,
                'clientName' => self::clientName($clients, $plan->clientId),
                'customerPublicId' => $customerMap[$plan->clientId]['customer_public_id'] ?? null,
                'state' => !$mapped && !$autoCreate ? 'no_customer' : 'not_monitored',
                'lastError' => null,
                'publicId' => null,
                'liveStatus' => null,
                'planned' => true,
            ];
        }
        $snapshots = [];
        foreach (Container::monitorMap()->all() as $map) {
            $key = $map['kind'] . ':' . $map['whmcs_ref_id'] . ':' . $map['monitor_type'];
            $customer = is_string($map['customer_public_id']) ? $map['customer_public_id'] : null;
            $row = $rows[$key] ?? null;
            if ($row === null) {
                $clientId = $map['kind'] === MonitorMap::KIND_SERVER_IP
                    ? MonitorPlanner::decodeServerIpRefId((int) $map['whmcs_ref_id'])['clientId']
                    : ($customer === null ? 0 : ($clientByCustomer[$customer] ?? 0));
                $row = [
                    'key' => $key,
                    'kind' => (string) $map['kind'],
                    'refId' => (int) $map['whmcs_ref_id'],
                    'monitorType' => (string) $map['monitor_type'],
                    'target' => (string) $map['target'],
                    'clientId' => $clientId,
                    'clientName' => self::clientName($clients, $clientId),
                    'customerPublicId' => $customer,
                    'planned' => false,
                ];
            }
            $state = (string) $map['state'];
            $error = is_string($map['last_error']) ? $map['last_error'] : null;
            $row['state'] = match (true) {
                $state === MonitorMap::STATE_ERROR && in_array($error, Client::QUOTA_ERROR_CODES, true) => 'quota',
                $state === MonitorMap::STATE_PAUSED && $map['admin_paused'] === true => 'paused_admin',
                default => $state,
            };
            $row['lastError'] = $error;
            $row['publicId'] = is_string($map['monitor_public_id']) ? $map['monitor_public_id'] : null;
            $row['customerPublicId'] = $customer ?? $row['customerPublicId'];
            $row['liveStatus'] = null;
            if ($row['publicId'] !== null && $row['clientId'] > 0) {
                $snapshots[$row['clientId']] ??= Container::statusCache()->value('client:' . $row['clientId']) ?? [];
                foreach (is_array($snapshots[$row['clientId']]['monitors'] ?? null) ? $snapshots[$row['clientId']]['monitors'] : [] as $monitor) {
                    if (is_array($monitor) && ($monitor['publicId'] ?? null) === $row['publicId'] && is_string($monitor['status'] ?? null)) {
                        $row['liveStatus'] = $monitor['status'];
                    }
                }
            }
            $rows[$key] = $row;
        }

        $q = mb_strtolower(self::string($get['q'] ?? ''), 'UTF-8');
        $state = self::string($get['state'] ?? '');
        $rows = array_values(array_filter($rows, static fn (array $r): bool => ($q === '' || str_contains($r['target'], $q) || str_contains(mb_strtolower($r['clientName'], 'UTF-8'), $q))
            && ($state === '' || $r['state'] === $state)));
        usort($rows, static fn (array $a, array $b): int => [$a['target'], $a['monitorType'], $a['key']] <=> [$b['target'], $b['monitorType'], $b['key']]);
        return $rows;
    }

    /** "Jetzt abgleichen" (spec §8.3): the full reconcile with the 20 s budget; the Reconciler takes the lock. */
    public function reconcile(): void
    {
        $result = Container::reconciler(true)->run(self::SYNC_BUDGET_SECONDS);
        if (!$result->aborted) {
            Flash::set('success', View::t('dashboard.sync_done', ['summary' => $result->summary()]));
            return;
        }
        match ($result->abortReason) {
            Reconciler::ABORT_TIME_BUDGET => Flash::set('warning', View::t('dashboard.sync_partial', ['summary' => $result->summary(), 'queued' => $result->queued])),
            Reconciler::ABORT_LOCKED => Flash::set('error', View::t('error.sync_locked')),
            Reconciler::ABORT_UNAUTHORIZED => Flash::set('error', View::t('error.unauthorized')), // the Reconciler reset the connection
            Reconciler::ABORT_RATE_LIMITED => Flash::set('error', View::t('error.rate_limited')),
            Reconciler::ABORT_ORGANIZATION => Flash::set('error', View::t('error.organization_mismatch')),
            default => Flash::set('error', View::t('dashboard.sync_failed', ['message' => implode('; ', array_slice($result->messages, 0, 5))])),
        };
    }

    /**
     * "Status aktualisieren" (round 2, D8): a read-only refresh of the status cache. Under the sync lock, after the
     * organization check: GET lists only (customers and the monitor types in the map), no incident walk (D2: the stored
     * open incidents stay until the next worker run that handled jobs, the daily reconcile or a client-area refresh after
     * its TTL), nothing reconciled, no queue job. The 20 s budget is checked before every
     * list; when it runs out nothing is written (a partial index would show monitors as unknown). A 429 never sleeps.
     */
    public function refreshStatus(): void
    {
        $lock = new SyncLock();
        if (!$lock->acquire()) {
            Flash::set('error', View::t('error.sync_locked'));
            return;
        }
        try {
            $started = ($this->clock)();
            if (!$this->organizationVerified()) {
                return;
            }
            $types = [];
            foreach (Container::monitorMap()->all() as $row) {
                $types[(string) $row['monitor_type']] = true;
            }
            $index = RemoteIndex::loadWithin(
                Container::apiOrFail(),
                Container::guard(true),
                array_values(array_filter(Reconciler::ALL_TYPES, static fn (string $type): bool => isset($types[$type]))),
                fn (): bool => ($this->clock)() - $started <= self::SYNC_BUDGET_SECONDS,
            );
            if ($index === null) {
                Flash::set('warning', View::t('dashboard.status_refresh_timeout'));
                return;
            }
            Container::reconciler(true)->refreshCache($index, null, false);
            $org = Container::statusCache()->value('org') ?? [];
            Flash::set('success', View::t('dashboard.status_refreshed', ['total' => is_int($org['total'] ?? null) ? $org['total'] : 0]));
        } catch (RateLimitedException) {
            Flash::set('error', View::t('error.rate_limited'));
        } finally {
            $lock->release();
        }
    }

    /**
     * "Ausgewählte synchronisieren" / "Aktivieren": each object's queue job run inline, under the lock, with one
     * RemoteIndex; after the budget or a 429 the remaining jobs are queued.
     *
     * @param list<string> $keys "kind:refId:type"
     */
    public function syncSelected(array $keys): void
    {
        $jobs = $this->jobsFor($keys);
        if ($jobs === []) {
            Flash::set('error', View::t('dashboard.nothing_selected'));
            return;
        }
        $lock = new SyncLock();
        if (!$lock->acquire()) {
            Flash::set('error', View::t('error.sync_locked'));
            return;
        }
        try {
            $started = ($this->clock)();
            // Round 2, D9: jobs write remotely, so the organization is checked first.
            if (!$this->organizationVerified()) {
                return;
            }
            $handler = Container::jobHandler(true);
            try {
                $index = RemoteIndex::load(Container::apiOrFail(), Container::guard(true), Reconciler::ALL_TYPES);
            } catch (RateLimitedException) {
                $this->queueJobs($jobs, 0, count($jobs), 0);
                return;
            }
            $done = 0;
            $errors = [];
            foreach ($jobs as $i => [$job, $refId]) {
                if (($this->clock)() - $started > self::SYNC_BUDGET_SECONDS) {
                    $this->queueJobs($jobs, $i, count($jobs), $done);
                    $this->refreshCache($index);
                    return;
                }
                try {
                    $handler->handle($job, $refId, $index);
                    $done++;
                } catch (RateLimitedException) {
                    $this->queueJobs($jobs, $i, count($jobs), $done);
                    return;
                } catch (JobFailedException $e) {
                    $errors[] = $e->getMessage();
                }
            }
            $this->refreshCache($index);
            Flash::set($errors === [] ? 'success' : 'error', $errors === []
                ? View::t('dashboard.selected_done', ['done' => $done, 'count' => count($jobs)])
                : View::t('dashboard.selected_errors', ['done' => $done, 'count' => count($jobs), 'errors' => implode('; ', array_slice($errors, 0, 5))]));
        } finally {
            $lock->release();
        }
    }

    /**
     * "Pausieren" for a mapped website or customer IP (round 2, D7): pauses it remotely and sets the persistent
     * `admin_paused` on every row sharing the monitor, so no reconcile or job resumes it until "Aktivieren".
     */
    public function pause(string $key): void
    {
        $row = $this->mapRow($key);
        $publicId = is_string($row['monitor_public_id'] ?? null) ? $row['monitor_public_id'] : '';
        if ($row === null || !in_array($row['monitor_type'], Reconciler::STATUS_TYPES, true) || $publicId === '') {
            throw new \InvalidArgumentException(View::t('error.unknown_row'));
        }
        $type = (string) $row['monitor_type'];
        $this->locked(function () use ($row, $publicId, $type): void {
            $map = Container::monitorMap();
            // Several rows can share one monitor (two hostings on one host): the remote state is theirs together.
            $shared = array_filter($map->forMonitorPublicId($publicId), static fn (array $r): bool => $r['monitor_type'] === $type);
            try {
                Container::guard(true)->run(fn (): array => $type === MonitorMap::TYPE_WEBSITE
                    ? Container::apiOrFail()->setWebsiteStatus($publicId, 'inactive')
                    : Container::apiOrFail()->setCustomerIpStatus($publicId, 'disabled'));
            } catch (NotFoundException) {
                foreach ($shared as $r) {
                    $map->setState((string) $r['kind'], (int) $r['whmcs_ref_id'], $type, MonitorMap::STATE_ORPHANED, Reconciler::ERROR_REMOTE_MISSING);
                }
                Flash::set('warning', View::t('dashboard.pause_orphaned', ['target' => (string) $row['target']]));
                return;
            }
            $map->setAdminPaused($type, $publicId, true);
            foreach ($shared as $r) {
                $map->setState((string) $r['kind'], (int) $r['whmcs_ref_id'], $type, MonitorMap::STATE_PAUSED);
            }
            Flash::set('success', View::t('dashboard.paused', ['target' => (string) $row['target']]));
        });
    }

    /**
     * "Aktivieren": clears the admin's persistent pause of the row's monitor (every row sharing it, and the pause a detached
     * record keeps for that monitor), then runs the jobs of those rows' objects like "Ausgewählte synchronisieren", which
     * resume the monitor only when the WHMCS state wants it.
     */
    public function enable(string $key): void
    {
        $row = $this->mapRow($key);
        $publicId = is_string($row['monitor_public_id'] ?? null) ? $row['monitor_public_id'] : '';
        $keys = [$key];
        $type = (string) ($row['monitor_type'] ?? '');
        $detached = new DetachedMonitors();
        if ($row !== null && $publicId !== '' && ($row['admin_paused'] === true || ($detached->find($type, $publicId)['admin_paused'] ?? false))) {
            $cleared = false;
            $this->locked(function () use ($type, $publicId, $detached, &$cleared): void {
                Container::monitorMap()->setAdminPaused($type, $publicId, false);
                $detached->reload();
                $detached->clearAdminPause($type, $publicId);
                $cleared = true;
            });
            if (!$cleared) {
                return;
            }
            foreach (Container::monitorMap()->forMonitorPublicId($publicId) as $shared) {
                if ($shared['monitor_type'] === $type) {
                    $keys[] = $shared['kind'] . ':' . $shared['whmcs_ref_id'] . ':' . $type;
                }
            }
        }
        $this->syncSelected(array_values(array_unique($keys)));
    }

    /**
     * "Ignorieren" (round 2, D1: an ignored target is a configuration exclusion, like a type switched off): adds the host to
     * `ignored_targets` and detaches its map rows through the Reconciler, under the lock. No API call: every monitor of the
     * host stays in Uptimeify as it is, its origin and admin pause are kept for a later re-adoption. To stop a monitor,
     * the admin pauses it ("Pausieren") before or instead of ignoring.
     */
    public function ignore(string $target): void
    {
        $host = TargetNormalizer::host($target);
        $settings = Container::settings();
        if ($host === '' || !($this->knownTargets()[$host] ?? in_array($host, $settings->ignoredTargets(), true))) {
            throw new \InvalidArgumentException(View::t('error.unknown_target'));
        }
        $this->locked(function () use ($host, $settings): void {
            $settings->setIgnoredTargets([...$settings->ignoredTargets(), $host]);
            $result = new ReconcileResult();
            // Built after the settings change: its planner reads the ignored targets when it is constructed.
            Container::reconciler(true)->detachIgnoredTarget($host, $result);
            Flash::set('success', View::t('dashboard.ignored', ['target' => $host, 'detached' => $result->detached]));
        });
    }

    /**
     * "Nicht mehr ignorieren": removes the host from `ignored_targets` (under the lock, no API call) and queues a job per
     * object that plans the host again. The next worker run adopts the monitors again; their detached records give back
     * origin and admin pause.
     */
    public function unignore(string $target): void
    {
        $host = TargetNormalizer::host($target);
        $settings = Container::settings();
        if ($host === '' || !in_array($host, $settings->ignoredTargets(), true)) {
            throw new \InvalidArgumentException(View::t('error.unknown_target'));
        }
        $this->locked(function () use ($host, $settings): void {
            $settings->setIgnoredTargets(array_values(array_filter($settings->ignoredTargets(), static fn (string $t): bool => $t !== $host)));
            $keys = [];
            foreach (Container::planner()->planAll() as $plan) {
                if ($plan->target === $host) {
                    $keys[] = $plan->key();
                }
            }
            $queue = Container::queue();
            foreach ($this->jobsFor($keys, false) as [$job, $refId]) {
                $queue->push($job, $refId);
            }
            Flash::set('success', View::t('dashboard.unignored', ['target' => $host]));
        });
    }

    /**
     * "Kunde wählen" / "Zuordnung neu setzen": a manual mapping, only for an existing WHMCS client and an existing
     * customer that no other client holds, neither in customer_map nor by its `whmcs_client_id` (one customer ↔ one
     * client, spec §10). The client's monitors follow through a queued `client.upsert`.
     */
    public function setCustomer(int $clientId, string $customerPublicId): void
    {
        $customerPublicId = trim($customerPublicId);
        $client = $clientId > 0 ? (new ClientRepository())->find($clientId) : null;
        if ($client === null) {
            throw new \InvalidArgumentException(View::t('error.unknown_client', ['id' => $clientId]));
        }
        if ($customerPublicId === '') {
            throw new \InvalidArgumentException(View::t('error.unknown_customer', ['customer' => '']));
        }
        $this->locked(function () use ($clientId, $customerPublicId): void {
            $customerMap = Container::customerMap();
            $owners = array_values(array_diff($customerMap->clientIdsFor($customerPublicId), [$clientId]));
            if ($owners !== []) {
                throw new \InvalidArgumentException(View::t('error.customer_held', ['customer' => $customerPublicId, 'owners' => implode(', #', $owners)]));
            }
            try {
                $customer = Container::guard(true)->run(fn (): array => Container::apiOrFail()->getCustomer($customerPublicId));
            } catch (NotFoundException) {
                throw new \InvalidArgumentException(View::t('error.unknown_customer', ['customer' => $customerPublicId]));
            } catch (ApiException $e) {
                if ($e->status !== 400) {
                    throw $e;
                }
                throw new \InvalidArgumentException(View::t('error.unknown_customer', ['customer' => $customerPublicId]));
            }
            /** @var array<string,mixed> $customer */
            $tagged = RemoteIndex::whmcsIdOf($customer);
            if ($tagged !== null && $tagged !== $clientId) {
                throw new \InvalidArgumentException(View::t('error.customer_tagged', ['customer' => $customerPublicId, 'tagged' => $tagged]));
            }
            $package = is_string($customer['packageDisplayName'] ?? null) && $customer['packageDisplayName'] !== '' ? $customer['packageDisplayName'] : null;
            $customerMap->set($clientId, $customerPublicId, $package, 'manual');
            Container::queue()->push(JobHandler::JOB_CLIENT_UPSERT, $clientId);
            Flash::set('success', View::t('dashboard.customer_set', ['id' => $clientId, 'customer' => $customerPublicId]));
        });
    }

    /**
     * The queue job per selected key (deduplicated, in order): hosting → `hosting.activate`, registrar domain →
     * `domain.upsert`, server IP → `client.upsert` of its client. Jobs follow live WHMCS state, so a key whose object
     * ended retires its monitor instead.
     *
     * @param list<string> $keys
     * @return list<array{0:string,1:int}>
     * @throws \InvalidArgumentException for a key that is neither planned nor mapped (when $strict; else it is skipped)
     */
    private function jobsFor(array $keys, bool $strict = true): array
    {
        $known = null;
        $jobs = [];
        foreach ($keys as $key) {
            $known ??= $this->knownKeys();
            $row = $known[$key] ?? null;
            if ($row === null) {
                if (!$strict) {
                    continue;
                }
                throw new \InvalidArgumentException(View::t('error.unknown_row'));
            }
            [$job, $refId] = match ($row['kind']) {
                MonitorMap::KIND_HOSTING => [JobHandler::JOB_HOSTING_ACTIVATE, $row['refId']],
                MonitorMap::KIND_DOMAIN => [JobHandler::JOB_DOMAIN_UPSERT, $row['refId']],
                default => [JobHandler::JOB_CLIENT_UPSERT, MonitorPlanner::decodeServerIpRefId($row['refId'])['clientId']],
            };
            $jobs[$job . ':' . $refId] = [$job, $refId];
        }
        return array_values($jobs);
    }

    /** @return array<string,array{kind:string,refId:int}> every planned or mapped "kind:refId:type" */
    private function knownKeys(): array
    {
        $out = [];
        foreach (Container::planner()->planAll() as $plan) {
            $out[$plan->key()] = ['kind' => $plan->kind, 'refId' => $plan->refId];
        }
        foreach (Container::monitorMap()->all() as $row) {
            $out[$row['kind'] . ':' . $row['whmcs_ref_id'] . ':' . $row['monitor_type']] = ['kind' => (string) $row['kind'], 'refId' => (int) $row['whmcs_ref_id']];
        }
        return $out;
    }

    /** @return array<string,true> every planned or mapped target */
    private function knownTargets(): array
    {
        $out = [];
        foreach (Container::planner()->planAll() as $plan) {
            $out[$plan->target] = true;
        }
        foreach (Container::monitorMap()->all() as $row) {
            $out[(string) $row['target']] = true;
        }
        return $out;
    }

    /** @return array<string,mixed>|null */
    private function mapRow(string $key): ?array
    {
        $parts = explode(':', $key);
        if (count($parts) !== 3 || preg_match('/^\d{1,19}$/', $parts[1]) !== 1) {
            return null;
        }
        return Container::monitorMap()->find($parts[0], (int) $parts[1], $parts[2]);
    }

    /**
     * @param list<array{0:string,1:int}> $jobs
     */
    private function queueJobs(array $jobs, int $from, int $count, int $done): void
    {
        $queue = Container::queue();
        $queued = 0;
        foreach (array_slice($jobs, $from) as [$job, $refId]) {
            $queue->push($job, $refId);
            $queued++;
        }
        Flash::set('warning', View::t('dashboard.selected_partial', ['done' => $done, 'count' => $count, 'queued' => $queued]));
    }

    /** Best effort: a failing incident or list call must not turn a finished action into an error. */
    private function refreshCache(RemoteIndex $index): void
    {
        try {
            Container::reconciler(true)->refreshCache($index, null, false); // no incident walk in an admin request (D2)
        } catch (UnauthorizedException $e) {
            throw $e;
        } catch (\Throwable) {
            // the next worker run or reconcile writes the cache
        }
    }

    /** False (with the flash message) when the token belongs to another organization than the bound one. */
    private function organizationVerified(): bool
    {
        try {
            Container::organizationBinding()->verify();
            return true;
        } catch (OrganizationMismatchException) {
            Flash::set('error', View::t('error.organization_mismatch'));
            return false;
        }
    }

    /** @param \Closure():void $action */
    private function locked(\Closure $action): void
    {
        $lock = new SyncLock();
        if (!$lock->acquire()) {
            Flash::set('error', View::t('error.sync_locked'));
            return;
        }
        try {
            $action();
        } finally {
            $lock->release();
        }
    }

    /** @param array<int,\WHMCS\Module\Addon\Uptimeify\Whmcs\ClientRow> $clients */
    private static function clientName(array $clients, int $clientId): string
    {
        return isset($clients[$clientId]) ? $clients[$clientId]->displayName : '#' . $clientId;
    }

    private static function string(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
