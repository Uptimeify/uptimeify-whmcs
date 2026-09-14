<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Uptimeify\Sync;

use WHMCS\Module\Addon\Uptimeify\Api\Client;
use WHMCS\Module\Addon\Uptimeify\Api\Exception\ApiException;
use WHMCS\Module\Addon\Uptimeify\Api\Exception\NotFoundException;
use WHMCS\Module\Addon\Uptimeify\Api\Exception\QuotaExceededException;
use WHMCS\Module\Addon\Uptimeify\Api\Exception\RateLimitedException;
use WHMCS\Module\Addon\Uptimeify\Api\Exception\UnauthorizedException;
use WHMCS\Module\Addon\Uptimeify\Bridge;
use WHMCS\Module\Addon\Uptimeify\Settings;
use WHMCS\Module\Addon\Uptimeify\Store\CustomerMap;
use WHMCS\Module\Addon\Uptimeify\Store\DetachedMonitors;
use WHMCS\Module\Addon\Uptimeify\Store\MonitorMap;
use WHMCS\Module\Addon\Uptimeify\Store\Queue;
use WHMCS\Module\Addon\Uptimeify\Store\Schema;
use WHMCS\Module\Addon\Uptimeify\Store\StatusCache;
use WHMCS\Module\Addon\Uptimeify\Store\SyncLock;
use WHMCS\Module\Addon\Uptimeify\Whmcs\ClientRepository;
use WHMCS\Module\Addon\Uptimeify\Whmcs\ClientRow;
use WHMCS\Module\Addon\Uptimeify\Whmcs\DomainRepository;
use WHMCS\Module\Addon\Uptimeify\Whmcs\HostingRepository;

/**
 * The full WHMCS → Uptimeify reconcile (spec §8.3) and the building blocks the queue worker reuses for single
 * objects (reconcileObject, applyPlanned, retire, refreshCache).
 *
 * Safety rules, in order of importance:
 * - Never touch a remote monitor that belongs to another customer than the map row's (spec §10).
 * - Never pause or delete a remote monitor while another map row still references it or a planned monitor of the
 *   same customer targets it (shared monitors: two hostings on one host, hosting + registrar domain).
 * - A monitor missing from one list walk is not proof it is gone (offset paging can skip rows): its row becomes
 *   `orphaned` first; only when it is still missing on the next load is the row removed and the monitor recreated.
 * - Before creating, a remote monitor with the same normalized target under the same customer is adopted.
 * - Configuration never destroys (round 2, D1): a monitor the WHMCS state still wants but the settings exclude only
 *   loses its map row. WHMCS end events delete only monitors the addon created (`origin=created`); an adopted website
 *   is paused, any other adopted monitor detached.
 * - An admin's persistent pause (`admin_paused`, round 2 D7) is never resumed by a run or a job.
 *
 * Not final: Task 11 mocks it.
 */
class Reconciler
{
    public const TAG_NAME = 'whmcs';
    public const TAG_COLOR = 'teal';
    public const ALL_TYPES = RemoteIndex::TYPES;

    public const ABORT_LOCKED = 'locked';
    public const ABORT_TIME_BUDGET = 'time_budget';
    public const ABORT_RATE_LIMITED = 'rate_limited';
    public const ABORT_UNAUTHORIZED = 'unauthorized';
    public const ABORT_API = 'api';
    /** The token belongs to another organization than the bound one (round 2, D9). */
    public const ABORT_ORGANIZATION = 'organization';

    /** Queue job names (spec §7) used for the work a time-limited run leaves over (spec §9.1). */
    public const JOB_CLIENT_UPSERT = 'client.upsert';
    public const JOB_HOSTING_ACTIVATE = 'hosting.activate';
    public const JOB_HOSTING_REMOVE = 'hosting.remove';
    public const JOB_DOMAIN_UPSERT = 'domain.upsert';
    public const JOB_DOMAIN_REMOVE = 'domain.remove';

    /** Client-side `last_error` codes (the API ones, e.g. `invalidUrl` or quota codes, are stored as sent). */
    public const ERROR_NO_DEFAULT_PACKAGE = 'noDefaultPackage';
    public const ERROR_CUSTOMER_CONFLICT = 'customerConflict';
    public const ERROR_INVALID_URL = 'invalidUrl';
    public const ERROR_REMOTE_MISSING = 'remoteMonitorNotFound';

    /** Remote statuses of a monitor that is not being checked. */
    private const PAUSED_STATUSES = ['inactive', 'paused', 'disabled'];

    /**
     * Monitor types the addon can pause and resume: websites (`PATCH /api/websites/:id` `inactive`/`active`) and customer
     * IPs (`PATCH /api/customer-ips/:id` `disabled`/`active`), docs/api-surface.md.
     */
    public const STATUS_TYPES = [MonitorMap::TYPE_WEBSITE, MonitorMap::TYPE_CUSTOMER_IP];

    /*
     * Which WHMCS statuses end a monitor has one source, MonitorPlanner: a hosting in LIVE_HOSTING_STATUSES still exists
     * and may come back (its website is paused, not ended; its domain-expiry monitor stays unless it is Active), a
     * registrar domain in PENDING_DOMAIN_STATUSES has not ended (spec §8.5 ends: Expired, Transferred Away, Cancelled,
     * deleted; Fraud is treated as an end too).
     */

    private const CLIENT_CLOSED = 'Closed';

    /** @var \Closure():float */
    private \Closure $clock;
    private readonly Queue $queue;
    private readonly DomainRepository $domains;
    private readonly DetachedMonitors $detached;
    private bool $tagResolved = false;
    private ?int $tagId = null;

    /**
     * "type|clientId|target" of every desired monitor while run() or reconcileObject() is working, [] otherwise: a
     * planned monitor that is not mapped yet still protects a remote monitor with that target from being retired.
     *
     * @var array<string,true>
     */
    private array $desiredTargets = [];

    /**
     * Whether applyPlanned() sends a target again that the API refused with `invalidUrl` (round 2 review I1). The refusal
     * is not permanent: the API's URL check resolves the host, so a domain whose DNS is not set up yet, or
     * a resolver failure, gets the same 400 `invalidUrl` as a private target, with only the free-text `statusMessage`
     * telling them apart. True for jobs and the daily reconcile (once per load, see RemoteIndex::targetRefusedInThisLoad());
     * false while a budgeted run() works, so "Jetzt abgleichen" does not send it again from an admin request.
     */
    private bool $retryRefusedTargets = true;

    /**
     * @param (callable():float)|null $clock seconds, default microtime(true); drives the time budget
     */
    public function __construct(
        private readonly Client $api,
        private readonly Settings $settings,
        private readonly CustomerMap $customerMap,
        private readonly MonitorMap $monitorMap,
        private readonly MonitorPlanner $planner,
        private readonly CustomerMirror $mirror,
        private readonly StatusCache $cache,
        private readonly ClientRepository $clients,
        private readonly HostingRepository $hostings,
        private readonly SyncLock $lock,
        private readonly RateLimitGuard $guard,
        ?callable $clock = null,
        ?Queue $queue = null,
        ?DomainRepository $domains = null,
        ?DetachedMonitors $detached = null,
    ) {
        $this->clock = $clock === null ? static fn (): float => microtime(true) : \Closure::fromCallable($clock);
        $this->queue = $queue ?? new Queue();
        $this->domains = $domains ?? new DomainRepository();
        $this->detached = $detached ?? new DetachedMonitors();
    }

    /**
     * Spec §8.3, after the organization check (round 2, D9: a token of another organization aborts with
     * `abortReason='organization'` before any other request). With a time budget (admin buttons, spec §9.1) the run
     * stops doing API work once the budget is used up, pushes the remaining work as queue jobs and returns
     * `aborted=true, abortReason='time_budget'`. In such a budgeted run a 429 (after the guard's retries; the admin guard has none) while applying or retiring counts as
     * the budget used up, so the rest is queued too.
     * Without a budget (cron), a 429 after the guard's retries aborts without queueing (the next run does the rest), as
     * does any failure to load the remote lists in both modes; 401 also resets `connection_validated`.
     */
    public function run(?float $timeBudgetSeconds = null): ReconcileResult
    {
        $result = new ReconcileResult();
        if (!$this->lock->acquire()) {
            $result->aborted = true;
            $result->abortReason = self::ABORT_LOCKED;
            $result->messages[] = 'another sync holds the lock';
            Bridge::logActivity('Reconcile skipped: another sync holds the lock');
            return $result;
        }
        $started = ($this->clock)();
        try {
            // Round 2, D9: one cheap request before anything else; another organization stops the run.
            (new OrganizationBinding($this->settings, $this->api, $this->guard))->verify();
            $this->detached->reload();
            $this->reconcile($result, $started, $timeBudgetSeconds);
        } catch (OrganizationMismatchException $e) {
            $result->aborted = true;
            $result->abortReason = self::ABORT_ORGANIZATION;
            $result->messages[] = $e->getMessage();
            $this->writeLastReconcile($result);
        } catch (UnauthorizedException $e) {
            $this->settings->setBool('connection_validated', false);
            $this->abort($result, self::ABORT_UNAUTHORIZED, $e);
        } catch (RateLimitedException $e) {
            $this->abort($result, self::ABORT_RATE_LIMITED, $e);
        } catch (ApiException $e) {
            $result->errors++;
            $this->abort($result, self::ABORT_API, $e);
        } finally {
            $this->desiredTargets = [];
            $this->retryRefusedTargets = true;
            $this->lock->release();
        }
        Bridge::logActivity('Reconcile finished: ' . $result->summary());
        return $result;
    }

