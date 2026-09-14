<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Uptimeify\Client;

use WHMCS\Module\Addon\Uptimeify\Admin\View;
use WHMCS\Module\Addon\Uptimeify\Bridge;
use WHMCS\Module\Addon\Uptimeify\Container;
use WHMCS\Module\Addon\Uptimeify\Lang;
use WHMCS\Module\Addon\Uptimeify\Settings;
use WHMCS\Module\Addon\Uptimeify\Store\CustomerMap;
use WHMCS\Module\Addon\Uptimeify\Store\MonitorMap;
use WHMCS\Module\Addon\Uptimeify\Store\StatusCache;
use WHMCS\Module\Addon\Uptimeify\Whmcs\HostingRepository;

/**
 * Read-only client area (spec §9.3): the overview page and the product details block, both from the `client:<id>`
 * snapshot. The caller passes the client id from Bridge::currentClientId() only.
 *
 * Spec §10: what is shown is checked here, not only by the snapshot key: the snapshot must belong to the client's
 * current customer, that customer must be mapped to this client alone, every monitor must have a monitor-map row of
 * that customer and every incident must carry that customer's public id. Totals are counted from what is shown.
 */
final class MonitoringController
{
    public const MODULE_LINK = 'index.php?m=uptimeify';

    public const STATE_NOT_LOGGED_IN = 'not_logged_in';
    public const STATE_NOT_SET_UP = 'not_set_up';
    public const STATE_UNAVAILABLE = 'unavailable';
    /** A WHMCS sub-user without the permission to view products and services (round 2, D10). */
    public const STATE_NO_PERMISSION = 'no_permission';
    public const STATE_OK = 'ok';

    /** The "customer mapped to several clients" line is logged at most once per client per this window. */
    public const CONFLICT_LOG_SECONDS = 3600;

    public function __construct(
        private readonly Settings $settings,
        private readonly CustomerMap $customers,
        private readonly MonitorMap $monitors,
        private readonly HostingRepository $hostings,
        private readonly ClientSnapshot $snapshots,
        private readonly StatusCache $cache,
    ) {
    }

    public static function fromContainer(): self
    {
        return new self(Container::settings(), Container::customerMap(), Container::monitorMap(), new HostingRepository(), Container::clientSnapshot(), Container::statusCache());
    }

    /**
     * The template variables of `templates/overview.tpl`, for a page without monitoring data.
     *
     * @param array<string,string> $lang
     * @return array<string,mixed>
     */
    public static function baseVars(string $state, string $brand, array $lang): array
    {
        $strings = [];
        foreach ($lang as $key => $_) {
            if (str_starts_with($key, 'client.')) {
                $strings[substr($key, strlen('client.'))] = Lang::t($lang, $key, ['brand' => $brand]);
            }
        }
        return [
            'state' => $state,
            'notLoggedIn' => $state === self::STATE_NOT_LOGGED_IN,
            'brand' => $brand,
            'L' => $strings,
            'monitors' => [],
            'incidents' => [],
            'totals' => ['total' => 0, 'up' => 0, 'down' => 0, 'paused' => 0],
            'stale' => false,
            'incidentsAvailable' => true,
            'incidentsComplete' => true,
            'refreshedAt' => null,
            'portalUrl' => null,
        ];
    }

