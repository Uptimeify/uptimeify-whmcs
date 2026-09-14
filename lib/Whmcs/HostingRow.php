<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Uptimeify\Whmcs;

final class HostingRow
{
    public function __construct(
        public readonly int $id,
        public readonly int $clientId,
        public readonly string $domain,
        public readonly string $status,
        public readonly int $serverId,
    ) {
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['userid'],
            trim((string) $row['domain']),
            (string) $row['domainstatus'],
            (int) ($row['server'] ?? 0),
        );
    }
}
