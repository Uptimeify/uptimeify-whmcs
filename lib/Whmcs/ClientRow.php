<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Uptimeify\Whmcs;

final class ClientRow
{
    public function __construct(
        public readonly int $id,
        public readonly string $email,
        public readonly string $displayName,
        public readonly string $status,
    ) {
    }

    /**
     * Email is trimmed and lowercased (UTF-8); displayName is the company name, else "firstname lastname" (spec §8.2).
     *
     * @param array<string,mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $company = trim((string) ($row['companyname'] ?? ''));
        $person = trim(trim((string) ($row['firstname'] ?? '')) . ' ' . trim((string) ($row['lastname'] ?? '')));
        return new self(
            (int) $row['id'],
            mb_strtolower(trim((string) ($row['email'] ?? '')), 'UTF-8'),
            $company !== '' ? $company : $person,
            (string) ($row['status'] ?? 'Active'),
        );
    }
}
