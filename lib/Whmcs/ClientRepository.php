<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Uptimeify\Whmcs;

use WHMCS\Database\Capsule;

/** Read access to tblclients. WHMCS column names stay inside this class (spec §15.2). */
final class ClientRepository
{
    private const COLUMNS = ['id', 'firstname', 'lastname', 'companyname', 'email', 'status'];

    /** @return array<int,ClientRow> keyed by client id */
    public function all(): array
    {
        $out = [];
        foreach (Capsule::table('tblclients')->select(self::COLUMNS)->orderBy('id')->get() as $row) {
            $client = ClientRow::fromRow((array) $row);
            $out[$client->id] = $client;
        }
        return $out;
    }

    public function find(int $id): ?ClientRow
    {
        $row = Capsule::table('tblclients')->select(self::COLUMNS)->where('id', $id)->first();
        return $row === null ? null : ClientRow::fromRow((array) $row);
    }
}
