<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Uptimeify\Whmcs;

use Illuminate\Database\Query\Builder;
use WHMCS\Database\Capsule;

/**
 * Read access to tblhosting. WHMCS column names stay inside this class (spec §15.2).
 *
 * "Active" means domainstatus='Active' and a non-empty domain (spec §8.1) for websites and domain expiry; the server IP
 * only needs domainstatus='Active' (round 2, D3).
 */
final class HostingRepository
{
    private const COLUMNS = ['id', 'userid', 'domain', 'domainstatus', 'server'];

    /** @return list<HostingRow> */
    public function active(): array
    {
        $out = [];
        foreach ($this->activeQuery()->select(self::COLUMNS)->orderBy('id')->get() as $row) {
            $hosting = HostingRow::fromRow((array) $row);
            if ($hosting->domain !== '') {
                $out[] = $hosting;
            }
        }
        return $out;
    }

    public function find(int $id): ?HostingRow
    {
        $row = Capsule::table('tblhosting')->select(self::COLUMNS)->where('id', $id)->first();
        return $row === null ? null : HostingRow::fromRow((array) $row);
    }

    /**
     * Distinct client ids with at least one Active hosting on the server, ascending. The domain does not matter: a VPS or
     * dedicated server without a domain still uses the server's IP (round 2, D3).
     *
     * @return list<int>
     */
    public function activeClientIdsForServer(int $serverId): array
    {
        $ids = Capsule::table('tblhosting')->where('domainstatus', 'Active')->where('server', $serverId)->distinct()->orderBy('userid')->pluck('userid')->all();
        return array_values(array_map(static fn (mixed $id): int => (int) $id, $ids));
    }

    /** Whether the client has at least one hosting with that status on the server (any domain). */
    public function clientHasHostingOnServer(int $clientId, int $serverId, string $status): bool
    {
        return Capsule::table('tblhosting')->where('userid', $clientId)->where('server', $serverId)->where('domainstatus', $status)->exists();
    }

    private function activeQuery(): Builder
    {
        return Capsule::table('tblhosting')->where('domainstatus', 'Active')->where('domain', '<>', '');
    }
}