    /**
     * Brings one desired monitor in line: orphan check, customer resolution, then align (resume) a mapped monitor,
     * adopt a matching remote one, or create it (+ tag `whmcs`). Quota, invalidUrl and other API errors end in the
     * row's `state=error` / `last_error` (and in `$result->rowErrors`); only 401 and an exhausted 429 are thrown.
     * Quota hits per customer pool, rows orphaned and targets refused against this load are kept on the RemoteIndex, so
     * every job of a worker batch that shares the index sees them. A row whose unchanged target was refused with
     * `invalidUrl` is sent again once per load (not from a budgeted run); a skipped one, like a known quota error that was
     * not tried again, is not this call's error.
     *
     * @param array<string,string> $runState memory shared by the calls of one run or job: customer resolution (result
     *                                       or failure) per client; start with []
     */
    public function applyPlanned(PlannedMonitor $plan, ClientRow $client, RemoteIndex $index, ReconcileResult $result, array &$runState): void
    {
        $existing = $this->monitorMap->find($plan->kind, $plan->refId, $plan->monitorType);
        // Orphaned by an earlier job against this same load: only the next load may remove or recreate it.
        if ($existing !== null && $existing['state'] === MonitorMap::STATE_ORPHANED && $index->rowOrphanedInThisLoad($plan->key())) {
            return;
        }

        $waitForNextRun = false;
        if ($existing !== null && self::publicIdOf($existing) !== null && $index->monitorByPublicId($plan->monitorType, (string) self::publicIdOf($existing)) === null) {
            if ($existing['state'] === MonitorMap::STATE_ORPHANED) {
                $this->monitorMap->remove($plan->kind, $plan->refId, $plan->monitorType);
                $result->messages[] = sprintf('%s: remote monitor %s still missing, row removed', $plan->key(), self::publicIdOf($existing));
                $existing = null;
            } else {
                $this->markOrphaned($existing, $result, $index);
                $waitForNextRun = true;
            }
        }

        $customerPublicId = $this->resolveCustomer($plan, $client, $index, $result, $runState);
        if ($customerPublicId === null) {
            return;
        }

        $publicId = $existing === null ? null : self::publicIdOf($existing);
        $remote = $publicId === null || $waitForNextRun ? null : $index->monitorByPublicId($plan->monitorType, $publicId);
        $retiredNow = null;
        // An admin pause of the object follows it to the monitor that replaces a detached or replaced one.
        $adminPause = ($existing['admin_paused'] ?? false) === true;
        if ($existing !== null && $remote !== null) {
            $owner = RemoteIndex::customerIdOf($remote);
            // The remote URL decides, not the map row: a row can point at a monitor for another host.
            $remoteTarget = RemoteIndex::targetOf($plan->monitorType, $remote);
            $currentTarget = $remoteTarget !== '' ? $remoteTarget : (string) $existing['target'];
            if ($owner !== '' && $owner !== $customerPublicId) {
                $this->monitorMap->remove($plan->kind, $plan->refId, $plan->monitorType);
                $result->messages[] = sprintf('%s: monitor %s belongs to customer %s, not %s; row detached, monitor left alone', $plan->key(), $publicId, $owner, $customerPublicId);
            } elseif ($currentTarget !== $plan->target) {
                // Same rule as retireRows(): no pause/delete/update unless the index confirms the row's customer owns it.
                if ($owner === '' || $owner !== ($existing['customer_public_id'] ?? null)) {
                    // Like detach(): a later re-adoption keeps what the addon knew (created, admin pause).
                    $this->detached->remember($plan->monitorType, (string) $publicId, (string) $existing['origin'], $existing['admin_paused'] === true);
                    $this->monitorMap->remove($plan->kind, $plan->refId, $plan->monitorType);
                    $result->messages[] = sprintf('%s: owner of monitor %s not confirmed, row detached, monitor left alone; target changed from %s to %s', $plan->key(), $publicId, $currentTarget, $plan->target);
                } elseif ($this->skipsRefusedTarget($existing, $plan, $index, $result)) {
                    return;
                } elseif (!$this->changeTarget($existing, $remote, $currentTarget, $plan, $customerPublicId, $index, $result)) {
                    return;
                }
                $retiredNow = $publicId;
            } else {
                $this->align($plan, $customerPublicId, $remote, $index, $result, null, false, $owner !== '');
                return;
            }
        }

        $adoptable = $this->adoptable($plan, $customerPublicId, $index, $retiredNow);
        if ($adoptable !== null) {
            $result->adopted++;
            $this->align($plan, $customerPublicId, $adoptable, $index, $result, $this->originOf($plan->monitorType, self::stringOf($adoptable['publicId'] ?? null), MonitorMap::ORIGIN_ADOPTED), $adminPause);
            return;
        }
        if ($waitForNextRun) {
            return;
        }
        if ($existing !== null && $this->skipsRefusedTarget($existing, $plan, $index, $result)) {
            return;
        }
        $quotaHit = $index->quotaHit(self::quotaKey($customerPublicId, $plan->monitorType));
        if ($quotaHit !== null) {
            $result->quotaSkipped++;
            $this->recordError($plan, $customerPublicId, $quotaHit, $result, false);
            return;
        }
        $this->create($plan, $customerPublicId, $index, $result, $adminPause);
    }

    /**
     * True when the row's unchanged target was refused with `invalidUrl` and is not sent again now: already refused against
     * this load, or a budgeted run. Such a skip is not this call's error (round 2 review I1: a job that did not try it
     * again, e.g. a ServiceEdit job in a batch that already sent the target, is not failed by the known error).
     *
     * @param array<string,mixed> $existing
     */
    private function skipsRefusedTarget(array $existing, PlannedMonitor $plan, RemoteIndex $index, ReconcileResult $result): bool
    {
        if ($existing['state'] !== MonitorMap::STATE_ERROR || $existing['last_error'] !== self::ERROR_INVALID_URL || $existing['target'] !== $plan->target) {
            return false;
        }
        if ($this->retryRefusedTargets && !$index->targetRefusedInThisLoad($plan->key(), $plan->target)) {
            return false;
        }
        $result->messages[] = sprintf('%s %s: refused as invalidUrl, not sent again %s', $plan->key(), $plan->target, $this->retryRefusedTargets ? 'against this load' : 'by an admin run (jobs and the daily reconcile try it again)');
        return true;
    }

    /**
     * Ends the monitor of a map row that is no longer desired, by live WHMCS state and settings (spec §8.5, round 2 D1/D5):
     * - the WHMCS state still wants it and only the configuration excludes it (type switch off, customer filter, ignored
     *   target, unavailable server) → detach: the row goes, the remote monitor stays untouched;
     * - website: hosting Active/Suspended/Pending or client Closed → pause;
     * - customer domain of a hosting: hosting Suspended/Pending or client Closed → stays;
     * - customer domain / DNS of a registrar domain: pending/grace/redemption or client Closed → stays;
     * - customer IP: client Closed → stays; no Active but a Suspended hosting of the client on the server → pause;
     * - otherwise a WHMCS end event (Terminated/Cancelled/Fraud/deleted hosting, ended or deleted domain, deleted client,
     *   no Active or Suspended hosting left on the server): a `created` website follows `on_terminate` (pause or delete),
     *   any other `created` monitor is deleted; an `adopted` website is paused, any other adopted monitor detached.
     * A monitor still referenced by another row (or by `$plannedTargets`) is not touched remotely. Nothing is paused or
     * deleted unless the RemoteIndex confirms the monitor belongs to the row's customer: an unlisted monitor marks the
     * row `orphaned`, one of another customer only loses the row. 404 on delete counts as gone, 404 on pause marks
     * the row `orphaned`.
     *
     * @param array<string,mixed> $row a monitor_map row
     * @param array<string,true> $plannedTargets "type|customerPublicId|target" of desired monitors in this run
     */
    public function retire(array $row, RemoteIndex $index, ReconcileResult $result, array $plannedTargets = []): void
    {
        $this->retireRows([$row], $index, $result, $plannedTargets);
    }

    /**
     * Dashboard "Ignorieren" (round 2, D1: an ignored target is a configuration exclusion). Call under the sync lock, with
     * a Reconciler built after the host was added to `ignored_targets`. Every map row of the host that the configuration
     * now excludes is detached exactly like in a run: the row goes, origin and admin pause are kept in DetachedMonitors, and
     * no API call is made, so the remote monitor stays as it is. A row without a monitor just goes. A row whose WHMCS
     * object has ended or whose client is Closed or gone stays for the next run, which applies the end-event rules.
     */
    public function detachIgnoredTarget(string $host, ReconcileResult $result): void
    {
        $this->detached->reload();
        foreach ($this->monitorMap->all() as $row) {
            if ($row['target'] !== $host) {
                continue;
            }
            if (self::publicIdOf($row) === null) {
                $this->monitorMap->remove((string) $row['kind'], (int) $row['whmcs_ref_id'], (string) $row['monitor_type']);
                continue;
            }
            [$action, $reason] = $this->retireAction($row);
            if ($action === 'detach') {
                $this->detach($row, $reason, $result);
            }
        }
    }

