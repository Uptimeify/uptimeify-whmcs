<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Uptimeify\Sync;

use WHMCS\Module\Addon\Uptimeify\Api\Client;
use WHMCS\Module\Addon\Uptimeify\Store\MonitorMap;

/**
 * The remote actual state of one run (spec §8.3 step 2): customers indexed by `customFields.whmcs_client_id`,
 * email and name; monitors per type indexed by publicId and by (customer public id, normalized target).
 *
 * Field names per docs/api-surface.md: the customer public id of a monitor is `customerPublicId` on websites,
 * customer domains and DNS monitors and `customer.publicId` on customer IPs (the numeric `customerId` is not
 * a public id). Only customers and websites carry `customFields`. When several rows share a lookup key,
 * the one added first wins (lists are loaded oldest first), also after a row is re-added.
 *
 * It also carries what this load taught every run or queue job that shares it: map rows marked orphaned against it
 * (the orphan-first rule needs a second load, not a second job), quota hits per customer pool, and targets the API refused
 * with `invalidUrl` (tried once per load, round 2 review I1).
 */
final class RemoteIndex
{
    public const TYPES = [MonitorMap::TYPE_WEBSITE, MonitorMap::TYPE_CUSTOMER_DOMAIN, MonitorMap::TYPE_DNS, MonitorMap::TYPE_CUSTOMER_IP];

    private int $seq = 0;

    /** @var array<string,true> monitor_map keys ("kind:refId:type") marked orphaned against this load */
    private array $orphanedRows = [];
    /** @var array<string,string> quota pool key => error code hit against this load */
    private array $quotaHits = [];
    /** @var array<string,true> "rowKey|target" the API refused with `invalidUrl` against this load */
    private array $refusedTargets = [];

    /** @var array<string,array<string,mixed>> publicId => customer */
    private array $customers = [];
    /** @var array<string,int> publicId => insertion order */
    private array $customerSeq = [];
    /** @var array<int,array<string,int>> whmcs client id => publicId => order */
    private array $byWhmcsId = [];
    /** @var array<string,array<string,int>> */
    private array $byEmail = [];
    /** @var array<string,array<string,int>> */
    private array $byName = [];
    /** @var array<string,array<string,array<string,mixed>>> type => publicId => monitor */
    private array $monitors = [];
    /** @var array<string,array<string,int>> type => publicId => insertion order */
    private array $monitorSeq = [];
    /** @var array<string,array<string,array<string,array<string,int>>>> type => customerPublicId => target => publicId => order */
    private array $byTarget = [];
    /** @var array<string,array<string,array<string,int>>> type => customerPublicId => publicId => order */
    private array $byCustomer = [];

    /**
     * Loads all customers and the monitor lists of the given types only (a type not loaded is empty).
     *
     * @param list<string> $monitorTypes subset of self::TYPES
     */
    public static function load(Client $api, RateLimitGuard $guard, array $monitorTypes): self
    {
        return self::loadWithin($api, $guard, $monitorTypes, static fn (): bool => true) ?? throw new \LogicException('RemoteIndex::load stopped without a budget');
    }

    /**
     * load() with a time budget checked before every list (a list already walking is not cut off): null when
     * `$inBudget` says the budget is used up ("Status aktualisieren", round 2 D8).
     *
     * @param list<string> $monitorTypes subset of self::TYPES
     * @param \Closure():bool $inBudget
     */
    public static function loadWithin(Client $api, RateLimitGuard $guard, array $monitorTypes, \Closure $inBudget): ?self
    {
        foreach ($monitorTypes as $type) {
            self::assertType($type);
        }
        $index = new self();
        // null: the customer list, first.
        foreach ([null, ...array_unique($monitorTypes)] as $type) {
            if (!$inBudget()) {
                return null;
            }
            if ($type === null) {
                foreach ($guard->run(fn (): array => $api->listCustomers()) as $customer) {
                    $index->addCustomer($customer);
                }
                continue;
            }
            $rows = $guard->run(fn (): array => match ($type) {
                MonitorMap::TYPE_WEBSITE => $api->listWebsites(),
                MonitorMap::TYPE_CUSTOMER_DOMAIN => $api->listCustomerDomains(),
                MonitorMap::TYPE_DNS => $api->listDnsMonitors(),
                MonitorMap::TYPE_CUSTOMER_IP => $api->listCustomerIps(),
                default => throw new \InvalidArgumentException('Unknown monitor type: ' . $type),
            });
            foreach ($rows as $monitor) {
                $index->addMonitor($type, $monitor);
            }
        }
        return $index;
    }

    // ---- customers ----------------------------------------------------------------

