<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Uptimeify\Sync;

use WHMCS\Module\Addon\Uptimeify\Settings;
use WHMCS\Module\Addon\Uptimeify\Store\MonitorMap;
use WHMCS\Module\Addon\Uptimeify\Whmcs\ClientRepository;
use WHMCS\Module\Addon\Uptimeify\Whmcs\DomainRepository;
use WHMCS\Module\Addon\Uptimeify\Whmcs\DomainRow;
use WHMCS\Module\Addon\Uptimeify\Whmcs\HostingRepository;
use WHMCS\Module\Addon\Uptimeify\Whmcs\HostingRow;
use WHMCS\Module\Addon\Uptimeify\Whmcs\ServerRepository;

/**
 * Builds the desired monitor set from WHMCS (spec §8.1). Each type only when its switch is on; the customer
 * filter and ignored_targets are applied before a plan is created.
 */
final class MonitorPlanner
{
    /**
     * Alert when the domain does not resolve. The spec's `matchMode:'any'` / `triggerOn:'no_answer'` is rejected
     * by the API (`lib/dns-monitor-config.ts`): every rrtype needs an expected value, so `contains '.'` with
     * `mismatch:false` disables value comparison and only resolve errors (no answer, NXDOMAIN, SERVFAIL,
     * timeout) alert. See docs/api-surface.md, POST /api/dns-monitors.
     */
    public const DNS_CONFIG = [
        'rrtypes' => ['A', 'NS'],
        'matchMode' => 'contains',
        'expectedValues' => ['A' => ['.'], 'NS' => ['.']],
        'triggerOn' => ['resolveError' => true, 'mismatch' => false],
    ];

    /** Server-IP refId = serverId * factor + clientId; unique for client ids below the factor (BIGINT column). */
    public const SERVER_IP_REF_FACTOR = 10_000_000_000;

    /** Why configuration keeps a monitor out of the plan that the WHMCS state still wants (configExclusion()). */
    public const EXCLUDED_TYPE_DISABLED = 'type_disabled';
    public const EXCLUDED_CLIENT_FILTER = 'client_filter';
    public const EXCLUDED_IGNORED_TARGET = 'ignored_target';
    public const EXCLUDED_SERVER_UNAVAILABLE = 'server_unavailable';

    /**
     * Statuses of an object that still exists and has not ended: the one source for configExclusion() and the Reconciler's
     * retirement rules (a live hosting's website is paused, not ended; a pending registrar domain keeps its monitors).
     */
    public const LIVE_HOSTING_STATUSES = ['Active', 'Suspended', 'Pending'];
    public const PENDING_DOMAIN_STATUSES = ['Pending', 'Pending Registration', 'Pending Transfer', 'Grace', 'Redemption'];
    public const LIVE_DOMAIN_STATUSES = ['Active', ...self::PENDING_DOMAIN_STATUSES];

    /** "kind:monitorType" => the Settings::typeEnabled() switch that plans it. */
    private const TYPE_SWITCHES = [
        MonitorMap::KIND_HOSTING . ':' . MonitorMap::TYPE_WEBSITE => 'hosting_website',
        MonitorMap::KIND_HOSTING . ':' . MonitorMap::TYPE_CUSTOMER_DOMAIN => 'hosting_domain_expiry',
        MonitorMap::KIND_DOMAIN . ':' . MonitorMap::TYPE_CUSTOMER_DOMAIN => 'domain_expiry',
        MonitorMap::KIND_DOMAIN . ':' . MonitorMap::TYPE_DNS => 'domain_dns',
        MonitorMap::KIND_SERVER_IP . ':' . MonitorMap::TYPE_CUSTOMER_IP => 'server_ips',
    ];

    public function __construct(
        private readonly Settings $settings,
        private readonly CustomerFilter $filter,
        // @phpstan-ignore property.onlyWritten (constructor signature is shared by Reconciler, JobHandler and Container in Tasks 10-12)
        private readonly ClientRepository $clients,
        private readonly HostingRepository $hostings,
        private readonly DomainRepository $domains,
        private readonly ServerRepository $servers,
    ) {
    }

