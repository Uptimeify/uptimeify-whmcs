<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Uptimeify\Sync;

use WHMCS\Module\Addon\Uptimeify\Api\Client;
use WHMCS\Module\Addon\Uptimeify\Api\Exception\ApiException;

/**
 * The one bounded incident read (round 2, D2) shared by the reconcile, the queue worker's cache refresh and the
 * client-area snapshot.
 *
 * `GET /api/incidents` has no status or customer filter (re-verified in `api/incidents.get.ts`: only `limit`, `offset`,
 * `includeTotal`, `source`, ordered by `startedAt` desc), so an open incident can only be found by walking. The walk is
 * capped at the newest PAGES pages of 500; when older incidents stay unread the snapshots carry
 * `incidentsComplete=false` and the UI says the open incidents may be incomplete. Nothing older is carried over from an
 * earlier snapshot: with no full walk anywhere, a carried-over incident could never be seen resolved.
 *
 * Budgeted admin requests never walk: they rebuild the open incidents from the stored snapshots (fromSnapshot()). The
 * incidents are read again by the next queue worker run that handled jobs, by the daily reconcile, or, for one client's
 * snapshot, by the client-area refresh once its incidents are older than ClientSnapshot::TTL.
 */
final class IncidentWindow
{
    /** Pages of 500 incidents read at most (newest first). */
    public const PAGES = 2;

    /**
     * @return array{incidents: list<array<string,mixed>>, complete: bool} complete is false when older incidents were not read
     * @throws ApiException
     */
    public static function load(Client $api, RateLimitGuard $guard): array
    {
        return $guard->run(static fn (): array => $api->listRecentIncidents(self::PAGES));
    }

    /**
     * The open incidents a stored client snapshot holds, back in the API shape SnapshotBuilder reads.
     *
     * @param array<mixed>|null $snapshot a `client:<id>` snapshot
     * @return list<array<string,mixed>>
     */
    public static function fromSnapshot(?array $snapshot): array
    {
        $out = [];
        foreach (is_array($snapshot['incidents'] ?? null) ? $snapshot['incidents'] : [] as $incident) {
            if (!is_array($incident) || !is_string($incident['publicId'] ?? null)) {
                continue;
            }
            $monitor = ['publicId' => $incident['monitorPublicId'] ?? '', 'name' => $incident['monitorName'] ?? ''];
            $out[] = [
                'publicId' => $incident['publicId'],
                'title' => $incident['title'] ?? '',
                'status' => $incident['status'] ?? '',
                'started_at' => $incident['startedAt'] ?? '',
                'resolved_at' => null,
                'website' => $monitor,
                'customer' => ['publicId' => $incident['customerPublicId'] ?? ''],
            ];
        }
        return $out;
    }
}
