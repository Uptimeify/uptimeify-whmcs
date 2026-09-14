<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Uptimeify\Sync;

use WHMCS\Module\Addon\Uptimeify\Store\CustomerMap;
use WHMCS\Module\Addon\Uptimeify\Store\MonitorMap;
use WHMCS\Module\Addon\Uptimeify\Store\Schema;

/**
 * Builds the status cache snapshots (spec §8.3 step 9, §9.3) from one RemoteIndex and one incident list, without
 * any API call.
 *
 * A client snapshot lists the monitor_map rows of the client's mapped customer, once per remote monitor, and only
 * when the remote monitor really belongs to that customer (spec §10: never show another customer's monitor, even
 * if a map row is stale). Field names per docs/api-surface.md: uptime values arrive as strings, avg response as
 * int ms, domain expiry under `expiry`, DNSBL state under `dnsbl`, incident start as `started_at`.
 */
final class SnapshotBuilder
{
    /** Remote statuses of a monitor that is not being checked. */
    private const PAUSED_STATUSES = ['inactive', 'paused', 'disabled'];

    /**
     * An incident is open while `status` is `open` or `acknowledged` and `resolved_at` is null (how the dashboard
     * counts it, docs/api-surface.md GET /api/incidents).
     */
    private const OPEN_INCIDENT_STATUSES = ['open', 'acknowledged'];

    /**
     * @param list<array<string,mixed>> $incidents
     * @param bool $incidentsAvailable false when the incident list could not be loaded (counts are then 0, not "no incidents")
     * @param int|null $onlyClientId only this client's snapshot (the client-area refresh); `org` then counts that client only
     * @param bool $incidentsComplete false when the incident list is only the newest window (IncidentWindow, round 2 D2)
     * @param string|null $incidentsRefreshedAt when the incidents were read (UTC 'Y-m-d H:i:s'); null: now
     * @return array{org: array{total:int,up:int,down:int,paused:int,incidents:int,incidentsAvailable:bool,incidentsComplete:bool,incidentsRefreshedAt:string,refreshedAt:string}, clients: array<int, array{customerPublicId:string,totals:array{total:int,up:int,down:int,paused:int},monitors:list<array<string,mixed>>,incidents:list<array<string,mixed>>,incidentsAvailable:bool,incidentsComplete:bool,incidentsRefreshedAt:string,refreshedAt:string}>}
     */
    public static function build(RemoteIndex $index, array $incidents, MonitorMap $map, CustomerMap $customers, bool $incidentsAvailable = true, ?int $onlyClientId = null, bool $incidentsComplete = true, ?string $incidentsRefreshedAt = null): array
    {
        $refreshedAt = Schema::now();
        $incidentsRefreshedAt ??= $refreshedAt;
        [$openByMonitor, $openByCustomer] = self::openIncidents($incidents);

        $org = ['total' => 0, 'up' => 0, 'down' => 0, 'paused' => 0, 'incidents' => 0, 'incidentsAvailable' => $incidentsAvailable, 'incidentsComplete' => $incidentsComplete, 'incidentsRefreshedAt' => $incidentsRefreshedAt, 'refreshedAt' => $refreshedAt];
        $clients = [];
        foreach ($customers->all() as $clientId => $mapping) {
            if ($onlyClientId !== null && $clientId !== $onlyClientId) {
                continue;
            }
            $customerPublicId = $mapping['customer_public_id'];
            $monitors = self::monitors($index, $map, $customerPublicId, $openByMonitor);
            $totals = ['total' => count($monitors), 'up' => 0, 'down' => 0, 'paused' => 0];
            foreach ($monitors as $monitor) {
                if (in_array($monitor['status'], ['up', 'down', 'paused'], true)) {
                    $totals[$monitor['status']]++;
                }
            }
            $clientIncidents = $openByCustomer[$customerPublicId] ?? [];
            $clients[$clientId] = [
                'customerPublicId' => $customerPublicId,
                'totals' => $totals,
                'monitors' => $monitors,
                'incidents' => $clientIncidents,
                'incidentsAvailable' => $incidentsAvailable,
                'incidentsComplete' => $incidentsComplete,
                'incidentsRefreshedAt' => $incidentsRefreshedAt,
                'refreshedAt' => $refreshedAt,
            ];
            $org['total'] += $totals['total'];
            $org['up'] += $totals['up'];
            $org['down'] += $totals['down'];
            $org['paused'] += $totals['paused'];
            $org['incidents'] += count($clientIncidents);
        }
        return ['org' => $org, 'clients' => $clients];
    }

    /**
     * website / dns: paused when `status` is inactive/paused/disabled, down when `isDown`, else up.
     * customer_domain: paused when disabled, down when `expiry.isExpired`, else up.
     * customer_ip: paused when disabled, down when `dnsbl.isListed`, else up.
     *
     * @param array<string,mixed> $monitor
     */
    public static function monitorStatus(string $type, array $monitor): string
    {
        if (in_array($monitor['status'] ?? null, self::PAUSED_STATUSES, true)) {
            return 'paused';
        }
        $down = match ($type) {
            MonitorMap::TYPE_CUSTOMER_DOMAIN => self::nested($monitor, 'expiry', 'isExpired') === true,
            MonitorMap::TYPE_CUSTOMER_IP => self::nested($monitor, 'dnsbl', 'isListed') === true,
            default => ($monitor['isDown'] ?? null) === true,
        };
        return $down ? 'down' : 'up';
    }