    public static function serverIpRefId(int $serverId, int $clientId): int
    {
        if ($serverId < 0 || $clientId < 0 || $clientId >= self::SERVER_IP_REF_FACTOR
            || $serverId > intdiv(PHP_INT_MAX - $clientId, self::SERVER_IP_REF_FACTOR)) {
            throw new \InvalidArgumentException("server-IP refId out of range (server {$serverId}, client {$clientId})");
        }
        return $serverId * self::SERVER_IP_REF_FACTOR + $clientId;
    }

    /** @return array{serverId:int, clientId:int} */
    public static function decodeServerIpRefId(int $refId): array
    {
        return ['serverId' => intdiv($refId, self::SERVER_IP_REF_FACTOR), 'clientId' => $refId % self::SERVER_IP_REF_FACTOR];
    }

    /** @return list<PlannedMonitor> hostings, then domains, then server IPs, each ascending by id */
    public function planAll(): array
    {
        $plans = [];
        foreach ($this->hostings->active() as $h) {
            array_push($plans, ...$this->fromHosting($h));
        }
        foreach ($this->domains->active() as $d) {
            array_push($plans, ...$this->fromDomain($d));
        }
        array_push($plans, ...$this->serverIps(null, null));
        return $plans;
    }

    /**
     * The hosting's plans plus the server IP of its server for its client. A hosting without a domain (VPS, dedicated)
     * has no website or domain-expiry plan but still its server IP (round 2, D3).
     *
     * @return list<PlannedMonitor>
     */
    public function planForHosting(int $hostingId): array
    {
        $h = $this->hostings->find($hostingId);
        if ($h === null || $h->status !== 'Active') {
            return [];
        }
        return [...$this->fromHosting($h), ...$this->serverIps($h->clientId, $h->serverId)];
    }

    /**
     * Why the configuration keeps the monitor of a map row out of the plan while its WHMCS object still exists and has
     * not ended: its type switch is off, the customer filter leaves the client out, its target is ignored, or (server IP)
     * the server is disabled, gone or has no IP. The object only has to be live, not Active (hosting Active, Suspended or
     * Pending; domain Active or pending/grace/redemption; an Active or Suspended hosting of the client on the server), so
     * a suspended object that the configuration excludes is detached directly instead of being paused first.
     * Null when the object is gone, ended or has no target, or nothing in the configuration excludes it. Whether the
     * client still exists and is not Closed is the caller's question (spec §8.5). Round 2, D1: such a row is detached,
     * never deleted.
     */
    public function configExclusion(string $kind, int $refId, string $monitorType): ?string
    {
        $switch = self::TYPE_SWITCHES[$kind . ':' . $monitorType] ?? null;
        if ($switch === null) {
            return null;
        }
        $server = null;
        if ($kind === MonitorMap::KIND_HOSTING) {
            $hosting = $this->hostings->find($refId);
            if ($hosting === null || !in_array($hosting->status, self::LIVE_HOSTING_STATUSES, true)) {
                return null;
            }
            [$clientId, $target] = [$hosting->clientId, TargetNormalizer::host($hosting->domain)];
        } elseif ($kind === MonitorMap::KIND_DOMAIN) {
            $domain = $this->domains->find($refId);
            if ($domain === null || !in_array($domain->status, self::LIVE_DOMAIN_STATUSES, true)) {
                return null;
            }
            [$clientId, $target] = [$domain->clientId, TargetNormalizer::host($domain->domain)];
        } else {
            ['serverId' => $serverId, 'clientId' => $clientId] = self::decodeServerIpRefId($refId);
            if (!$this->hostings->clientHasHostingOnServer($clientId, $serverId, 'Active')
                && !$this->hostings->clientHasHostingOnServer($clientId, $serverId, 'Suspended')) {
                return null;
            }
            foreach ($this->servers->active() as $candidate) {
                $server = $candidate->id === $serverId ? $candidate : $server;
            }
            $target = $server === null ? '' : TargetNormalizer::host($server->ip);
        }
        if ($kind !== MonitorMap::KIND_SERVER_IP && $target === '') {
            return null;
        }
        if (!$this->settings->typeEnabled($switch)) {
            return self::EXCLUDED_TYPE_DISABLED;
        }
        if (!$this->filter->allows($clientId)) {
            return self::EXCLUDED_CLIENT_FILTER;
        }
        if ($target !== '' && $this->filter->isIgnoredTarget($target)) {
            return self::EXCLUDED_IGNORED_TARGET;
        }
        return $kind === MonitorMap::KIND_SERVER_IP && $target === '' ? self::EXCLUDED_SERVER_UNAVAILABLE : null;
    }

