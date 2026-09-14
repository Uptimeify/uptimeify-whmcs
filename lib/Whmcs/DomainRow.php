<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Uptimeify\Whmcs;

final class DomainRow
{
    public function __construct(
        public readonly int $id,
        public readonly int $clientId,
        public readonly string $domain,
        public readonly string $status,
        public readonly ?string $expiryDate,
        public readonly string $registrar,
    ) {
    }

    /**
     * expiryDate is Y-m-d or null (NULL, empty and MySQL's zero date '0000-00-00').
     *
     * @param array<string,mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $expiry = substr(trim((string) ($row['expirydate'] ?? '')), 0, 10);
        return new self(
            (int) $row['id'],
            (int) $row['userid'],
            trim((string) $row['domain']),
            (string) $row['status'],
            $expiry === '' || $expiry === '0000-00-00' ? null : $expiry,
            (string) ($row['registrar'] ?? ''),
        );
    }
}