    /**
     * Adds or replaces a customer (by publicId); a replaced customer loses its old lookup keys.
     *
     * @param array<string,mixed> $customer
     */
    public function addCustomer(array $customer): void
    {
        $publicId = self::stringOf($customer['publicId'] ?? null);
        if ($publicId === '') {
            return;
        }
        $order = $this->customerSeq[$publicId] ?? $this->seq++;
        $this->unindexCustomer($publicId);
        $this->customers[$publicId] = $customer;
        $this->customerSeq[$publicId] = $order;
        $whmcsId = self::whmcsIdOf($customer);
        if ($whmcsId !== null) {
            $this->byWhmcsId[$whmcsId][$publicId] = $order;
        }
        $email = self::emailKey(self::stringOf($customer['email'] ?? null));
        if ($email !== '') {
            $this->byEmail[$email][$publicId] = $order;
        }
        $name = self::nameKey(self::stringOf($customer['name'] ?? null));
        if ($name !== '') {
            $this->byName[$name][$publicId] = $order;
        }
    }

    /**
     * `customFields.whmcs_client_id` as a positive int; the API stores it as string (docs/api-surface.md §3),
     * an int is accepted too. Anything else (missing, "4.5", "abc") is null.
     *
     * @param array<string,mixed> $customer
     */
    public static function whmcsIdOf(array $customer): ?int
    {
        $fields = $customer['customFields'] ?? null;
        $value = is_array($fields) ? ($fields['whmcs_client_id'] ?? null) : null;
        if (is_string($value) && preg_match('/^\d{1,18}$/', trim($value)) === 1) {
            $value = (int) trim($value);
        }
        return is_int($value) && $value > 0 ? $value : null;
    }

    /** @return array<string,mixed>|null */
    public function customerByWhmcsId(int $id): ?array
    {
        return $this->ordered($this->byWhmcsId[$id] ?? [])[0] ?? null;
    }

    /**
     * @param int|null $forClientId when set, customers tagged with another WHMCS client id are skipped
     * @return array<string,mixed>|null
     */
    public function customerByEmail(string $email, ?int $forClientId = null): ?array
    {
        return self::firstFor($this->customersByEmail($email), $forClientId);
    }

    /** @return list<array<string,mixed>> every customer with that email (trimmed, lowercased), first loaded first */
    public function customersByEmail(string $email): array
    {
        $key = self::emailKey($email);
        return $key === '' ? [] : $this->ordered($this->byEmail[$key] ?? []);
    }

    /**
     * @param int|null $forClientId when set, customers tagged with another WHMCS client id are skipped
     * @return array<string,mixed>|null
     */
    public function customerByName(string $name, ?int $forClientId = null): ?array
    {
        return self::firstFor($this->customersByName($name), $forClientId);
    }

    /** @return list<array<string,mixed>> every customer with that name (trimmed, lowercased), first loaded first */
    public function customersByName(string $name): array
    {
        $key = self::nameKey($name);
        return $key === '' ? [] : $this->ordered($this->byName[$key] ?? []);
    }

    /** @return array<string,mixed>|null */
    public function customerByPublicId(string $publicId): ?array
    {
        return $this->customers[$publicId] ?? null;
    }

    /** @return list<array<string,mixed>> */
    public function customers(): array
    {
        return array_values($this->customers);
    }

    // ---- monitors -----------------------------------------------------------------

    /**
     * Adds or replaces a monitor (by publicId). A row without a customer public id (e.g. a create response,
     * which only has the numeric `customerId`) is kept by publicId but not matched by target: add
     * `customerPublicId` to such a row first.
     *
     * @param array<string,mixed> $monitor
     */
    public function addMonitor(string $type, array $monitor): void
    {
        self::assertType($type);
        $publicId = self::stringOf($monitor['publicId'] ?? null);
        if ($publicId === '') {
            return;
        }
        $order = $this->monitorSeq[$type][$publicId] ?? $this->seq++;
        $this->removeMonitor($type, $publicId);
        $this->monitors[$type][$publicId] = $monitor;
        $this->monitorSeq[$type][$publicId] = $order;
        $customer = self::customerIdOf($monitor);
        $target = self::targetOf($type, $monitor);
        if ($customer !== '') {
            $this->byCustomer[$type][$customer][$publicId] = $order;
        }
        if ($customer !== '' && $target !== '') {
            $this->byTarget[$type][$customer][$target][$publicId] = $order;
        }
    }

    public function removeMonitor(string $type, string $publicId): void
    {
        $monitor = $this->monitors[$type][$publicId] ?? null;
        if ($monitor === null) {
            return;
        }
        $customer = self::customerIdOf($monitor);
        $target = self::targetOf($type, $monitor);
        unset(
            $this->monitors[$type][$publicId],
            $this->monitorSeq[$type][$publicId],
            $this->byCustomer[$type][$customer][$publicId],
            $this->byTarget[$type][$customer][$target][$publicId],
        );
        if (($this->byTarget[$type][$customer][$target] ?? null) === []) {
            unset($this->byTarget[$type][$customer][$target]);
        }
    }

    /**
     * @param string $target normalized like the planner (TargetNormalizer::host)
     * @return array<string,mixed>|null the first monitor of that customer for the target
     */
    public function monitor(string $type, string $customerPublicId, string $target): ?array
    {
        $bucket = $this->byTarget[$type][$customerPublicId][$target] ?? [];
        if ($bucket === []) {
            return null;
        }
        asort($bucket);
        return $this->monitors[$type][(string) array_key_first($bucket)];
    }

