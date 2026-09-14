<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Uptimeify\Whmcs;

final class ServerRow
{
    public function __construct(public readonly int $id, public readonly string $name, public readonly string $ip)
    {
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self((int) $row['id'], (string) $row['name'], trim((string) $row['ipaddress']));
    }
}
