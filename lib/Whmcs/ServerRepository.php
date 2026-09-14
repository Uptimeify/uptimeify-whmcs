<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Uptimeify\Whmcs;

use WHMCS\Database\Capsule;

/** Read access to tblservers. WHMCS column names stay inside this class (spec §15.2). */
final class ServerRepository
{
    /** @return list<ServerRow> disabled=0 with a non-empty IP address (spec §8.1) */
    public function active(): array
    {
        $out = [];
        $rows = Capsule::table('tblservers')->select(['id', 'name', 'ipaddress'])->where('disabled', 0)->where('ipaddress', '<>', '')->orderBy('id')->get();
        foreach ($rows as $row) {
            $server = ServerRow::fromRow((array) $row);
            if ($server->ip !== '') {
                $out[] = $server;
            }
        }
        return $out;
    }
}
