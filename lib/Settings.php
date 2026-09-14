<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Uptimeify;

use Illuminate\Database\Query\Builder;
use WHMCS\Database\Capsule;

/**
 * Module settings in tbladdonmodules (module = 'uptimeify'), one row per key (spec §6).
 *
 * Typed getters normalise whatever is stored and fall back to the spec defaults. Writes go through
 * set()/setBool()/setJson(); input validation for admin forms lives in the settings controller (Task 13), which
 * uses isAllowedBaseUrl() for the https-only api_base_url rule (spec §10), as does Container before it hands the token
 * to a Client.
 */
final class Settings
{
    public const KEYS = [
        'api_token', 'api_base_url', 'connection_validated', 'organization_name', 'default_package_type',
        'auto_create_customers', 'auto_sync', 'sync_hosting_website', 'sync_hosting_domain_expiry',
        'sync_domain_expiry', 'sync_domain_dns', 'sync_server_ips', 'default_check_interval',
        'default_monitoring_type', 'on_terminate', 'filter_mode', 'customer_filter', 'ignored_targets',
        'brand_name', 'status_portal_url', 'product_block_enabled', 'drop_data_on_deactivate',
        // Round 2, D9: the organization binding, a detected mismatch, and a refused connect waiting for "Organisation wechseln".
        'organization_public_id', 'organization_mismatch', 'organization_pending', 'pending_api_token',
    ];

    public const DEFAULT_API_BASE_URL = 'https://uptimeify.io';

    public const DEFAULT_BRAND = 'Uptimeify';

    /** Hosts that may be reached over plain http (spec §10: the sandbox only; 127.0.0.1 is deliberately not listed). */
    public const LOCAL_HTTP_HOSTS = ['localhost', 'host.docker.internal'];

    /**
     * Website monitoring types the addon may use for hosting websites. The API also accepts
     * `playwright`, `heartbeat` and `dns`, but those need a script, have no URL or are a separate
     * monitor kind (docs/api-surface.md, POST /api/websites).
     */
    public const MONITORING_TYPES = ['combined', 'http_status', 'ssl_check'];

    /** Spec §6 range; a subset of the API's 1..1440 minutes. */
    public const CHECK_INTERVAL_MIN = 1;

    public const CHECK_INTERVAL_MAX = 60;

    private const TYPE_DEFAULTS = [
        'hosting_website' => true,
        'hosting_domain_expiry' => false,
        'domain_expiry' => true,
        'domain_dns' => false,
        'server_ips' => false,
    ];

    /** @var array<string,string>|null */
    private ?array $cache = null;

    public function get(string $key, ?string $default = null): ?string
    {
        return $this->load()[$key] ?? $default;
    }

    /** A null value deletes the row. */
    public function set(string $key, ?string $value): void
    {
        $this->cache = null;
        if ($value === null) {
            $this->rows($key)->delete();
            return;
        }
        if ($this->rows($key)->exists()) {
            $this->rows($key)->update(['value' => $value]);
            return;
        }
        Capsule::table('tbladdonmodules')->insert(['module' => Bridge::MODULE, 'setting' => $key, 'value' => $value]);
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $v = $this->get($key);
        return $v === null ? $default : in_array($v, ['1', 'on', 'true'], true);
    }

    public function setBool(string $key, bool $value): void
    {
        $this->set($key, $value ? '1' : '0');
    }

    public function getInt(string $key, int $default): int
    {
        $v = $this->get($key);
        return $v === null || !is_numeric($v) ? $default : (int) $v;
    }

    /** @return array<mixed> */
    public function getJson(string $key): array
    {
        $v = $this->get($key);
        if ($v === null || $v === '') {
            return [];
        }
        $decoded = json_decode($v, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<mixed> $value */
    public function setJson(string $key, array $value): void
    {
        $this->set($key, json_encode($value, JSON_THROW_ON_ERROR));
    }

    /** Decrypted token. Never log or render it (spec §10). */
    public function getApiToken(): ?string
    {
        $cipher = $this->get('api_token');
        return $cipher === null || $cipher === '' ? null : Bridge::decrypt($cipher);
    }

    /** Stores the token encrypted (null/'' removes it) and always resets connection_validated. */
    public function setApiToken(?string $plain): void
    {
        $this->set('api_token', $plain === null || $plain === '' ? null : Bridge::encrypt($plain));
        $this->setBool('connection_validated', false);
    }

    public function hasApiToken(): bool
    {
        return $this->hasValue('api_token');
    }

    private function hasValue(string $key): bool
    {
        $v = $this->get($key);
        return $v !== null && $v !== '';
    }

    public function isConnected(): bool
    {
        return $this->hasApiToken() && $this->getBool('connection_validated');
    }

    public function apiBaseUrl(): string
    {
        $url = trim((string) $this->get('api_base_url'));
        return rtrim($url === '' ? self::DEFAULT_API_BASE_URL : $url, '/');
    }

    /**
     * The one rule for API base URLs (spec §10): https to any host, http only to LOCAL_HTTP_HOSTS. No userinfo, query
     * or fragment, since the client appends the API path to this string.
     */
    public static function isAllowedBaseUrl(string $url): bool
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || $parts['host'] === ''
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }
        $scheme = strtolower($parts['scheme']);
        return $scheme === 'https' || ($scheme === 'http' && in_array(strtolower($parts['host']), self::LOCAL_HTTP_HOSTS, true));
    }

