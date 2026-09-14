<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Uptimeify\Sync;

/**
 * One desired Uptimeify monitor for a WHMCS object (spec §8.1), keyed like a monitor_map row (spec §6).
 *
 * `target` is the normalized host (website, customer domain, DNS) or the normalized IP (customer IP).
 * `extra` per type: website `url`, `monitoringType`, `checkInterval`, `customFields`; customer domain and
 * customer IP `label` (IP also `serverId`); DNS `dnsConfig`.
 */
final class PlannedMonitor
{
    /** @param array<string,mixed> $extra */
    public function __construct(
        public readonly string $kind,
        public readonly int $refId,
        public readonly int $clientId,
        public readonly string $monitorType,
        public readonly string $target,
        public readonly string $name,
        public readonly array $extra = [],
    ) {
    }

    /** "kind:refId:monitorType", the same triple as the monitor_map primary key. */
    public function key(): string
    {
        return $this->kind . ':' . $this->refId . ':' . $this->monitorType;
    }
}
