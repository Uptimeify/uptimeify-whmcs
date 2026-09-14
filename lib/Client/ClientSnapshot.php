<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Uptimeify\Client;

use WHMCS\Module\Addon\Uptimeify\Api\Client;
use WHMCS\Module\Addon\Uptimeify\Api\Exception\ApiException;
use WHMCS\Module\Addon\Uptimeify\Bridge;
use WHMCS\Module\Addon\Uptimeify\Store\CustomerMap;
use WHMCS\Module\Addon\Uptimeify\Store\MonitorMap;
use WHMCS\Module\Addon\Uptimeify\Store\StatusCache;
use WHMCS\Module\Addon\Uptimeify\Store\SyncLock;
use WHMCS\Module\Addon\Uptimeify\Sync\IncidentWindow;
use WHMCS\Module\Addon\Uptimeify\Sync\RateLimitGuard;
use WHMCS\Module\Addon\Uptimeify\Sync\RemoteIndex;
use WHMCS\Module\Addon\Uptimeify\Sync\SnapshotBuilder;

/**
 * The `client:<id>` status snapshot for a client-area page view (spec §9.3), refreshed on the page view when it is
 * older than TTL. The refresh runs inside a customer's web request, so it is bounded:
 *
 * - GET calls only, filtered to the client's customer where the API can (`customerId`; the DNS list takes the numeric
 *   id), and only for the monitor types the client has in the monitor map; the customer list is never walked.
 * - The RateLimitGuard the Container passes never sleeps on 429 (interactive guard, like the admin buttons).
 * - Incidents: the bounded IncidentWindow shared with the sync (the API has no status or customer filter); when older
 *   incidents stay unread the snapshot carries `incidentsComplete=false` (round 2, D2). A snapshot whose incidents were
 *   read more than TTL ago (`incidentsRefreshedAt`, kept by an admin status refresh) is refreshed as well.
 * - A soft DEADLINE_SECONDS budget, checked between calls (a call already running is not cut off); the Container also
 *   shortens the HTTP timeout.
 * - A client without monitor types in the map gets an empty snapshot without any API call (no incident walk).
 * - After a failed refresh (API error or deadline) a `client_retry:<id>` marker holds further attempts for
 *   RETRY_SECONDS: page views serve the stored snapshot without HTTP, and the failure is logged once per window.
 * - Skipped while a sync holds the sync lock (checked, not taken, so the cron run is never pushed out) or while
 *   another request refreshes the same client (per-client lock).
 * - Only `client:<id>` of this client is written, never `org` or another client's snapshot, and not when a sync wrote
 *   a newer one while this refresh was loading (that one is served instead).
 * - Any API failure or skip serves the stored snapshot with `stale = true` (or null when there is none); the cause
 *   goes to the activity log, never to the page.
 *
 * A stored snapshot of another customer than the current mapping (the mapping was changed) is never served.
 * cached() reads without refreshing (the product details block).
 */
final class ClientSnapshot
{
    public const TTL = 300;
    public const DEADLINE_SECONDS = 10.0;
    public const HTTP_TIMEOUT = 5.0;
    public const RETRY_SECONDS = 60;

    /** @var \Closure():float */
    private \Closure $clock;