    /** @return list<PlannedMonitor> */
    public function planForDomain(int $domainId): array
    {
        $d = $this->domains->find($domainId);
        if ($d === null || $d->status !== 'Active' || $d->domain === '') {
            return [];
        }
        return $this->fromDomain($d);
    }

    /** @return list<PlannedMonitor> */
    public function planForClient(int $clientId): array
    {
        return array_values(array_filter($this->planAll(), fn (PlannedMonitor $p): bool => $p->clientId === $clientId));
    }

    /** @return list<PlannedMonitor> */
    private function fromHosting(HostingRow $h): array
    {
        $host = TargetNormalizer::host($h->domain);
        if ($host === '' || !$this->filter->allows($h->clientId) || $this->filter->isIgnoredTarget($host)) {
            return [];
        }
        $plans = [];
        if ($this->settings->typeEnabled('hosting_website')) {
            $plans[] = new PlannedMonitor(MonitorMap::KIND_HOSTING, $h->id, $h->clientId, MonitorMap::TYPE_WEBSITE, $host, $host, [
                'url' => 'https://' . $host,
                'monitoringType' => $this->settings->defaultMonitoringType(),
                'checkInterval' => $this->settings->defaultCheckInterval(),
                'customFields' => ['whmcs_hosting_id' => (string) $h->id],
            ]);
        }
        if ($this->settings->typeEnabled('hosting_domain_expiry')) {
            $plans[] = new PlannedMonitor(MonitorMap::KIND_HOSTING, $h->id, $h->clientId, MonitorMap::TYPE_CUSTOMER_DOMAIN, $host, $host, ['label' => $host]);
        }
        return $plans;
    }

    /** @return list<PlannedMonitor> */
    private function fromDomain(DomainRow $d): array
    {
        $host = TargetNormalizer::host($d->domain);
        if ($host === '' || !$this->filter->allows($d->clientId) || $this->filter->isIgnoredTarget($host)) {
            return [];
        }
        $plans = [];
        if ($this->settings->typeEnabled('domain_expiry')) {
            $plans[] = new PlannedMonitor(MonitorMap::KIND_DOMAIN, $d->id, $d->clientId, MonitorMap::TYPE_CUSTOMER_DOMAIN, $host, $host, ['label' => $host]);
        }
        if ($this->settings->typeEnabled('domain_dns')) {
            $plans[] = new PlannedMonitor(MonitorMap::KIND_DOMAIN, $d->id, $d->clientId, MonitorMap::TYPE_DNS, $host, $host, ['dnsConfig' => self::DNS_CONFIG]);
        }
        return $plans;
    }

    /**
     * One customer-IP plan per enabled server x client with at least one Active hosting on it (with or without domain,
     * round 2 D3), label = server name.
     *
     * @return list<PlannedMonitor>
     */
    private function serverIps(?int $onlyClientId, ?int $onlyServerId): array
    {
        if (!$this->settings->typeEnabled('server_ips')) {
            return [];
        }
        $plans = [];
        foreach ($this->servers->active() as $server) {
            $ip = TargetNormalizer::host($server->ip);
            if (($onlyServerId !== null && $server->id !== $onlyServerId) || $ip === '' || $this->filter->isIgnoredTarget($ip)) {
                continue;
            }
            foreach ($this->hostings->activeClientIdsForServer($server->id) as $clientId) {
                if (($onlyClientId !== null && $clientId !== $onlyClientId) || !$this->filter->allows($clientId)) {
                    continue;
                }
                $plans[] = new PlannedMonitor(
                    MonitorMap::KIND_SERVER_IP,
                    self::serverIpRefId($server->id, $clientId),
                    $clientId,
                    MonitorMap::TYPE_CUSTOMER_IP,
                    $ip,
                    $server->name,
                    ['label' => $server->name, 'serverId' => $server->id],
                );
            }
        }
        return $plans;
    }
}