    /**
     * @param list<array<string,mixed>> $incidents
     * @return array{0: array<string,int>, 1: array<string, list<array<string,mixed>>>} open count per monitor public id, open incidents per customer public id
     */
    private static function openIncidents(array $incidents): array
    {
        $byMonitor = [];
        $byCustomer = [];
        foreach ($incidents as $incident) {
            if (!in_array($incident['status'] ?? null, self::OPEN_INCIDENT_STATUSES, true) || ($incident['resolved_at'] ?? null) !== null) {
                continue;
            }
            $monitorId = self::string(self::nested($incident, 'website', 'publicId'));
            $monitorName = self::nested($incident, 'website', 'name');
            if ($monitorId === '') {
                $monitorId = self::string(self::nested($incident, 'monitor', 'publicId'));
                $monitorName = self::nested($incident, 'monitor', 'name');
            }
            if ($monitorId !== '') {
                $byMonitor[$monitorId] = ($byMonitor[$monitorId] ?? 0) + 1;
            }
            $customerId = self::string(self::nested($incident, 'customer', 'publicId'));
            if ($customerId === '') {
                continue;
            }
            $byCustomer[$customerId][] = [
                'publicId' => self::string($incident['publicId'] ?? null),
                'title' => self::string($incident['title'] ?? null),
                'status' => self::string($incident['status'] ?? null),
                'startedAt' => self::string($incident['started_at'] ?? null),
                'monitorPublicId' => $monitorId,
                'monitorName' => self::string($monitorName),
                'customerPublicId' => $customerId,
            ];
        }
        return [$byMonitor, $byCustomer];
    }

    /**
     * @param array<string,int> $openByMonitor
     * @return list<array{type:string,name:string,target:string,publicId:string,status:string,uptimeDay:?float,uptimeMonth:?float,uptimeYear:?float,avgResponseDay:?float,expiresAt:?string,registrar:?string,openIncidents:int}>
     */
    private static function monitors(RemoteIndex $index, MonitorMap $map, string $customerPublicId, array $openByMonitor): array
    {
        $entries = [];
        foreach ($map->forCustomer($customerPublicId) as $row) {
            $type = (string) $row['monitor_type'];
            $publicId = $row['monitor_public_id'] === null ? '' : (string) $row['monitor_public_id'];
            $state = (string) $row['state'];
            if ($publicId === '' || $state === MonitorMap::STATE_ORPHANED || isset($entries[$type . '|' . $publicId])) {
                continue;
            }
            $remote = $index->monitorByPublicId($type, $publicId);
            if ($remote === null && !in_array($state, [MonitorMap::STATE_ACTIVE, MonitorMap::STATE_PAUSED], true)) {
                continue;
            }
            $owner = $remote === null ? '' : RemoteIndex::customerIdOf($remote);
            if ($owner !== '' && $owner !== $customerPublicId) {
                continue;
            }
            $isDomain = $type === MonitorMap::TYPE_CUSTOMER_DOMAIN;
            $entries[$type . '|' . $publicId] = [
                'type' => $type,
                'name' => self::string($remote['name'] ?? $remote['label'] ?? null) ?: (string) $row['target'],
                'target' => (string) $row['target'],
                'publicId' => $publicId,
                'status' => $remote === null ? 'unknown' : self::monitorStatus($type, $remote),
                'uptimeDay' => self::floatOrNull($remote['uptimeDay'] ?? null),
                'uptimeMonth' => self::floatOrNull($remote['uptimeMonth'] ?? null),
                'uptimeYear' => self::floatOrNull($remote['uptimeYear'] ?? null),
                'avgResponseDay' => self::floatOrNull($remote['avgResponseDay'] ?? null),
                'expiresAt' => $isDomain && $remote !== null ? self::stringOrNull(self::nested($remote, 'expiry', 'domainExpiresAt')) : null,
                'registrar' => $isDomain && $remote !== null ? self::stringOrNull(self::nested($remote, 'expiry', 'domainRegistrar')) : null,
                'openIncidents' => $openByMonitor[$publicId] ?? 0,
            ];
        }
        $entries = array_values($entries);
        usort($entries, fn (array $a, array $b): int => [$a['target'], $a['type'], $a['publicId']] <=> [$b['target'], $b['type'], $b['publicId']]);
        return $entries;
    }

    /** @param array<string,mixed> $data */
    private static function nested(array $data, string $key, string $field): mixed
    {
        $inner = $data[$key] ?? null;
        return is_array($inner) ? ($inner[$field] ?? null) : null;
    }

    private static function floatOrNull(mixed $value): ?float
    {
        return (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) ? (float) $value : null;
    }

    private static function string(mixed $value): string
    {
        return is_string($value) || is_int($value) ? (string) $value : '';
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