    /**
     * The reconcile for one WHMCS object, run by a queue job (spec §8.4: the same building blocks as run(), for one
     * ref id). The same steps as run(), restricted to the object:
     * - desired monitors of the involved clients (a missing or Closed client has none, spec §8.5);
     * - the orphan check for the object's rows that are not desired;
     * - applyPlanned() for the object's desired monitors, with a fresh run state (quota hits and rows orphaned against
     *   the load are shared through the RemoteIndex);
     * - retirement of the object's rows that are not desired, by live WHMCS state.
     * Every desired monitor of the involved clients protects its target, exactly like in run(): a single job never
     * pauses or deletes a remote monitor that another planned row still targets. The involved clients are
     * `$clientIds` plus every client mapped to a customer of the rows. Does not take the lock: the caller holds it.
     * 401 and an exhausted 429 are thrown, like in applyPlanned().
     *
     * @param list<int> $clientIds the object's client, when WHMCS still knows it
     * @param \Closure(PlannedMonitor):bool $belongs whether a desired monitor of those clients belongs to the object
     * @param list<array<string,mixed>> $rows the object's monitor_map rows
     */
    public function reconcileObject(array $clientIds, \Closure $belongs, array $rows, RemoteIndex $index, ReconcileResult $result): void
    {
        $this->detached->reload();
        $byKey = [];
        foreach ($rows as $row) {
            $byKey[self::keyOf($row)] = $row;
            if (is_string($row['customer_public_id'] ?? null) && $row['customer_public_id'] !== '') {
                array_push($clientIds, ...$this->customerMap->clientIdsFor($row['customer_public_id']));
            }
        }
        $clients = [];
        foreach (array_unique($clientIds) as $clientId) {
            $client = $this->clients->find($clientId);
            if ($client !== null) {
                $clients[$client->id] = $client;
            }
        }
        $desired = $clients === [] ? [] : $this->desiredPlans($clients);
        $this->desiredTargets = self::desiredTargetKeys($desired);
        try {
            foreach ($byKey as $key => $row) {
                if (!isset($desired[$key])) {
                    $this->checkUndesiredRow($row, $index, $result);
                }
            }
            $runState = [];
            foreach ($desired as $plan) {
                if ($belongs($plan)) {
                    $this->applyPlanned($plan, $clients[$plan->clientId], $index, $result, $runState);
                }
            }
            $current = [];
            foreach ($byKey as $row) {
                $now = $this->monitorMap->find((string) $row['kind'], (int) $row['whmcs_ref_id'], (string) $row['monitor_type']);
                if ($now !== null) {
                    $current[] = $now;
                }
            }
            $plannedTargets = $this->plannedTargets($desired);
            foreach (self::retireGroups($current, $desired) as $group) {
                $this->retireRows($group, $index, $result, $plannedTargets);
            }
        } finally {
            $this->desiredTargets = [];
        }
    }

    /**
     * Builds the snapshots and writes `org` and `client:<id>`; with `$result` also `last_reconcile`.
     *
     * Incidents (round 2, D2): with `$walkIncidents` the bounded IncidentWindow is read once (best effort: a failure is
     * logged and flagged, the snapshot is still written; a truncated window flags `incidentsComplete=false`). Without it
     * (budgeted admin requests) nothing is walked: counts come from the map and the monitor lists, the open incidents,
     * their flags and `incidentsRefreshedAt` are the ones stored by the last walk (unavailable when nothing was stored
     * yet). They are read again by the next worker run that handled jobs, the daily reconcile, or a client-area page view
     * once they are older than its TTL.
     */
    public function refreshCache(RemoteIndex $index, ?ReconcileResult $result = null, bool $walkIncidents = true): void
    {
        $readAt = [];
        if ($walkIncidents) {
            [$incidents, $available, $complete] = $this->loadIncidents($result);
            $orgReadAt = null;
        } else {
            [$incidents, $available, $complete, $orgReadAt, $readAt] = $this->storedIncidents();
        }
        $snapshot = SnapshotBuilder::build($index, $incidents, $this->monitorMap, $this->customerMap, $available, null, $complete, $orgReadAt);
        $this->cache->set('org', $snapshot['org']);
        $this->cache->set('org_customers', self::customerList($index));
        foreach ($snapshot['clients'] as $clientId => $data) {
            // Kept incidents keep the time they were read, so the client area still refreshes them after its TTL.
            if (isset($readAt[$clientId])) {
                $data['incidentsRefreshedAt'] = $readAt[$clientId];
            }
            $this->cache->set('client:' . $clientId, $data);
        }
        if ($result !== null) {
            $this->writeLastReconcile($result);
        }
    }

    /** @return array{0: list<array<string,mixed>>, 1: bool, 2: bool} incidents, available, complete */
    private function loadIncidents(?ReconcileResult $result): array
    {
        try {
            $window = IncidentWindow::load($this->api, $this->guard);
            return [$window['incidents'], true, $window['complete']];
        } catch (UnauthorizedException $e) {
            throw $e;
        } catch (ApiException $e) {
            $message = 'Could not load incidents for the status cache: ' . $e->getMessage();
            Bridge::logActivity($message);
            if ($result !== null) {
                $result->messages[] = $message;
            }
            return [[], false, true];
        }
    }

    /**
     * @return array{0: list<array<string,mixed>>, 1: bool, 2: bool, 3: ?string, 4: array<int,string>} the open incidents
     *         and flags of the stored snapshots, when the org's and each client's incidents were read
     */
    private function storedIncidents(): array
    {
        $org = $this->cache->value('org');
        if ($org === null) {
            return [[], false, true, null, []];
        }
        $orgReadAt = self::timestampOf($org['incidentsRefreshedAt'] ?? null) ?? self::timestampOf($org['refreshedAt'] ?? null);
        $incidents = [];
        $readAt = [];
        foreach (array_keys($this->customerMap->all()) as $clientId) {
            $stored = $this->cache->value('client:' . $clientId);
            array_push($incidents, ...IncidentWindow::fromSnapshot($stored));
            $clientReadAt = self::timestampOf($stored['incidentsRefreshedAt'] ?? null) ?? self::timestampOf($stored['refreshedAt'] ?? null) ?? $orgReadAt;
            if ($clientReadAt !== null) {
                $readAt[$clientId] = $clientReadAt;
            }
        }
        return [$incidents, ($org['incidentsAvailable'] ?? false) === true, ($org['incidentsComplete'] ?? true) !== false, $orgReadAt, $readAt];
    }