    public function organizationName(): string
    {
        return trim((string) $this->get('organization_name'));
    }

    /** The Uptimeify organization this installation is bound to (round 2, D9); '' before the first connect. */
    public function organizationPublicId(): string
    {
        return trim((string) $this->get('organization_public_id'));
    }

    /**
     * The organization a run found for the stored token when it is not the bound one (OrganizationBinding).
     *
     * @return array{publicId:string,name:string}|null
     */
    public function organizationMismatch(): ?array
    {
        $v = $this->getJson('organization_mismatch');
        return is_string($v['publicId'] ?? null) && $v['publicId'] !== ''
            ? ['publicId' => $v['publicId'], 'name' => is_string($v['name'] ?? null) ? $v['name'] : '']
            : null;
    }

    /**
     * A connect refused because the token belongs to another organization; its token is in `pending_api_token`.
     *
     * @return array{publicId:string,name:string,apiBaseUrl:string}|null
     */
    public function pendingOrganization(): ?array
    {
        $v = $this->getJson('organization_pending');
        if (!is_string($v['publicId'] ?? null) || $v['publicId'] === '' || !$this->hasValue('pending_api_token')) {
            return null;
        }
        return [
            'publicId' => $v['publicId'],
            'name' => is_string($v['name'] ?? null) ? $v['name'] : '',
            'apiBaseUrl' => is_string($v['apiBaseUrl'] ?? null) && $v['apiBaseUrl'] !== '' ? $v['apiBaseUrl'] : $this->apiBaseUrl(),
        ];
    }

    /**
     * Stores (encrypted, like the token) or clears the refused connect.
     *
     * @param array{publicId:string,name:string,apiBaseUrl:string}|null $organization
     */
    public function setPendingOrganization(?array $organization, ?string $token = null): void
    {
        if ($organization === null || $token === null || $token === '') {
            $this->set('organization_pending', null);
            $this->set('pending_api_token', null);
            return;
        }
        $this->set('pending_api_token', Bridge::encrypt($token));
        $this->setJson('organization_pending', $organization);
    }

    /** Decrypted token of the refused connect. Never log or render it. */
    public function pendingApiToken(): ?string
    {
        $cipher = $this->get('pending_api_token');
        return $cipher === null || $cipher === '' ? null : Bridge::decrypt($cipher);
    }

    public function defaultPackageType(): string
    {
        return trim((string) $this->get('default_package_type'));
    }

    public function brandName(): string
    {
        $v = trim((string) $this->get('brand_name'));
        return $v === '' ? self::DEFAULT_BRAND : $v;
    }

    public function statusPortalUrl(): ?string
    {
        $v = trim((string) $this->get('status_portal_url'));
        return $v === '' ? null : $v;
    }

    public function defaultCheckInterval(): int
    {
        return max(self::CHECK_INTERVAL_MIN, min(self::CHECK_INTERVAL_MAX, $this->getInt('default_check_interval', 5)));
    }

    public function defaultMonitoringType(): string
    {
        $v = (string) $this->get('default_monitoring_type');
        return in_array($v, self::MONITORING_TYPES, true) ? $v : 'combined';
    }

    public function onTerminate(): string
    {
        return $this->get('on_terminate') === 'delete' ? 'delete' : 'pause';
    }

    public function typeEnabled(string $type): bool
    {
        return $this->getBool('sync_' . $type, self::TYPE_DEFAULTS[$type] ?? false);
    }

    public function autoCreateCustomers(): bool
    {
        return $this->getBool('auto_create_customers', true);
    }

    public function autoSync(): bool
    {
        return $this->getBool('auto_sync', true);
    }

    public function productBlockEnabled(): bool
    {
        return $this->getBool('product_block_enabled', true);
    }

    public function dropDataOnDeactivate(): bool
    {
        return $this->getBool('drop_data_on_deactivate', false);
    }

    public function filterMode(): string
    {
        return $this->get('filter_mode') === 'whitelist' ? 'whitelist' : 'blacklist';
    }

    /** @return array<int,string> clientId => 'sync'|'skip' */
    public function customerFilter(): array
    {
        $out = [];
        foreach ($this->getJson('customer_filter') as $id => $mode) {
            if (is_int($id) && $id > 0 && in_array($mode, ['sync', 'skip'], true)) {
                $out[$id] = $mode;
            }
        }
        return $out;
    }

    /** @param array<int,string> $filter */
    public function setCustomerFilter(array $filter): void
    {
        $this->setJson('customer_filter', $filter);
    }

    /** @return list<string> */
    public function ignoredTargets(): array
    {
        $out = [];
        foreach ($this->getJson('ignored_targets') as $target) {
            if (is_string($target) || is_int($target)) {
                $out[] = (string) $target;
            }
        }
        return $out;
    }

    /** @param list<string> $targets */
    public function setIgnoredTargets(array $targets): void
    {
        $this->setJson('ignored_targets', array_values(array_unique($targets)));
    }

    private function rows(string $key): Builder
    {
        return Capsule::table('tbladdonmodules')->where('module', Bridge::MODULE)->where('setting', $key);
    }

    /** @return array<string,string> */
    private function load(): array
    {
        if ($this->cache === null) {
            $this->cache = [];
            foreach (Capsule::table('tbladdonmodules')->where('module', Bridge::MODULE)->get() as $row) {
                $this->cache[(string) $row->setting] = (string) $row->value;
            }
        }
        return $this->cache;
    }
}