    /**
     * @param array<string,string> $lang
     * @return array<string,mixed>
     */
    public function overview(int $clientId, array $lang): array
    {
        $brand = $this->settings->brandName();
        $owned = $this->owned($clientId, true);
        $vars = self::baseVars($owned['state'], $brand, $lang);
        $portal = $this->settings->statusPortalUrl();
        $vars['portalUrl'] = $portal !== null && preg_match('#^https?://[^\s]+$#i', $portal) === 1 ? $portal : null;
        if ($owned['snapshot'] === null) {
            return $vars;
        }
        $t = static fn (string $key): string => Lang::t($lang, $key, ['brand' => $brand]);
        foreach ($owned['monitors'] as $monitor) {
            $vars['monitors'][] = self::present($monitor, $t);
            $status = self::string($monitor['status'] ?? null);
            $vars['totals']['total']++;
            if (in_array($status, ['up', 'down', 'paused'], true)) {
                $vars['totals'][$status]++;
            }
        }
        foreach ($owned['incidents'] as $incident) {
            $vars['incidents'][] = [
                'title' => self::string($incident['title'] ?? null),
                'monitorName' => self::string($incident['monitorName'] ?? null),
                'startedAt' => self::dateTime($incident['startedAt'] ?? null, true),
            ];
        }
        $vars['stale'] = ($owned['snapshot']['stale'] ?? false) === true;
        $vars['incidentsAvailable'] = ($owned['snapshot']['incidentsAvailable'] ?? true) !== false;
        // Only the newest incidents are read (round 2, D2); the note matters only while a monitor of the client is down.
        $vars['incidentsComplete'] = ($owned['snapshot']['incidentsComplete'] ?? true) !== false || $vars['totals']['down'] === 0;
        $vars['refreshedAt'] = self::dateTime($owned['snapshot']['refreshedAt'] ?? null, true);
        return $vars;
    }

    /**
     * Status block of the hosting's own website monitor (spec §7 ClientAreaProductDetailsOutput), or '' when the
     * block is off, the service is not the client's, or the hosting has no website monitor of the client's customer.
     * Reads the cached snapshot only (an expired one with the stale hint); the overview page does the refreshing, so a
     * slow API never slows the product details page.
     *
     * @param array<string,string> $lang
     */
    public function productBlock(int $clientId, int $serviceId, array $lang): string
    {
        if (!$this->settings->productBlockEnabled() || $serviceId <= 0) {
            return '';
        }
        $hosting = $this->hostings->find($serviceId);
        $mapping = $this->customers->get($clientId);
        $row = $this->monitors->find(MonitorMap::KIND_HOSTING, $serviceId, MonitorMap::TYPE_WEBSITE);
        if ($hosting === null || $hosting->clientId !== $clientId || $mapping === null || $row === null
            || $row['monitor_public_id'] === null || $row['customer_public_id'] !== $mapping['customer_public_id']) {
            return '';
        }
        $owned = $this->owned($clientId, false);
        foreach ($owned['monitors'] as $monitor) {
            if (($monitor['type'] ?? null) !== MonitorMap::TYPE_WEBSITE || ($monitor['publicId'] ?? null) !== $row['monitor_public_id']) {
                continue;
            }
            $brand = $this->settings->brandName();
            $t = static fn (string $key): string => Lang::t($lang, $key, ['brand' => $brand]);
            $m = self::present($monitor, $t);
            $stale = ($owned['snapshot']['stale'] ?? false) === true
                ? '<p class="text-muted small">' . View::e($t('client.stale')) . '</p>'
                : '';
            return '<div class="card uptimeify-product-block"><div class="card-body">'
                . '<h3 class="card-title">' . View::e($t('client.product_block_title')) . '</h3>'
                . '<p><span class="badge badge-' . View::e($m['statusClass']) . '">' . View::e($m['statusLabel']) . '</span> '
                . View::e($t('client.uptime_day')) . ': ' . View::e($m['uptimeDay']) . ' &middot; '
                . View::e($t('client.uptime_month')) . ': ' . View::e($m['uptimeMonth']) . '</p>'
                . $stale
                . '<a href="' . View::e(self::MODULE_LINK) . '" class="btn btn-default btn-secondary btn-sm">' . View::e($t('client.open_overview')) . '</a>'
                . '</div></div>';
        }
        return '';
    }