    private static function timestampOf(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The remote customers for the admin customer picker (spec §9.1 "Kunde wählen", "Zuordnung neu setzen"), so the
     * admin pages never walk the customer list themselves.
     *
     * @return list<array{publicId:string,name:string,email:string,whmcsClientId:?int}>
     */
    private static function customerList(RemoteIndex $index): array
    {
        $out = [];
        foreach ($index->customers() as $customer) {
            $publicId = self::stringOf($customer['publicId'] ?? null);
            if ($publicId !== '') {
                $out[] = [
                    'publicId' => $publicId,
                    'name' => self::stringOf($customer['name'] ?? null),
                    'email' => self::stringOf($customer['email'] ?? null),
                    'whmcsClientId' => RemoteIndex::whmcsIdOf($customer),
                ];
            }
        }
        return $out;
    }

    /**
     * Id of the org-wide tag `whmcs` (listed first, created with color teal only when missing: the API does not
     * dedupe tag names). Cached per instance; null when the lookup fails, then tagging is skipped.
     */
    public function ensureTagId(): ?int
    {
        if ($this->tagResolved) {
            return $this->tagId;
        }
        $id = null;
        try {
            foreach ($this->guard->run(fn (): array => $this->api->listTags()) as $tag) {
                if (($tag['name'] ?? null) === self::TAG_NAME && ($tag['customerId'] ?? null) === null && is_int($tag['id'] ?? null)) {
                    $id = $tag['id'];
                    break;
                }
            }
            if ($id === null) {
                $created = $this->guard->run(fn (): array => $this->api->createTag(self::TAG_NAME, self::TAG_COLOR));
                $id = is_int($created['id'] ?? null) ? $created['id'] : null;
            }
        } catch (UnauthorizedException | RateLimitedException $e) {
            throw $e;
        } catch (ApiException $e) {
            Bridge::logActivity('Could not resolve the whmcs tag: ' . $e->getMessage());
            $id = null;
        }
        $this->tagResolved = true;
        return $this->tagId = $id;
    }

    // ---- run ----------------------------------------------------------------------------

    private function reconcile(ReconcileResult $result, float $started, ?float $budget): void
    {
        $this->retryRefusedTargets = $budget === null;
        $clients = $this->clients->all();
        $desired = $this->desiredPlans($clients);
        $this->desiredTargets = self::desiredTargetKeys($desired);
        $rows = $this->monitorMap->all();
        $index = RemoteIndex::load($this->api, $this->guard, self::typesToLoad($desired, $rows));

        // §8.3.4 for rows that are not desired; desired rows get the same check in applyPlanned().
        foreach ($rows as $row) {
            if (!isset($desired[self::keyOf($row)])) {
                $this->checkUndesiredRow($row, $index, $result);
            }
        }

        $overBudget = false;
        $rateLimited = false;
        $queued = [];
        $runState = [];
        foreach ($desired as $plan) {
            $overBudget = $overBudget || $this->overBudget($started, $budget);
            if ($overBudget) {
                if (!$this->isInSync($plan, $index)) {
                    $this->enqueuePlan($plan, $result, $queued);
                }
                continue;
            }
            try {
                $this->applyPlanned($plan, $clients[$plan->clientId], $index, $result, $runState);
            } catch (RateLimitedException $e) {
                if ($budget === null) {
                    throw $e;
                }
                $overBudget = $rateLimited = true;
                $this->enqueuePlan($plan, $result, $queued);
            }
        }

        $plannedTargets = $this->plannedTargets($desired);
        $desiredRefs = [];
        foreach ($desired as $plan) {
            $desiredRefs[$plan->kind . ':' . $plan->refId] = true;
        }
        foreach (self::retireGroups($this->monitorMap->all(), $desired) as $group) {
            $overBudget = $overBudget || $this->overBudget($started, $budget);
            if (!$overBudget) {
                try {
                    $this->retireRows($group, $index, $result, $plannedTargets);
                    continue;
                } catch (RateLimitedException $e) {
                    if ($budget === null) {
                        throw $e;
                    }
                    $overBudget = $rateLimited = true;
                }
            }
            foreach ($group as $row) {
                $this->enqueueRetirement($row, $desiredRefs, $result, $queued);
            }
        }

        if ($overBudget) {
            $result->aborted = true;
            $result->abortReason = self::ABORT_TIME_BUDGET;
            $result->messages[] = $rateLimited
                ? sprintf('API rate limited (HTTP 429): %d queue jobs pushed, the rest runs in cron', $result->queued)
                : sprintf('Time budget used up: %d queue jobs pushed, the rest runs in cron', $result->queued);
        }
        // A budgeted run is an admin request: no incident walk in it (round 2, D2).
        $this->refreshCache($index, $result, $budget === null);
    }

    /**
     * Desired monitors by key. Plans of a client that no longer exists or is Closed are not desired (spec §8.5
     * "Client Closed": websites paused, nothing new created).
     *
     * @param array<int,ClientRow> $clients
     * @return array<string,PlannedMonitor>
     */
    private function desiredPlans(array $clients): array
    {
        $desired = [];
        foreach ($this->planner->planAll() as $plan) {
            $client = $clients[$plan->clientId] ?? null;
            if ($client !== null && $client->status !== self::CLIENT_CLOSED) {
                $desired[$plan->key()] = $plan;
            }
        }
        return $desired;
    }

    /**
     * Monitor lists to load: the types planned plus the types that have map rows (so rows of a switched-off type
     * are still retired). Unknown types in the map are ignored.
     *
     * @param array<string,PlannedMonitor> $desired
     * @param list<array<string,mixed>> $rows
     * @return list<string>
     */
    private static function typesToLoad(array $desired, array $rows): array
    {
        $types = [];
        foreach ($desired as $plan) {
            $types[$plan->monitorType] = true;
        }
        foreach ($rows as $row) {
            $types[(string) $row['monitor_type']] = true;
        }
        return array_values(array_filter(self::ALL_TYPES, fn (string $type): bool => isset($types[$type])));
    }

    /**
     * @param array<string,mixed> $row
     */
    private function checkUndesiredRow(array $row, RemoteIndex $index, ReconcileResult $result): void
    {
        $publicId = self::publicIdOf($row);
        if ($publicId === null || $index->rowOrphanedInThisLoad(self::keyOf($row))) {
            return;
        }
        $remote = $index->monitorByPublicId((string) $row['monitor_type'], $publicId);
        if ($remote === null) {
            if ($row['state'] === MonitorMap::STATE_ORPHANED) {
                $this->monitorMap->remove((string) $row['kind'], (int) $row['whmcs_ref_id'], (string) $row['monitor_type']);
                $result->messages[] = sprintf('%s: remote monitor %s still missing, row removed', self::keyOf($row), $publicId);
            } else {
                $this->markOrphaned($row, $result, $index);
            }
            return;
        }
        if ($row['state'] === MonitorMap::STATE_ORPHANED) {
            $state = self::isPaused($remote) ? MonitorMap::STATE_PAUSED : MonitorMap::STATE_ACTIVE;
            $this->monitorMap->setState((string) $row['kind'], (int) $row['whmcs_ref_id'], (string) $row['monitor_type'], $state);
        }
    }

    private function overBudget(float $started, ?float $budget): bool
    {
        return $budget !== null && ($this->clock)() - $started > $budget;
    }

    /**
     * True when a queue job for the plan is pointless: applyPlanned() would make no API call (mapped, active, listed
     * under the client's customer, same target, website or customer IP not paused; or paused with the admin's persistent
     * pause) or would skip it (customer mapped to several clients, invalidUrl with the same target: a budgeted run
     * does not send a refused target again, the daily reconcile does).
     */
    private function isInSync(PlannedMonitor $plan, RemoteIndex $index): bool
    {
        $row = $this->monitorMap->find($plan->kind, $plan->refId, $plan->monitorType);
        // Only called in a budgeted run, which does not send a refused target again; the daily reconcile retries it.
        if ($row !== null && $row['state'] === MonitorMap::STATE_ERROR && $row['last_error'] === self::ERROR_INVALID_URL && $row['target'] === $plan->target) {
            return true;
        }
        $publicId = $row === null ? null : self::publicIdOf($row);
        $mapping = $this->customerMap->get($plan->clientId);
        if ($mapping !== null && $this->customerMap->clientIdsFor($mapping['customer_public_id']) !== [$plan->clientId]) {
            return true;
        }
        $adminPaused = $row !== null && $row['admin_paused'] === true;
        if ($row === null || $publicId === null || $mapping === null || $row['target'] !== $plan->target
            || $row['state'] !== ($adminPaused ? MonitorMap::STATE_PAUSED : MonitorMap::STATE_ACTIVE)) {
            return false;
        }
        $remote = $index->monitorByPublicId($plan->monitorType, $publicId);
        return $remote !== null
            && RemoteIndex::customerIdOf($remote) === $mapping['customer_public_id']
            && (!in_array($plan->monitorType, self::STATUS_TYPES, true) || self::isPaused($remote) === $adminPaused);
    }

    /** @param array<string,true> $queued */
    private function enqueuePlan(PlannedMonitor $plan, ReconcileResult $result, array &$queued): void
    {
        [$job, $refId] = match ($plan->kind) {
            MonitorMap::KIND_HOSTING => [self::JOB_HOSTING_ACTIVATE, $plan->refId],
            MonitorMap::KIND_DOMAIN => [self::JOB_DOMAIN_UPSERT, $plan->refId],
            default => [self::JOB_CLIENT_UPSERT, $plan->clientId],
        };
        $this->push($job, $refId, $result, $queued);
    }

    /**
     * A `hosting.remove` / `domain.remove` job retires every row of that object, so it is only pushed when none of
     * the object's monitors is desired. Everything else (mixed objects, server IPs) is left to the next reconcile.
     *
     * @param array<string,mixed> $row
     * @param array<string,true> $desiredRefs "kind:refId" with at least one desired monitor
     * @param array<string,true> $queued
     */
    private function enqueueRetirement(array $row, array $desiredRefs, ReconcileResult $result, array &$queued): void
    {
        $kind = (string) $row['kind'];
        $refId = (int) $row['whmcs_ref_id'];
        $job = match ($kind) {
            MonitorMap::KIND_HOSTING => self::JOB_HOSTING_REMOVE,
            MonitorMap::KIND_DOMAIN => self::JOB_DOMAIN_REMOVE,
            default => null,
        };
        if ($job === null || isset($desiredRefs[$kind . ':' . $refId])) {
            $result->messages[] = sprintf('%s: left for the next reconcile', self::keyOf($row));
            return;
        }
        $this->push($job, $refId, $result, $queued);
    }

    /** @param array<string,true> $queued */
    private function push(string $job, int $refId, ReconcileResult $result, array &$queued): void
    {
        if (isset($queued[$job . ':' . $refId])) {
            return;
        }
        $queued[$job . ':' . $refId] = true;
        $isNew = !$this->queue->isPending($job, $refId);
        $this->queue->push($job, $refId);
        if ($isNew) {
            $result->queued++;
        }
    }

    /**
     * @param array<string,PlannedMonitor> $desired
     * @return array<string,true> "type|clientId|target"
     */
    private static function desiredTargetKeys(array $desired): array
    {
        $out = [];
        foreach ($desired as $plan) {
            $out[$plan->monitorType . '|' . $plan->clientId . '|' . $plan->target] = true;
        }
        return $out;
    }

    /**
     * Rows to retire (not desired, not orphaned), grouped per remote monitor so a shared monitor gets one
     * decision; a row without a monitor is its own group.
     *
     * @param list<array<string,mixed>> $rows
     * @param array<string,PlannedMonitor> $desired
     * @return list<list<array<string,mixed>>>
     */
    private static function retireGroups(array $rows, array $desired): array
    {
        $groups = [];
        foreach ($rows as $row) {
            if (isset($desired[self::keyOf($row)]) || $row['state'] === MonitorMap::STATE_ORPHANED) {
                continue;
            }
            $publicId = self::publicIdOf($row);
            $groups[$publicId === null ? 'row|' . self::keyOf($row) : $row['monitor_type'] . '|' . $publicId][] = $row;
        }
        return array_values($groups);
    }

    /**
     * @param array<string,PlannedMonitor> $desired
     * @return array<string,true> "type|customerPublicId|target"
     */
    private function plannedTargets(array $desired): array
    {
        $customers = $this->customerMap->all();
        $out = [];
        foreach ($desired as $plan) {
            $customer = $customers[$plan->clientId]['customer_public_id'] ?? null;
            if ($customer !== null) {
                $out[$plan->monitorType . '|' . $customer . '|' . $plan->target] = true;
            }
        }
        return $out;
    }

    private function abort(ReconcileResult $result, string $reason, ApiException $e): void
    {
        $result->aborted = true;
        $result->abortReason = $reason;
        $result->messages[] = $e->getMessage();
        $this->writeLastReconcile($result);
    }

    private function writeLastReconcile(ReconcileResult $result): void
    {
        $this->cache->set('last_reconcile', [
            'at' => Schema::now(),
            'summary' => $result->summary(),
            'aborted' => $result->aborted,
            'abortReason' => $result->abortReason,
            'messages' => array_slice($result->messages, 0, 50),
        ]);
    }

    // ---- desired monitors ---------------------------------------------------------------

    /**
     * @param array<string,string> $runState
     */
    private function resolveCustomer(PlannedMonitor $plan, ClientRow $client, RemoteIndex $index, ReconcileResult $result, array &$runState): ?string
    {
        $clientKey = 'client:' . $client->id;
        $resolvedKey = 'customer:' . $client->id;
        if (isset($runState[$resolvedKey])) {
            return $runState[$resolvedKey] === '' ? null : $runState[$resolvedKey];
        }
        if (isset($runState[$clientKey])) {
            if (in_array($runState[$clientKey], Client::QUOTA_ERROR_CODES, true)) {
                $result->quotaSkipped++;
            }
            $this->recordError($plan, null, $runState[$clientKey], $result);
            return null;
        }
        try {
            $customerPublicId = $this->mirror->resolve($client, $index, $this->settings->autoCreateCustomers(), $result);
        } catch (UnauthorizedException | RateLimitedException $e) {
            throw $e;
        } catch (QuotaExceededException $e) {
            $runState[$clientKey] = self::codeOf($e);
            $result->quotaSkipped++;
            $this->failCustomer($plan, $client, $runState[$clientKey], $result);
            return null;
        } catch (ApiException $e) {
            $runState[$clientKey] = self::codeOf($e);
            $result->errors++;
            $this->failCustomer($plan, $client, $runState[$clientKey], $result);
            return null;
        } catch (\RuntimeException $e) {
            if ($e->getMessage() !== CustomerMirror::NO_DEFAULT_PACKAGE_MESSAGE) {
                throw $e;
            }
            $runState[$clientKey] = self::ERROR_NO_DEFAULT_PACKAGE;
            $result->errors++;
            $this->failCustomer($plan, $client, $runState[$clientKey], $result);
            return null;
        }
        if ($customerPublicId === null) {
            $runState[$resolvedKey] = '';
            return null;
        }
        $owners = $this->customerMap->clientIdsFor($customerPublicId);
        if ($owners !== [$client->id]) {
            $runState[$clientKey] = self::ERROR_CUSTOMER_CONFLICT;
            $result->errors++;
            $message = sprintf('Conflict: customer %s is mapped to several WHMCS clients (#%s); no monitors for client #%d until the mapping is fixed', $customerPublicId, implode(', #', $owners), $client->id);
            Bridge::logActivity($message, $client->id);
            $result->messages[] = $message;
            $this->recordError($plan, null, self::ERROR_CUSTOMER_CONFLICT, $result);
            return null;
        }
        $runState[$resolvedKey] = $customerPublicId;
        return $customerPublicId;
    }

    private function failCustomer(PlannedMonitor $plan, ClientRow $client, string $code, ReconcileResult $result): void
    {
        $result->messages[] = sprintf('WHMCS client #%d: customer could not be resolved: %s', $client->id, $code);
        $this->recordError($plan, null, $code, $result);
    }

    /**
     * Mapped or adopted monitor that is listed: map row active; a paused website or customer IP is resumed (spec §8.5
     * unsuspend, round 2 D5). A monitor under the admin's persistent pause (round 2, D7) is kept paused instead, and
     * paused again when it was resumed outside the addon.
     *
     * @param array<string,mixed> $remote
     * What a detached record of the monitor knew (origin, admin pause) moves into the row, and the record is forgotten.
     *
     * @param string|null $origin the row's origin when the row is new (adoption); null keeps the stored one
     * @param bool $adminPause the plan's previous monitor was admin-paused (it was detached or replaced)
     * @param bool $ownerConfirmed false when the list names no customer for the mapped monitor: its status is then left as
     *                             it is (no resume, no re-pause), like retireRows() never pauses without a confirmed owner
     */
    private function align(PlannedMonitor $plan, string $customerPublicId, array $remote, RemoteIndex $index, ReconcileResult $result, ?string $origin = null, bool $adminPause = false, bool $ownerConfirmed = true): void
    {
        $row = $this->row($plan, $customerPublicId, $remote);
        if ($origin !== null) {
            $row['origin'] = $origin;
        }
        $statusType = in_array($plan->monitorType, self::STATUS_TYPES, true);
        if ($statusType && !$ownerConfirmed) {
            $row = ['state' => self::isPaused($remote) ? MonitorMap::STATE_PAUSED : MonitorMap::STATE_ACTIVE] + $row;
            $result->messages[] = sprintf('%s: owner of monitor %s not confirmed, status left as it is', $plan->key(), $row['monitor_public_id']);
            $this->upsertIfChanged($plan, $row);
            return;
        }
        if ($statusType && ($adminPause || $this->isAdminPaused($plan, $row['monitor_public_id']))) {
            $row = ['state' => MonitorMap::STATE_PAUSED, 'admin_paused' => true] + $row;
            if (!self::isPaused($remote)) {
                try {
                    $this->pauseMonitor($plan->monitorType, $row['monitor_public_id'], $remote, $index);
                } catch (UnauthorizedException | RateLimitedException $e) {
                    throw $e;
                } catch (NotFoundException) {
                    $this->saveRow($row);
                    $this->markOrphaned($row, $result, $index);
                    return;
                } catch (ApiException $e) {
                    $result->errors++;
                    $result->messages[] = sprintf('%s: keeping the admin pause failed: %s', $plan->key(), self::codeOf($e));
                    $this->saveRow(['state' => MonitorMap::STATE_ERROR, 'last_error' => self::codeOf($e)] + $row);
                    $result->rowErrors[$plan->key()] = self::codeOf($e);
                    return;
                }
                $result->paused++;
            }
            $this->upsertIfChanged($plan, $row);
            return;
        }
        if ($statusType && self::isPaused($remote)) {
            $quotaKey = self::quotaKey($customerPublicId, $plan->monitorType);
            $quotaHit = $index->quotaHit($quotaKey);
            if ($quotaHit !== null) {
                $result->quotaSkipped++;
                $known = $this->monitorMap->find($plan->kind, $plan->refId, $plan->monitorType);
                $this->saveRow(['state' => MonitorMap::STATE_ERROR, 'last_error' => $quotaHit] + $row);
                if (($known['state'] ?? null) !== MonitorMap::STATE_ERROR || ($known['last_error'] ?? null) !== $quotaHit) {
                    $result->rowErrors[$plan->key()] = $quotaHit; // a known quota error not tried again is not this call's error
                }
                return;
            }
            try {
                $this->setRemoteStatus($plan->monitorType, $row['monitor_public_id'], true);
            } catch (UnauthorizedException | RateLimitedException $e) {
                throw $e;
            } catch (QuotaExceededException $e) {
                $index->rememberQuotaHit($quotaKey, self::codeOf($e));
                $result->quotaSkipped++;
                $result->messages[] = sprintf('%s: resume skipped: %s', $plan->key(), self::codeOf($e));
                $this->saveRow(['state' => MonitorMap::STATE_ERROR, 'last_error' => self::codeOf($e)] + $row);
                $result->rowErrors[$plan->key()] = self::codeOf($e);
                return;
            } catch (NotFoundException $e) {
                $this->saveRow($row);
                $this->markOrphaned($row, $result, $index);
                return;
            } catch (ApiException $e) {
                $result->errors++;
                $result->messages[] = sprintf('%s: resume failed: %s', $plan->key(), self::codeOf($e));
                $this->saveRow(['state' => MonitorMap::STATE_ERROR, 'last_error' => self::codeOf($e)] + $row);
                $result->rowErrors[$plan->key()] = self::codeOf($e);
                return;
            }
            $remote['status'] = 'active';
            $index->addMonitor($plan->monitorType, $remote);
            $result->resumed++;
        }
        $this->upsertIfChanged($plan, $row);
    }

    /** @param array<string,mixed> $row */
    private function upsertIfChanged(PlannedMonitor $plan, array $row): void
    {
        $row = $this->withDetachedOrigin($row);
        $current = $this->monitorMap->find($plan->kind, $plan->refId, $plan->monitorType);
        if ($current === null || array_intersect_key($current, $row) != $row) {
            $this->monitorMap->upsert($row);
        }
        $this->detached->forget((string) $row['monitor_type'], (string) $row['monitor_public_id']);
    }

    /**
     * Upserts a row of a listed monitor and forgets the monitor's detached record, whose origin it takes over.
     *
     * @param array<string,mixed> $row
     */
    private function saveRow(array $row): void
    {
        $this->monitorMap->upsert($this->withDetachedOrigin($row));
        $this->detached->forget((string) $row['monitor_type'], (string) $row['monitor_public_id']);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed> `origin=created` when the monitor's detached record says the addon created it
     */
    private function withDetachedOrigin(array $row): array
    {
        $record = $this->detached->find((string) $row['monitor_type'], (string) $row['monitor_public_id']);
        return ($record['origin'] ?? null) === MonitorMap::ORIGIN_CREATED ? ['origin' => MonitorMap::ORIGIN_CREATED] + $row : $row;
    }

    /**
     * Whether the admin paused this monitor persistently: the plan's row, any other row sharing the remote monitor or the
     * monitor's detached record carries `admin_paused` (a row that adopts the monitor inherits the pause).
     */
    private function isAdminPaused(PlannedMonitor $plan, string $publicId): bool
    {
        if (($this->monitorMap->find($plan->kind, $plan->refId, $plan->monitorType)['admin_paused'] ?? false) === true
            || ($publicId !== '' && ($this->detached->find($plan->monitorType, $publicId)['admin_paused'] ?? false))) {
            return true;
        }
        foreach ($publicId === '' ? [] : $this->monitorMap->forMonitorPublicId($publicId) as $row) {
            if ($row['monitor_type'] === $plan->monitorType && $row['admin_paused'] === true) {
                return true;
            }
        }
        return false;
    }

    /**
     * `created` when any row already mapped to that remote monitor, or the monitor's detached record, says the addon
     * created it (a second WHMCS object that shares or dedupes onto the monitor, or a configuration toggle, does not make
     * it foreign), else `$default`.
     */
    private function originOf(string $monitorType, string $publicId, string $default): string
    {
        if ($publicId !== '' && ($this->detached->find($monitorType, $publicId)['origin'] ?? null) === MonitorMap::ORIGIN_CREATED) {
            return MonitorMap::ORIGIN_CREATED;
        }
        foreach ($publicId === '' ? [] : $this->monitorMap->forMonitorPublicId($publicId) as $row) {
            if ($row['monitor_type'] === $monitorType && $row['origin'] === MonitorMap::ORIGIN_CREATED) {
                return MonitorMap::ORIGIN_CREATED;
            }
        }
        return $default;
    }

    /** @param bool $adminPause create it under the admin pause of the monitor it replaces (created, then paused) */
    private function create(PlannedMonitor $plan, string $customerPublicId, RemoteIndex $index, ReconcileResult $result, bool $adminPause = false): void
    {
        try {
            /** @var array<string,mixed> $created */
            $created = $this->guard->run(fn (): array => $this->createRemote($plan, $customerPublicId));
        } catch (UnauthorizedException | RateLimitedException $e) {
            throw $e;
        } catch (QuotaExceededException $e) {
            $index->rememberQuotaHit(self::quotaKey($customerPublicId, $plan->monitorType), self::codeOf($e));
            $result->quotaSkipped++;
            $result->messages[] = sprintf('%s %s: quota: %s', $plan->key(), $plan->target, self::codeOf($e));
            $this->recordError($plan, $customerPublicId, self::codeOf($e), $result);
            return;
        } catch (ApiException $e) {
            if (self::codeOf($e) === self::ERROR_INVALID_URL) {
                $index->markTargetRefused($plan->key(), $plan->target);
            }
            $result->errors++;
            $result->messages[] = sprintf('%s %s: create failed: %s', $plan->key(), $plan->target, self::codeOf($e));
            $this->recordError($plan, $customerPublicId, self::codeOf($e), $result);
            return;
        }
        if (!is_string($created['publicId'] ?? null) || $created['publicId'] === '') {
            $result->errors++;
            $result->messages[] = sprintf('%s %s: create response without publicId', $plan->key(), $plan->target);
            $this->recordError($plan, $customerPublicId, Client::INVALID_RESPONSE_CODE, $result);
            return;
        }
        // Create responses carry only the numeric customerId; the index matches by customer public id.
        $created['customerPublicId'] = $customerPublicId;
        $index->addMonitor($plan->monitorType, $created);
        // A deduplicated answer is a monitor that already existed: created only if the addon created it for another row.
        $origin = ($created['deduplicated'] ?? false) === true
            ? $this->originOf($plan->monitorType, $created['publicId'], MonitorMap::ORIGIN_ADOPTED)
            : MonitorMap::ORIGIN_CREATED;
        $adminPause = in_array($plan->monitorType, self::STATUS_TYPES, true)
            && ($adminPause || ($this->detached->find($plan->monitorType, $created['publicId'])['admin_paused'] ?? false));
        $this->monitorMap->upsert(['origin' => $origin] + ($adminPause ? ['admin_paused' => true] : []) + $this->row($plan, $customerPublicId, $created));
        $this->detached->forget($plan->monitorType, $created['publicId']);
        if (($created['deduplicated'] ?? false) === true) {
            $result->adopted++;
        } else {
            $result->monitorsCreated++;
        }
        $this->tag($plan->monitorType, $created);
        if ($adminPause) {
            $this->align($plan, $customerPublicId, $created, $index, $result); // pauses it
        }
    }

    /** @return array<mixed> the created monitor row as returned by the API */
    private function createRemote(PlannedMonitor $plan, string $customerPublicId): array
    {
        $label = self::extraString($plan, 'label', $plan->target);
        if ($plan->monitorType === MonitorMap::TYPE_WEBSITE) {
            $customFields = [];
            $extraFields = $plan->extra['customFields'] ?? [];
            foreach (is_array($extraFields) ? $extraFields : [] as $key => $value) {
                if (is_scalar($value)) {
                    $customFields[(string) $key] = $value;
                }
            }
            $interval = $plan->extra['checkInterval'] ?? null;
            return $this->api->createWebsite(
                $customerPublicId,
                $plan->name,
                self::extraString($plan, 'url', 'https://' . $plan->target),
                self::extraString($plan, 'monitoringType', $this->settings->defaultMonitoringType()),
                is_int($interval) ? $interval : $this->settings->defaultCheckInterval(),
                $customFields,
            );
        }
        if ($plan->monitorType === MonitorMap::TYPE_CUSTOMER_DOMAIN) {
            return $this->api->createCustomerDomain($customerPublicId, $plan->target, $label);
        }
        if ($plan->monitorType === MonitorMap::TYPE_DNS) {
            $config = $plan->extra['dnsConfig'] ?? null;
            /** @var array<string,mixed> $dnsConfig */
            $dnsConfig = is_array($config) ? $config : MonitorPlanner::DNS_CONFIG;
            return $this->api->createDnsMonitor($customerPublicId, $plan->name, $plan->target, $dnsConfig);
        }
        if ($plan->monitorType === MonitorMap::TYPE_CUSTOMER_IP) {
            return $this->api->createCustomerIp($customerPublicId, $plan->target, $label);
        }
        throw new \LogicException('unknown monitor type ' . $plan->monitorType);
    }

    /**
     * A remote monitor of the customer whose normalized target equals the planned one (spec §8.3.5). Among several,
     * a website tagged with this hosting's `customFields.whmcs_hosting_id` (spec §5) wins, else the first loaded.
     * A monitor with another host is never adopted, even when it carries the hosting id (it is the old website of
     * a changed domain, or was edited in Uptimeify), and neither is the monitor just retired for this plan.
     *
     * @return array<string,mixed>|null
     */
    private function adoptable(PlannedMonitor $plan, string $customerPublicId, RemoteIndex $index, ?string $excludedPublicId): ?array
    {
        $first = null;
        foreach ($index->monitorsFor($plan->monitorType, $customerPublicId) as $monitor) {
            $publicId = self::stringOf($monitor['publicId'] ?? null);
            if ($publicId === '' || $publicId === $excludedPublicId || RemoteIndex::targetOf($plan->monitorType, $monitor) !== $plan->target) {
                continue;
            }
            if ($plan->kind !== MonitorMap::KIND_HOSTING || $plan->monitorType !== MonitorMap::TYPE_WEBSITE) {
                return $monitor;
            }
            $fields = $monitor['customFields'] ?? null;
            if (is_array($fields) && self::stringOf($fields['whmcs_hosting_id'] ?? null) === (string) $plan->refId) {
                return $monitor;
            }
            $first ??= $monitor;
        }
        return $first;
    }

    /**
     * The mapped monitor watches another target than the plan (the WHMCS domain changed, or the URL was edited in
     * Uptimeify), its owner is confirmed. Round 2, D4/D1:
     * - an adopted monitor is detached (never rewritten, never deleted), the plan then gets a new monitor;
     * - a created website that no other row or desired monitor uses, with no monitor of the customer on the new target
     *   yet, is updated in place (`url`, `name`): same id and history, no duplicate, no orphan;
     * - any other created monitor is replaced (replaceChangedTarget()).
     * True when the plan's key is free for adoption or creation; false when this call is done (updated in place, or the
     * row was marked error/orphaned).
     *
     * @param array<string,mixed> $existing
     * @param array<string,mixed> $remote
     */
    private function changeTarget(array $existing, array $remote, string $oldTarget, PlannedMonitor $plan, string $customerPublicId, RemoteIndex $index, ReconcileResult $result): bool
    {
        $publicId = (string) self::publicIdOf($existing);
        if ($existing['origin'] !== MonitorMap::ORIGIN_CREATED) {
            $this->detached->remember($plan->monitorType, $publicId, MonitorMap::ORIGIN_ADOPTED, $existing['admin_paused'] === true);
            $this->monitorMap->remove($plan->kind, $plan->refId, $plan->monitorType);
            $result->detached++;
            $message = sprintf('%s: adopted monitor %s watches %s, not %s; row detached, monitor left alone', $plan->key(), $publicId, $oldTarget, $plan->target);
            $result->messages[] = $message;
            Bridge::logActivity($message, $plan->clientId);
            return true;
        }
        if ($plan->monitorType === MonitorMap::TYPE_WEBSITE
            && $this->otherReferences($existing) === []
            && !isset($this->desiredTargets[$plan->monitorType . '|' . $plan->clientId . '|' . $oldTarget])
            && $this->adoptable($plan, $customerPublicId, $index, $publicId) === null) {
            $this->updateWebsiteTarget($existing, $remote, $oldTarget, $plan, $customerPublicId, $index, $result);
            return false;
        }
        return $this->replaceChangedTarget($existing, $remote, $oldTarget, $plan, $index, $result);
    }

    /**
     * `PATCH /api/websites/:publicId {url, name}` (a partial update, status unchanged), then align() the row to the new
     * target. 400 `invalidUrl` (the host does not resolve yet, or resolves to a private address) leaves the row in error for
     * the new target, which applyPlanned() sends again once per later load (not from a budgeted run); 404 marks the row
     * orphaned; other API errors mark it error for a retry.
     *
     * @param array<string,mixed> $existing
     * @param array<string,mixed> $remote
     */
    private function updateWebsiteTarget(array $existing, array $remote, string $oldTarget, PlannedMonitor $plan, string $customerPublicId, RemoteIndex $index, ReconcileResult $result): void
    {
        $publicId = (string) self::publicIdOf($existing);
        $url = self::extraString($plan, 'url', 'https://' . $plan->target);
        try {
            /** @var array<string,mixed> $updated */
            $updated = $this->guard->run(fn (): array => $this->api->updateWebsiteTarget($publicId, $url, $plan->name));
        } catch (UnauthorizedException | RateLimitedException $e) {
            throw $e;
        } catch (NotFoundException) {
            $this->markOrphaned($existing, $result, $index);
            return;
        } catch (ApiException $e) {
            $code = self::codeOf($e);
            if ($code === self::ERROR_INVALID_URL) {
                $index->markTargetRefused($plan->key(), $plan->target);
            }
            $result->errors++;
            $result->messages[] = sprintf('%s: website %s could not be changed from %s to %s: %s', $plan->key(), $publicId, $oldTarget, $plan->target, $code);
            $this->monitorMap->upsert(['target' => $plan->target, 'state' => MonitorMap::STATE_ERROR, 'last_error' => $code] + $existing);
            $result->rowErrors[$plan->key()] = $code;
            return;
        }
        $remote['url'] = is_string($updated['url'] ?? null) && $updated['url'] !== '' ? $updated['url'] : $url;
        $remote['name'] = is_string($updated['name'] ?? null) && $updated['name'] !== '' ? $updated['name'] : $plan->name;
        $index->addMonitor(MonitorMap::TYPE_WEBSITE, $remote);
        $result->updated++;
        $result->messages[] = sprintf('%s: website %s changed from %s to %s in place', $plan->key(), $publicId, $oldTarget, $plan->target);
        $this->align($plan, $customerPublicId, $remote, $index, $result);
    }

    /**
     * The monitor mapped for the plan watches another host (the hosting's domain changed, or the URL was edited):
     * the old website follows `on_terminate`, another type is deleted, in both cases only when no other row and no
     * other desired monitor still uses it. Then the key is free for the new target. False when the old monitor could
     * not be handled (row → error, retried next run).
     *
     * @param array<string,mixed> $existing
     * @param array<string,mixed> $remote
     */
    private function replaceChangedTarget(array $existing, array $remote, string $oldTarget, PlannedMonitor $plan, RemoteIndex $index, ReconcileResult $result): bool
    {
        $publicId = (string) self::publicIdOf($existing);
        $others = $this->otherReferences($existing);
        $plannedElsewhere = isset($this->desiredTargets[$plan->monitorType . '|' . $plan->clientId . '|' . $oldTarget]);
        $action = $plan->monitorType === MonitorMap::TYPE_WEBSITE ? $this->settings->onTerminate() : 'delete';
        try {
            if ($action === 'pause') {
                $stillUsed = $plannedElsewhere || array_filter($others, fn (array $r): bool => $r['state'] === MonitorMap::STATE_ACTIVE) !== [];
                if (!$stillUsed && !self::isPaused($remote)) {
                    $this->pauseMonitor($plan->monitorType, $publicId, $remote, $index);
                    $result->paused++;
                }
            } elseif ($others === [] && !$plannedElsewhere) {
                $this->deleteMonitor($plan->monitorType, $publicId, $index);
                $result->deleted++;
            }
        } catch (UnauthorizedException | RateLimitedException $e) {
            throw $e;
        } catch (NotFoundException) {
            // already gone
        } catch (ApiException $e) {
            $result->errors++;
            $result->messages[] = sprintf('%s: old monitor %s for %s could not be retired: %s', $plan->key(), $publicId, $oldTarget, self::codeOf($e));
            $this->monitorMap->setState($plan->kind, $plan->refId, $plan->monitorType, MonitorMap::STATE_ERROR, self::codeOf($e));
            $result->rowErrors[$plan->key()] = self::codeOf($e);
            return false;
        }
        $this->monitorMap->remove($plan->kind, $plan->refId, $plan->monitorType);
        $result->messages[] = sprintf('%s: target changed from %s to %s', $plan->key(), $oldTarget, $plan->target);
        return true;
    }

    /** @param array<string,mixed> $monitor */
    private function tag(string $monitorType, array $monitor): void
    {
        if (!is_int($monitor['id'] ?? null)) {
            return;
        }
        $tagId = $this->ensureTagId();
        if ($tagId === null) {
            return;
        }
        try {
            $monitorId = $monitor['id'];
            $this->guard->run(function () use ($monitorType, $monitorId, $tagId): void {
                $this->api->addMonitorTag($monitorType, $monitorId, $tagId);
            });
        } catch (UnauthorizedException | RateLimitedException $e) {
            throw $e;
        } catch (ApiException $e) {
            Bridge::logActivity(sprintf('Could not tag %s monitor %s: %s', $monitorType, self::stringOf($monitor['publicId'] ?? null), self::codeOf($e)));
        }
    }

    /**
     * @param bool $attempted false when no request for this monitor was sent (a quota hit remembered by the load): an error
     *                        the row already had for the same target is then not this call's error and nothing is written
     */
    private function recordError(PlannedMonitor $plan, ?string $customerPublicId, string $error, ReconcileResult $result, bool $attempted = true): void
    {
        $existing = $this->monitorMap->find($plan->kind, $plan->refId, $plan->monitorType);
        if (!$attempted && ($existing['state'] ?? null) === MonitorMap::STATE_ERROR && ($existing['last_error'] ?? null) === $error && ($existing['target'] ?? null) === $plan->target) {
            return;
        }
        $result->rowErrors[$plan->key()] = $error;
        $this->monitorMap->upsert([
            'kind' => $plan->kind,
            'whmcs_ref_id' => $plan->refId,
            'monitor_type' => $plan->monitorType,
            'monitor_public_id' => $existing['monitor_public_id'] ?? null,
            'monitor_numeric_id' => $existing['monitor_numeric_id'] ?? null,
            'customer_public_id' => $customerPublicId ?? ($existing['customer_public_id'] ?? null),
            'target' => $plan->target,
            'state' => MonitorMap::STATE_ERROR,
            'last_error' => $error,
        ]);
    }

    /**
     * @param array<string,mixed> $monitor
     * @return array{kind:string,whmcs_ref_id:int,monitor_type:string,monitor_public_id:string,monitor_numeric_id:?int,customer_public_id:string,target:string,state:string,last_error:null}
     */
    private function row(PlannedMonitor $plan, string $customerPublicId, array $monitor): array
    {
        return [
            'kind' => $plan->kind,
            'whmcs_ref_id' => $plan->refId,
            'monitor_type' => $plan->monitorType,
            'monitor_public_id' => self::stringOf($monitor['publicId'] ?? null),
            'monitor_numeric_id' => is_int($monitor['id'] ?? null) ? $monitor['id'] : null,
            'customer_public_id' => $customerPublicId,
            'target' => $plan->target,
            'state' => MonitorMap::STATE_ACTIVE,
            'last_error' => null,
        ];
    }

    // ---- retiring -----------------------------------------------------------------------

    /**
     * What happens to a map row that is no longer desired (retire() lists the rules). A hosting or domain whose client no
     * longer exists ends like Terminated (spec §8.5 "Client gelöscht"), even while WHMCS still has its row.
     *
     * @param array<string,mixed> $row
     * @return array{0:'keep'|'pause'|'detach'|'delete', 1:string} the action and why
     */
    private function retireAction(array $row): array
    {
        $kind = (string) $row['kind'];
        $refId = (int) $row['whmcs_ref_id'];
        $type = (string) $row['monitor_type'];
        $clientId = match ($kind) {
            MonitorMap::KIND_HOSTING => $this->hostings->find($refId)?->clientId,
            MonitorMap::KIND_DOMAIN => $this->domains->find($refId)?->clientId,
            MonitorMap::KIND_SERVER_IP => MonitorPlanner::decodeServerIpRefId($refId)['clientId'],
            default => null,
        };
        $client = $clientId === null ? null : $this->clients->find($clientId);
        $closed = $client?->status === self::CLIENT_CLOSED;
        if ($client !== null && !$closed) {
            $exclusion = $this->planner->configExclusion($kind, $refId, $type);
            if ($exclusion !== null) {
                return ['detach', 'excluded by configuration (' . $exclusion . ')'];
            }
        }
        if ($kind === MonitorMap::KIND_HOSTING) {
            $status = $client === null ? null : $this->hostings->find($refId)?->status;
            if ($type === MonitorMap::TYPE_WEBSITE && $status !== null && ($closed || in_array($status, MonitorPlanner::LIVE_HOSTING_STATUSES, true))) {
                return ['pause', $closed ? 'client closed' : 'hosting ' . $status];
            }
            // spec §8.5 "Hosting Suspended: bleibt": a live hosting that is not Active keeps its domain-expiry monitor.
            if ($type !== MonitorMap::TYPE_WEBSITE && $status !== null && ($closed || ($status !== 'Active' && in_array($status, MonitorPlanner::LIVE_HOSTING_STATUSES, true)))) {
                return ['keep', ''];
            }
            return $this->endAction($row, $status === null ? 'hosting or client deleted' : 'hosting ' . $status);
        }
        if ($kind === MonitorMap::KIND_DOMAIN) {
            $status = $client === null ? null : $this->domains->find($refId)?->status;
            if ($status !== null && ($closed || in_array($status, MonitorPlanner::PENDING_DOMAIN_STATUSES, true))) {
                return ['keep', ''];
            }
            return $this->endAction($row, $status === null ? 'domain or client deleted' : 'domain ' . $status);
        }
        if ($kind === MonitorMap::KIND_SERVER_IP) {
            if ($client === null) {
                return $this->endAction($row, 'client deleted');
            }
            ['serverId' => $serverId] = MonitorPlanner::decodeServerIpRefId($refId);
            if ($closed || $this->hostings->clientHasHostingOnServer($client->id, $serverId, 'Active')) {
                return ['keep', ''];
            }
            if ($this->hostings->clientHasHostingOnServer($client->id, $serverId, 'Suspended')) {
                return ['pause', 'every hosting of the client on the server is suspended'];
            }
            return $this->endAction($row, 'no Active or Suspended hosting of the client left on the server');
        }
        return ['keep', ''];
    }

    /**
     * A WHMCS end event (round 2, D1): only a monitor the addon created is ended by `on_terminate` (website) or deleted
     * (other types); an adopted website is paused, any other adopted monitor detached.
     *
     * @param array<string,mixed> $row
     * @return array{0:'pause'|'detach'|'delete', 1:string}
     */
    private function endAction(array $row, string $reason): array
    {
        $website = $row['monitor_type'] === MonitorMap::TYPE_WEBSITE;
        if (($row['origin'] ?? null) === MonitorMap::ORIGIN_CREATED) {
            return [$website && $this->settings->onTerminate() !== 'delete' ? 'pause' : 'delete', $reason];
        }
        return [$website ? 'pause' : 'detach', $reason . ', adopted monitor'];
    }

    /**
     * Retires rows that reference one remote monitor, deciding once for the monitor (so the result does not depend on
     * row order): any row that pauses → the monitor is paused (unless another row outside the group is active or a
     * desired monitor targets it) and the deleting rows only lose their row; all rows delete → deleted, unless another
     * row or a desired monitor still uses it. Rows whose action is keep stay and count as users. A detached row loses
     * its row at once and leaves the monitor untouched for the whole group: configuration never pauses or deletes.
     *
     * @param list<array<string,mixed>> $rows same monitor_type and monitor_public_id (or a single row)
     * @param array<string,true> $plannedTargets
     */
    private function retireRows(array $rows, RemoteIndex $index, ReconcileResult $result, array $plannedTargets): void
    {
        $group = [];
        $detached = false;
        foreach ($rows as $row) {
            if ($row['state'] === MonitorMap::STATE_ORPHANED) {
                continue;
            }
            if (self::publicIdOf($row) === null) {
                $this->monitorMap->remove((string) $row['kind'], (int) $row['whmcs_ref_id'], (string) $row['monitor_type']);
                continue;
            }
            [$action, $reason] = $this->retireAction($row);
            if ($action === 'keep') {
                continue;
            }
            if ($action === 'detach') {
                $this->detach($row, $reason, $result);
                $detached = true;
                continue;
            }
            $remote = $index->monitorByPublicId((string) $row['monitor_type'], (string) self::publicIdOf($row));
            if ($remote === null) {
                $this->markOrphaned($row, $result, $index);
                continue;
            }
            $owner = RemoteIndex::customerIdOf($remote);
            if ($owner === '' || !is_string($row['customer_public_id'])) {
                $result->messages[] = sprintf('%s: owner of monitor %s not confirmed, left alone', self::keyOf($row), self::publicIdOf($row));
                continue;
            }
            if ($owner !== $row['customer_public_id']) {
                $this->monitorMap->remove((string) $row['kind'], (int) $row['whmcs_ref_id'], (string) $row['monitor_type']);
                $result->messages[] = sprintf('%s: monitor %s belongs to customer %s; row removed, monitor left alone', self::keyOf($row), self::publicIdOf($row), $owner);
                continue;
            }
            $group[self::keyOf($row)] = ['row' => $row, 'action' => $action];
        }
        if ($group === []) {
            return;
        }
        $first = reset($group)['row'];
        $type = (string) $first['monitor_type'];
        $publicId = (string) self::publicIdOf($first);
        $remote = $index->monitorByPublicId($type, $publicId);
        $outside = array_filter(
            $this->monitorMap->forMonitorPublicId($publicId),
            fn (array $r): bool => $r['monitor_type'] === $type && !isset($group[self::keyOf($r)]),
        );
        $plannedElsewhere = false;
        $pauses = false;
        foreach ($group as $entry) {
            $plannedElsewhere = $plannedElsewhere || isset($plannedTargets[$type . '|' . $entry['row']['customer_public_id'] . '|' . $entry['row']['target']]);
            $pauses = $pauses || $entry['action'] === 'pause';
        }
        if ($detached) {
            $remoteAction = 'none';
        } elseif ($pauses) {
            $outsideActive = array_filter($outside, fn (array $r): bool => $r['state'] === MonitorMap::STATE_ACTIVE) !== [];
            $remoteAction = !$plannedElsewhere && !$outsideActive && !self::isPaused($remote) ? 'pause' : 'none';
        } else {
            $remoteAction = $outside === [] && !$plannedElsewhere ? 'delete' : 'none';
        }

        try {
            if ($remoteAction === 'pause') {
                $this->pauseMonitor($type, $publicId, $remote, $index);
                $result->paused++;
            } elseif ($remoteAction === 'delete') {
                $this->deleteMonitor($type, $publicId, $index);
                $result->deleted++;
            }
        } catch (UnauthorizedException | RateLimitedException $e) {
            throw $e;
        } catch (NotFoundException) {
            foreach ($group as $entry) {
                $this->markOrphaned($entry['row'], $result, $index);
            }
            return;
        } catch (ApiException $e) {
            $code = self::codeOf($e);
            $result->errors++;
            $result->messages[] = sprintf('monitor %s: %s failed: %s', $publicId, $remoteAction, $code);
            foreach ($group as $entry) {
                $this->monitorMap->setState((string) $entry['row']['kind'], (int) $entry['row']['whmcs_ref_id'], $type, MonitorMap::STATE_ERROR, $code);
                $result->rowErrors[self::keyOf($entry['row'])] = $code;
            }
            return;
        }

        foreach ($group as $key => $entry) {
            $row = $entry['row'];
            if ($entry['action'] === 'pause') {
                // Next to a detached row the monitor stayed as it is: the row is not shown paused; the next run pauses it.
                if (!$detached && ($row['state'] !== MonitorMap::STATE_PAUSED || $row['last_error'] !== null)) {
                    $this->monitorMap->setState((string) $row['kind'], (int) $row['whmcs_ref_id'], $type, MonitorMap::STATE_PAUSED);
                }
                continue;
            }
            $this->monitorMap->remove((string) $row['kind'], (int) $row['whmcs_ref_id'], $type);
            if ($remoteAction !== 'delete') {
                $result->messages[] = sprintf('%s: monitor %s is still used, only the row was removed', $key, $publicId);
            }
        }
    }

    /**
     * Other map rows of the same type that reference the same remote monitor.
     *
     * @param array<string,mixed> $row
     * @return list<array<string,mixed>>
     */
    private function otherReferences(array $row): array
    {
        $publicId = self::publicIdOf($row);
        if ($publicId === null) {
            return [];
        }
        $key = self::keyOf($row);
        return array_values(array_filter(
            $this->monitorMap->forMonitorPublicId($publicId),
            fn (array $other): bool => $other['monitor_type'] === $row['monitor_type'] && self::keyOf($other) !== $key,
        ));
    }

    /**
     * The row goes, the remote monitor stays as it is (round 2, D1), with a log line. Its origin and admin pause are kept
     * in DetachedMonitors until the monitor is adopted again or deleted.
     *
     * @param array<string,mixed> $row
     */
    private function detach(array $row, string $reason, ReconcileResult $result): void
    {
        $this->detached->remember((string) $row['monitor_type'], (string) self::publicIdOf($row), (string) $row['origin'], $row['admin_paused'] === true);
        $this->monitorMap->remove((string) $row['kind'], (int) $row['whmcs_ref_id'], (string) $row['monitor_type']);
        $result->detached++;
        $message = sprintf('%s %s: %s; row detached, monitor %s left untouched', self::keyOf($row), $row['target'], $reason, (string) self::publicIdOf($row));
        $result->messages[] = $message;
        Bridge::logActivity('Monitor detached: ' . $message);
    }

    /**
     * Only websites and customer IPs can be paused through the API the addon uses (STATUS_TYPES); other types are left.
     *
     * @param array<string,mixed>|null $remote
     */
    private function pauseMonitor(string $type, string $publicId, ?array $remote, RemoteIndex $index): void
    {
        if (!in_array($type, self::STATUS_TYPES, true)) {
            return;
        }
        $this->setRemoteStatus($type, $publicId, false);
        if ($remote !== null) {
            $remote['status'] = $type === MonitorMap::TYPE_WEBSITE ? 'inactive' : 'disabled';
            $index->addMonitor($type, $remote);
        }
    }

    /** Website `active`/`inactive`, customer IP `active`/`disabled` (docs/api-surface.md). */
    private function setRemoteStatus(string $type, string $publicId, bool $active): void
    {
        $this->guard->run(fn (): array => $type === MonitorMap::TYPE_WEBSITE
            ? $this->api->setWebsiteStatus($publicId, $active ? 'active' : 'inactive')
            : $this->api->setCustomerIpStatus($publicId, $active ? 'active' : 'disabled'));
    }

    /** A 404 is swallowed: the monitor is gone either way. */
    private function deleteMonitor(string $type, string $publicId, RemoteIndex $index): void
    {
        try {
            $this->guard->run(function () use ($type, $publicId): void {
                if ($type === MonitorMap::TYPE_WEBSITE) {
                    $this->api->deleteWebsite($publicId);
                } elseif ($type === MonitorMap::TYPE_CUSTOMER_DOMAIN) {
                    $this->api->deleteCustomerDomain($publicId);
                } elseif ($type === MonitorMap::TYPE_DNS) {
                    $this->api->deleteDnsMonitor($publicId);
                } elseif ($type === MonitorMap::TYPE_CUSTOMER_IP) {
                    $this->api->deleteCustomerIp($publicId);
                } else {
                    throw new \LogicException('unknown monitor type ' . $type);
                }
            });
        } catch (NotFoundException) {
            // already deleted
        }
        $index->removeMonitor($type, $publicId);
        $this->detached->forget($type, $publicId);
    }

    /** @param array<string,mixed> $row */
    private function markOrphaned(array $row, ReconcileResult $result, RemoteIndex $index): void
    {
        $index->markRowOrphaned(self::keyOf($row));
        $this->monitorMap->setState((string) $row['kind'], (int) $row['whmcs_ref_id'], (string) $row['monitor_type'], MonitorMap::STATE_ORPHANED, self::ERROR_REMOTE_MISSING);
        $result->orphaned++;
        $result->messages[] = sprintf('%s: remote monitor %s not found, marked orphaned', self::keyOf($row), (string) self::publicIdOf($row));
    }

    // ---- helpers ------------------------------------------------------------------------

    /** Quota pools per customer: DNSBL IPs have their own limit, every other monitor type shares one. */
    private static function quotaKey(string $customerPublicId, string $monitorType): string
    {
        return 'quota:' . $customerPublicId . ':' . ($monitorType === MonitorMap::TYPE_CUSTOMER_IP ? 'ip' : 'monitor');
    }

    /** @param array<string,mixed>|null $remote */
    private static function isPaused(?array $remote): bool
    {
        return $remote !== null && in_array($remote['status'] ?? null, self::PAUSED_STATUSES, true);
    }

    private static function codeOf(ApiException $e): string
    {
        return $e->errorCode !== '' ? $e->errorCode : ($e->status > 0 ? 'http' . $e->status : 'api');
    }

    /** @param array<string,mixed> $row */
    private static function publicIdOf(array $row): ?string
    {
        $id = $row['monitor_public_id'] ?? null;
        return is_string($id) && $id !== '' ? $id : null;
    }

    /** @param array<string,mixed> $row */
    private static function keyOf(array $row): string
    {
        return $row['kind'] . ':' . $row['whmcs_ref_id'] . ':' . $row['monitor_type'];
    }

    private static function extraString(PlannedMonitor $plan, string $key, string $default): string
    {
        $value = $plan->extra[$key] ?? null;
        return is_string($value) && $value !== '' ? $value : $default;
    }

    private static function stringOf(mixed $value): string
    {
        return is_string($value) || is_int($value) ? (string) $value : '';
    }
}