    /** @return array<string,mixed>|null */
    public function monitorByPublicId(string $type, string $publicId): ?array
    {
        return $this->monitors[$type][$publicId] ?? null;
    }

    /** @return list<array<string,mixed>> the customer's monitors in insertion order */
    public function monitorsFor(string $type, string $customerPublicId): array
    {
        $order = $this->byCustomer[$type][$customerPublicId] ?? [];
        asort($order);
        $out = [];
        foreach (array_keys($order) as $publicId) {
            $out[] = $this->monitors[$type][(string) $publicId];
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    public function allMonitors(string $type): array
    {
        return array_values($this->monitors[$type] ?? []);
    }

    /**
     * Normalized target: website `url`, customer domain `domainName`, DNS `hostname`, customer IP `ipAddress`,
     * each through TargetNormalizer::host (the planner normalizes server IPs the same way).
     *
     * @param array<string,mixed> $monitor
     */
    public static function targetOf(string $type, array $monitor): string
    {
        $field = match ($type) {
            MonitorMap::TYPE_WEBSITE => 'url',
            MonitorMap::TYPE_CUSTOMER_DOMAIN => 'domainName',
            MonitorMap::TYPE_DNS => 'hostname',
            MonitorMap::TYPE_CUSTOMER_IP => 'ipAddress',
            default => null,
        };
        return $field === null ? '' : TargetNormalizer::host(self::stringOf($monitor[$field] ?? null));
    }

    /**
     * Customer public id of a monitor row: `customerPublicId` (websites, customer domains, DNS monitors) or
     * `customer.publicId` (customer IPs); '' when neither is present.
     *
     * @param array<string,mixed> $monitor
     */
    public static function customerIdOf(array $monitor): string
    {
        $id = self::stringOf($monitor['customerPublicId'] ?? null);
        if ($id !== '') {
            return $id;
        }
        $customer = $monitor['customer'] ?? null;
        return is_array($customer) ? self::stringOf($customer['publicId'] ?? null) : '';
    }

    // ---- state learned against this load ----------------------------------------

    /** Remembers that a map row was marked orphaned because its monitor is missing from this load. */
    public function markRowOrphaned(string $rowKey): void
    {
        $this->orphanedRows[$rowKey] = true;
    }

    /** True when the row was marked orphaned against this load: it may only be removed after the next load. */
    public function rowOrphanedInThisLoad(string $rowKey): bool
    {
        return isset($this->orphanedRows[$rowKey]);
    }

    public function rememberQuotaHit(string $poolKey, string $code): void
    {
        $this->quotaHits[$poolKey] = $code;
    }

    /** The quota error code hit for the pool against this load, null when none. */
    public function quotaHit(string $poolKey): ?string
    {
        return $this->quotaHits[$poolKey] ?? null;
    }

    /** Remembers that the API refused the target of a map row with `invalidUrl` against this load. */
    public function markTargetRefused(string $rowKey, string $target): void
    {
        $this->refusedTargets[$rowKey . '|' . $target] = true;
    }

    /** True when the row's target was already sent and refused against this load: it is not sent again before the next load. */
    public function targetRefusedInThisLoad(string $rowKey, string $target): bool
    {
        return isset($this->refusedTargets[$rowKey . '|' . $target]);
    }

    // ---- internals ----------------------------------------------------------------

    private static function assertType(string $type): void
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Unknown monitor type: ' . $type);
        }
    }

    private function unindexCustomer(string $publicId): void
    {
        $old = $this->customers[$publicId] ?? null;
        if ($old === null) {
            return;
        }
        $whmcsId = self::whmcsIdOf($old);
        if ($whmcsId !== null) {
            unset($this->byWhmcsId[$whmcsId][$publicId]);
        }
        unset(
            $this->byEmail[self::emailKey(self::stringOf($old['email'] ?? null))][$publicId],
            $this->byName[self::nameKey(self::stringOf($old['name'] ?? null))][$publicId],
        );
    }

    /**
     * @param array<string,int> $bucket publicId => order
     * @return list<array<string,mixed>>
     */
    private function ordered(array $bucket): array
    {
        asort($bucket);
        $out = [];
        foreach (array_keys($bucket) as $publicId) {
            $out[] = $this->customers[(string) $publicId];
        }
        return $out;
    }

    /**
     * @param list<array<string,mixed>> $customers
     * @return array<string,mixed>|null
     */
    private static function firstFor(array $customers, ?int $forClientId): ?array
    {
        foreach ($customers as $customer) {
            $tagged = self::whmcsIdOf($customer);
            if ($forClientId === null || $tagged === null || $tagged === $forClientId) {
                return $customer;
            }
        }
        return null;
    }

    private static function emailKey(string $email): string
    {
        return mb_strtolower(trim($email), 'UTF-8');
    }

    private static function nameKey(string $name): string
    {
        return mb_strtolower(trim($name), 'UTF-8');
    }

    private static function stringOf(mixed $value): string
    {
        return is_string($value) || is_int($value) ? (string) $value : '';
    }
}