    /** @param (\Closure():float)|null $clock seconds, for the deadline (tests) */
    public function __construct(
        private readonly StatusCache $cache,
        private readonly CustomerMap $customers,
        private readonly MonitorMap $monitors,
        private readonly ?Client $api,
        private readonly RateLimitGuard $guard,
        private readonly SyncLock $lock,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    public static function lockName(int $clientId): string
    {
        return 'uptimeify_client_' . $clientId;
    }

    public static function retryKey(int $clientId): string
    {
        return 'client_retry:' . $clientId;
    }

    /**
     * @return array<string,mixed>|null the snapshot plus `stale` (true when it is expired and could not be refreshed);
     *                                  null when the client has no mapping or there is no usable snapshot
     */
    public function for(int $clientId): ?array
    {
        $mapping = $this->customers->get($clientId);
        if ($mapping === null) {
            return null;
        }
        $customerPublicId = $mapping['customer_public_id'];
        $key = 'client:' . $clientId;
        $entry = $this->cache->get($key);
        $usable = self::usable($entry, $customerPublicId);
        if ($usable !== null && $this->cache->isFresh($key, self::TTL) && self::incidentsFresh($usable)) {
            return $usable + ['stale' => false];
        }
        $fallback = $usable === null ? null : $usable + ['stale' => true];
        if ($this->api === null || $this->cache->isFresh(self::retryKey($clientId), self::RETRY_SECONDS)
            || !$this->lock->isFree() || !$this->lock->acquire(self::lockName($clientId))) {
            return $fallback;
        }
        try {
            $fresh = $this->refresh($this->api, $clientId, $customerPublicId);
            if ($fresh === null) {
                $this->failed($clientId, 'the refresh ran out of time (' . self::DEADLINE_SECONDS . ' s)');
                return $fallback;
            }
            $now = $this->cache->get($key);
            if (($now['refreshed_at'] ?? null) !== ($entry['refreshed_at'] ?? null)) {
                // A sync wrote the snapshot while this refresh was loading: keep and serve the newer one.
                $newer = self::usable($now, $customerPublicId);
                return $newer === null ? $fallback : $newer + ['stale' => false];
            }
            $this->cache->set($key, $fresh);
            return $fresh + ['stale' => false];
        } catch (ApiException $e) {
            $this->failed($clientId, $e->getMessage());
            return $fallback;
        } finally {
            $this->lock->release(self::lockName($clientId));
        }
    }

    /**
     * The stored snapshot without any refresh (null without mapping or usable snapshot); `stale` when expired.
     *
     * @return array<string,mixed>|null
     */
    public function cached(int $clientId): ?array
    {
        $mapping = $this->customers->get($clientId);
        if ($mapping === null) {
            return null;
        }
        $key = 'client:' . $clientId;
        $usable = self::usable($this->cache->get($key), $mapping['customer_public_id']);
        return $usable === null ? null : $usable + ['stale' => !$this->cache->isFresh($key, self::TTL)];
    }

    /**
     * @param array{value:array<mixed>,refreshed_at:string}|null $entry
     * @return array<mixed>|null
     */
    private static function usable(?array $entry, string $customerPublicId): ?array
    {
        return $entry !== null && ($entry['value']['customerPublicId'] ?? null) === $customerPublicId ? $entry['value'] : null;
    }

    /**
     * An admin "Status aktualisieren" rewrites the snapshot but keeps the incidents it did not read (round 2, D2) with the
     * time they were read: such a snapshot is not fresh once those incidents are older than TTL. A snapshot without the
     * field counts as fresh.
     *
     * @param array<mixed> $snapshot
     */
    private static function incidentsFresh(array $snapshot): bool
    {
        $readAt = $snapshot['incidentsRefreshedAt'] ?? null;
        if (!is_string($readAt)) {
            return true;
        }
        $ts = strtotime($readAt . ' UTC');
        return $ts !== false && time() - $ts < self::TTL;
    }

    private function failed(int $clientId, string $reason): void
    {
        if ($this->cache->claim(self::retryKey($clientId), self::RETRY_SECONDS)) {
            Bridge::logActivity('Client area: could not refresh the monitoring status (next attempt in ' . self::RETRY_SECONDS . ' s): ' . $reason, $clientId);
        }
    }

    /**
     * @return array<string,mixed>|null null when the deadline ran out
     * @throws ApiException
     */
    private function refresh(Client $api, int $clientId, string $customerPublicId): ?array
    {
        $started = ($this->clock)();
        $types = [];
        foreach ($this->monitors->forCustomer($customerPublicId) as $row) {
            $types[(string) $row['monitor_type']] = true;
        }
        if ($types === []) {
            return SnapshotBuilder::build(new RemoteIndex(), [], $this->monitors, $this->customers, true, $clientId)['clients'][$clientId] ?? null;
        }
        $index = new RemoteIndex();
        $numericId = null;
        $calls = 0;
        foreach ([MonitorMap::TYPE_WEBSITE, MonitorMap::TYPE_CUSTOMER_DOMAIN, MonitorMap::TYPE_CUSTOMER_IP, MonitorMap::TYPE_DNS] as $type) {
            if (!isset($types[$type])) {
                continue;
            }
            if ($calls++ > 0 && $this->overDeadline($started)) {
                return null;
            }
            if ($type === MonitorMap::TYPE_DNS && $numericId === null) {
                $customer = $this->guard->run(fn (): array => $api->getCustomer($customerPublicId));
                $numericId = is_int($customer['id'] ?? null) ? $customer['id'] : null;
                if ($numericId === null) {
                    throw new ApiException('uptimeify API returned no numeric id for customer ' . $customerPublicId, 0, Client::INVALID_RESPONSE_CODE);
                }
                if ($this->overDeadline($started)) {
                    return null;
                }
            }
            $rows = $this->guard->run(fn (): array => match ($type) {
                MonitorMap::TYPE_WEBSITE => $api->listWebsites($customerPublicId),
                MonitorMap::TYPE_CUSTOMER_DOMAIN => $api->listCustomerDomains($customerPublicId),
                MonitorMap::TYPE_CUSTOMER_IP => $api->listCustomerIps($customerPublicId),
                default => $api->listDnsMonitors($numericId),
            });
            foreach ($rows as $monitor) {
                $index->addMonitor($type, $monitor);
                if ($numericId === null && RemoteIndex::customerIdOf($monitor) === $customerPublicId && is_int($monitor['customerId'] ?? null)) {
                    $numericId = $monitor['customerId'];
                }
            }
        }
        if ($calls > 0 && $this->overDeadline($started)) {
            return null;
        }
        $window = IncidentWindow::load($api, $this->guard);

        return SnapshotBuilder::build($index, $window['incidents'], $this->monitors, $this->customers, true, $clientId, $window['complete'])['clients'][$clientId] ?? null;
    }

    private function overDeadline(float $started): bool
    {
        return ($this->clock)() - $started > self::DEADLINE_SECONDS;
    }
}
