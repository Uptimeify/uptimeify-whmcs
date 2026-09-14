<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Uptimeify\Whmcs;

use WHMCS\Database\Capsule;

/** Read access to tbldomains. WHMCS column names stay inside this class (spec §15.2). */
final class DomainRepository
{
    private const COLUMNS = ['id', 'userid', 'domain', 'status', 'expirydate', 'registrar'];

    /** @return list<DomainRow> status='Active' with a non-empty domain (spec §8.1) */
    public function active(): array
    {
        $out = [];
        $rows = Capsule::table('tbldomains')->select(self::COLUMNS)->where('status', 'Active')->where('domain', '<>', '')->orderBy('id')->get();
        foreach ($rows as $row) {
            $domain = DomainRow::fromRow((array) $row);
            if ($domain->domain !== '') {
                $out[] = $domain;
            }
        }
        return $out;
    }

    public function find(int $id): ?DomainRow
    {
        $row = Capsule::table('tbldomains')->select(self::COLUMNS)->where('id', $id)->first();
        return $row === null ? null : DomainRow::fromRow((array) $row);
    }
}