    private static function pct(mixed $value): string
    {
        return is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))
            ? number_format((float) $value, 2, '.', '') . ' %'
            : '—';
    }

    /**
     * @return array{state:string, snapshot: array<string,mixed>|null, monitors: list<array<string,mixed>>, incidents: list<array<string,mixed>>}
     */
    private function owned(int $clientId, bool $refresh): array
    {
        $none = ['snapshot' => null, 'monitors' => [], 'incidents' => []];
        $mapping = $this->customers->get($clientId);
        if ($mapping === null) {
            return ['state' => self::STATE_NOT_SET_UP] + $none;
        }
        $customerPublicId = $mapping['customer_public_id'];
        if ($this->customers->clientIdsFor($customerPublicId) !== [$clientId]) {
            if ($this->cache->claim('client_conflict_log:' . $clientId, self::CONFLICT_LOG_SECONDS)) {
                Bridge::logActivity('Client area: customer ' . $customerPublicId . ' is mapped to several WHMCS clients; nothing is shown until the mapping is fixed', $clientId);
            }
            return ['state' => self::STATE_UNAVAILABLE] + $none;
        }
        $snapshot = $refresh ? $this->snapshots->for($clientId) : $this->snapshots->cached($clientId);
        if ($snapshot === null || ($snapshot['customerPublicId'] ?? null) !== $customerPublicId) {
            return ['state' => self::STATE_UNAVAILABLE] + $none;
        }
        $allowed = [];
        foreach ($this->monitors->forCustomer($customerPublicId) as $row) {
            if ($row['monitor_public_id'] !== null && $row['customer_public_id'] === $customerPublicId) {
                $allowed[$row['monitor_type'] . '|' . $row['monitor_public_id']] = true;
            }
        }
        $monitors = [];
        foreach (is_array($snapshot['monitors'] ?? null) ? $snapshot['monitors'] : [] as $monitor) {
            if (is_array($monitor) && isset($allowed[self::string($monitor['type'] ?? null) . '|' . self::string($monitor['publicId'] ?? null)])) {
                $monitors[] = $monitor;
            }
        }
        $incidents = [];
        foreach (is_array($snapshot['incidents'] ?? null) ? $snapshot['incidents'] : [] as $incident) {
            if (is_array($incident) && ($incident['customerPublicId'] ?? null) === $customerPublicId) {
                $incidents[] = $incident;
            }
        }
        return ['state' => self::STATE_OK, 'snapshot' => $snapshot, 'monitors' => $monitors, 'incidents' => $incidents];
    }

    /**
     * @param array<mixed> $m a snapshot monitor
     * @param \Closure(string):string $t
     * @return array<string,mixed>
     */
    private static function present(array $m, \Closure $t): array
    {
        $type = self::string($m['type'] ?? null);
        $status = self::string($m['status'] ?? null);
        $status = in_array($status, ['up', 'down', 'paused'], true) ? $status : 'unknown';
        $isDomain = $type === MonitorMap::TYPE_CUSTOMER_DOMAIN;
        $response = $m['avgResponseDay'] ?? null;
        return [
            'publicId' => self::string($m['publicId'] ?? null),
            'type' => $type,
            'typeLabel' => $t('type.' . $type),
            'name' => self::string($m['name'] ?? null),
            'target' => self::string($m['target'] ?? null),
            'status' => $status,
            'statusLabel' => $t('status.' . $status),
            'statusClass' => match ($status) {
                'up' => 'success',
                'down' => 'danger',
                'paused' => 'secondary',
                default => 'warning',
            },
            'uptimeDay' => self::pct($m['uptimeDay'] ?? null),
            'uptimeMonth' => self::pct($m['uptimeMonth'] ?? null),
            'uptimeYear' => self::pct($m['uptimeYear'] ?? null),
            'avgResponse' => is_int($response) || is_float($response) ? (int) round($response) . ' ms' : '—',
            'openIncidents' => is_int($m['openIncidents'] ?? null) ? $m['openIncidents'] : 0,
            'isDomain' => $isDomain,
            'expiresAt' => $isDomain ? self::dateTime($m['expiresAt'] ?? null, false) : '—',
            'registrar' => $isDomain && self::string($m['registrar'] ?? null) !== '' ? self::string($m['registrar']) : '—',
        ];
    }

    /** UTC date ('Y-m-d') or date and time ('Y-m-d H:i UTC') of an ISO or 'Y-m-d H:i:s' UTC value; '—' when unreadable. */
    private static function dateTime(mixed $value, bool $withTime): string
    {
        if (!is_string($value) || trim($value) === '') {
            return '—';
        }
        try {
            $date = (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return '—';
        }
        return $withTime ? $date->format('Y-m-d H:i') . ' UTC' : $date->format('Y-m-d');
    }

    private static function string(mixed $value): string
    {
        return is_string($value) || is_int($value) ? (string) $value : '';
    }
}
