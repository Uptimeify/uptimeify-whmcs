<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Uptimeify\Admin;

use WHMCS\Module\Addon\Uptimeify\Api\Client;
use WHMCS\Module\Addon\Uptimeify\Api\Exception\ApiException;
use WHMCS\Module\Addon\Uptimeify\Api\Exception\RateLimitedException;
use WHMCS\Module\Addon\Uptimeify\Api\Exception\UnauthorizedException;
use WHMCS\Module\Addon\Uptimeify\Bridge;
use WHMCS\Module\Addon\Uptimeify\Container;
use WHMCS\Module\Addon\Uptimeify\Settings;
use WHMCS\Module\Addon\Uptimeify\Store\Schema;
use WHMCS\Module\Addon\Uptimeify\Store\SyncLock;
use WHMCS\Module\Addon\Uptimeify\Sync\OrganizationBinding;

/**
 * Setup (token, connect) and the settings tab (spec §6, §9.1). The token is write-only: no template ever receives
 * it, not even the stored cipher (spec §10).
 */
final class SettingsController
{
    /** Checkbox settings; a form lists the ones it shows in `form_bools[]`, only those are written (T13-4). */
    public const BOOL_KEYS = [
        'auto_create_customers', 'auto_sync', 'sync_hosting_website', 'sync_hosting_domain_expiry', 'sync_domain_expiry',
        'sync_domain_dns', 'sync_server_ips', 'product_block_enabled', 'drop_data_on_deactivate',
    ];

    private const ON_TERMINATE = ['pause', 'delete'];

    /** @param array<string,mixed> $ctx */
    public function showSetup(array $ctx): string
    {
        $settings = Container::settings();
        $banners = [];
        if (!Settings::isAllowedBaseUrl($settings->apiBaseUrl())) {
            $banners[] = ['type' => 'danger', 'message' => View::t('banner.base_url_invalid'), 'link' => null, 'linkText' => null];
        }
        if ($settings->hasApiToken()) {
            // A stored token without a usable connection: reset after a 401, or no longer decryptable (spec §5).
            $banners[] = ['type' => 'danger', 'message' => View::t('banner.connection_reset'), 'link' => null, 'linkText' => null];
        }
        $vars = ['banners' => $banners, 'apiBaseUrl' => $settings->apiBaseUrl(), 'organizationSwitch' => self::switchView($settings)] + $ctx;
        return View::render('layout', $vars + ['content' => View::render('setup', $vars)]);
    }

    /** @param array<string,mixed> $ctx */
    public function showSettings(array $ctx): string
    {
        $settings = Container::settings();
        $packages = [];
        $packagesError = null;
        try {
            $packages = $this->packageConfigs();
        } catch (\Throwable $e) {
            if ($e instanceof UnauthorizedException) {
                $settings->setBool('connection_validated', false);
            }
            $packagesError = $e->getMessage();
        }
        $package = $settings->defaultPackageType();
        $vars = $ctx + [
            'organizationName' => $settings->organizationName(),
            'organizationSwitch' => self::switchView($settings),
            'packages' => $packages,
            'packagesError' => $packagesError,
            'packageMissing' => $package !== '' && $packagesError === null && !isset($packages[$package]),
            'values' => [
                'api_base_url' => $settings->apiBaseUrl(),
                'default_package_type' => $package,
                'default_monitoring_type' => $settings->defaultMonitoringType(),
                'default_check_interval' => $settings->defaultCheckInterval(),
                'on_terminate' => $settings->onTerminate(),
                'brand_name' => trim((string) $settings->get('brand_name')),
                'status_portal_url' => (string) $settings->statusPortalUrl(),
            ],
            'bools' => [
                'auto_create_customers' => $settings->autoCreateCustomers(),
                'auto_sync' => $settings->autoSync(),
                'sync_hosting_website' => $settings->typeEnabled('hosting_website'),
                'sync_hosting_domain_expiry' => $settings->typeEnabled('hosting_domain_expiry'),
                'sync_domain_expiry' => $settings->typeEnabled('domain_expiry'),
                'sync_domain_dns' => $settings->typeEnabled('domain_dns'),
                'sync_server_ips' => $settings->typeEnabled('server_ips'),
                'product_block_enabled' => $settings->productBlockEnabled(),
                'drop_data_on_deactivate' => $settings->dropDataOnDeactivate(),
            ],
            'monitoringTypes' => Settings::MONITORING_TYPES,
        ];
        return View::render('layout', $vars + ['content' => View::render('settings', $vars)]);
    }

    /**
     * Handshake (spec §5): `GET /api/organization`, then the read-only scope probe `GET /api/custom-fields`, which a
     * customer-bound token cannot read. The first connect binds the installation to the token's organization
     * (`publicId`); a token of another organization is refused and offered for "Organisation wechseln" (round 2, D9). The token, and the advanced `api_base_url` the setup form may carry (sandbox,
     * T17-2), are stored only when both succeed, so a failed attempt keeps the working connection.
     *
     * @param array<string,mixed> $post
     * @return bool true when connected
     */
    public function connect(array $post): bool
    {
        $settings = Container::settings();
        $token = trim(is_string($post['api_token'] ?? null) ? $post['api_token'] : '');
        if (!str_starts_with($token, 'wsm_') || strlen($token) <= 4) {
            Flash::set('error', View::t('error.token_format'));
            return false;
        }
        $url = $settings->apiBaseUrl();
        if (is_string($post['api_base_url'] ?? null) && trim($post['api_base_url']) !== '') {
            $url = rtrim(trim($post['api_base_url']), '/');
            if (!Settings::isAllowedBaseUrl($url)) {
                Flash::set('error', View::t('error.base_url'));
                return false;
            }
        }
        try {
            $api = Container::apiWithToken($token, $url);
            $organization = $api->getOrganization();
            $api->listCustomFieldDefinitions();
        } catch (UnauthorizedException) {
            // The new token was rejected, not the stored one: the current connection stays as it is.
            Flash::set('error', View::t('error.unauthorized'));
            return false;
        } catch (ApiException $e) {
            Flash::set('error', $e->errorCode === Client::SCOPE_FORBIDDEN_CODE ? View::t('error.customer_scoped') : View::t('error.api', ['message' => $e->getMessage()]));
            return false;
        } catch (\RuntimeException) {
            Flash::set('error', View::t('error.base_url'));
            return false;
        }
        try {
            $identity = OrganizationBinding::identity($organization);
        } catch (ApiException $e) {
            Flash::set('error', View::t('error.api', ['message' => $e->getMessage()]));
            return false;
        }
        $bound = $settings->organizationPublicId();
        if ($bound !== '' && $bound !== $identity['publicId']) {
            // Round 2, D9: the local data belongs to the bound organization. The verified token waits (encrypted) for the
            // admin's explicit "Organisation wechseln"; the working connection stays as it is.
            $settings->setPendingOrganization($identity + ['apiBaseUrl' => $url], $token);
            Flash::set('error', View::t('error.organization_changed', ['new' => self::label($identity), 'bound' => self::boundLabel($settings)]));
            return false;
        }
        $name = $identity['name'];
        // Base URL and token change together, only after the handshake: a failed attempt keeps the working connection.
        $settings->set('api_base_url', $url === Settings::DEFAULT_API_BASE_URL ? null : $url);
        $settings->setApiToken($token);
        $settings->set('organization_name', $name === '' ? null : $name);
        $settings->set('organization_public_id', $identity['publicId']);
        $settings->set('organization_mismatch', null);
        $settings->setPendingOrganization(null);
        $settings->setBool('connection_validated', true);
        Flash::set('success', View::t('setup.connected', ['org' => $name]));
        return true;
    }

    /**
     * "Organisation wechseln" (round 2, D9), a separate confirmed POST: for the organization offered on the page (a refused
     * connect's pending token, or the stored token after a run found another organization), under the sync lock, the
     * token's organization is checked again; then every module table is emptied locally (customer_map, monitor_map, queue,
     * status_cache, detached) and the installation is bound to the new organization. Nothing is deleted remotely. A default
     * package whose key the new organization's package list does not contain is cleared, so the first run does not create
     * customers against the old organization's package (round 2 review, Minor 6); when the list cannot be read it stays.
     *
     * @param array<string,mixed> $post
     */
    public function switchOrganization(array $post): void
    {
        $settings = Container::settings();
        $offer = self::switchOffer($settings);
        $target = self::text($post, 'organization_public_id');
        if ($offer === null || $target !== $offer['publicId']) {
            Flash::set('error', View::t('error.organization_switch_stale'));
            return;
        }
        try {
            $token = $offer['pending'] ? $settings->pendingApiToken() : $settings->getApiToken();
            $api = $token === null || $token === '' ? null : Container::apiWithToken($token, $offer['apiBaseUrl']);
        } catch (\RuntimeException) {
            $token = $api = null;
        }
        if ($api === null || $token === null) {
            Flash::set('error', View::t('error.organization_switch_stale'));
            return;
        }
        $lock = new SyncLock();
        if (!$lock->acquire()) {
            Flash::set('error', View::t('error.sync_locked'));
            return;
        }
        try {
            try {
                $identity = OrganizationBinding::identity(Container::guard(true)->run(static fn (): array => $api->getOrganization()));
            } catch (UnauthorizedException) {
                Flash::set('error', View::t('error.unauthorized'));
                return;
            } catch (ApiException $e) {
                Flash::set('error', $e instanceof RateLimitedException ? View::t('error.rate_limited') : View::t('error.api', ['message' => $e->getMessage()]));
                return;
            }
            if ($identity['publicId'] !== $target) {
                Flash::set('error', View::t('error.organization_switch_stale'));
                return;
            }
            $package = $settings->defaultPackageType();
            $packageGone = $package !== '' && !self::organizationHasPackage($api, $package);
            $previous = self::boundLabel($settings);
            Schema::clearData();
            if ($offer['pending']) {
                $settings->set('api_base_url', $offer['apiBaseUrl'] === Settings::DEFAULT_API_BASE_URL ? null : $offer['apiBaseUrl']);
                $settings->setApiToken($token);
                $settings->setBool('connection_validated', true);
            }
            $settings->set('organization_public_id', $identity['publicId']);
            $settings->set('organization_name', $identity['name'] === '' ? null : $identity['name']);
            $settings->set('organization_mismatch', null);
            $settings->setPendingOrganization(null);
            if ($packageGone) {
                $settings->set('default_package_type', null);
            }
            Bridge::logActivity(sprintf('Organization switched from %s to %s (%s): local mappings, monitor rows, queue, status cache and detached records cleared; nothing was deleted in Uptimeify', $previous, $identity['name'], $identity['publicId'])
                . ($packageGone ? sprintf('; default package "%s" cleared (not in the new organization)', $package) : ''));
            Flash::set('success', View::t('organization.switched', ['org' => self::label($identity)])
                . ($packageGone ? ' ' . View::t('organization.package_cleared', ['package' => $package]) : ''));
        } finally {
            $lock->release();
        }
    }

    /** True unless the package list was read and lacks the key; a failed read counts as "has it" (nothing is cleared). */
    private static function organizationHasPackage(Client $api, string $package): bool
    {
        try {
            foreach (Container::guard(true)->run(static fn (): array => $api->listPackageConfigs()) as $row) {
                if (($row['packageType'] ?? null) === $package) {
                    return true;
                }
            }
        } catch (ApiException) {
            return true;
        }
        return false;
    }

    /** Discards the token of a refused connect; the connection stays with the bound organization. */
    public function cancelOrganizationSwitch(): void
    {
        $settings = Container::settings();
        if ($settings->pendingOrganization() === null) {
            Flash::set('error', View::t('error.organization_switch_stale'));
            return;
        }
        $settings->setPendingOrganization(null);
        Flash::set('success', View::t('organization.switch_cancelled', ['org' => self::boundLabel($settings)]));
    }

    /**
     * What "Organisation wechseln" would switch to: a refused connect first, else a mismatch a run found for the stored
     * token.
     *
     * @return array{publicId:string,name:string,apiBaseUrl:string,pending:bool}|null
     */
    private static function switchOffer(Settings $settings): ?array
    {
        $pending = $settings->pendingOrganization();
        if ($pending !== null) {
            return $pending + ['pending' => true];
        }
        $mismatch = $settings->organizationMismatch();
        return $mismatch !== null && $settings->hasApiToken() ? $mismatch + ['apiBaseUrl' => $settings->apiBaseUrl(), 'pending' => false] : null;
    }

    /** @return array{publicId:string,label:string,boundLabel:string,pending:bool}|null the template's view of switchOffer() */
    private static function switchView(Settings $settings): ?array
    {
        $offer = self::switchOffer($settings);
        return $offer === null ? null : ['publicId' => $offer['publicId'], 'label' => self::label($offer), 'boundLabel' => self::boundLabel($settings), 'pending' => $offer['pending']];
    }

    /** @param array{publicId:string,name:string} $organization */
    private static function label(array $organization): string
    {
        return $organization['name'] !== '' ? $organization['name'] : $organization['publicId'];
    }

    public static function boundLabel(Settings $settings): string
    {
        return $settings->organizationName() !== '' ? $settings->organizationName() : $settings->organizationPublicId();
    }

    /**
     * Writes every spec §6 key the form carries; an invalid value is not written and reported, the valid ones are.
     *
     * @param array<string,mixed> $post
     */
    public function saveSettings(array $post): void
    {
        $settings = Container::settings();
        $errors = [];
        $notes = [];

        if (self::has($post, 'api_base_url')) {
            $url = rtrim(self::text($post, 'api_base_url'), '/');
            if (!Settings::isAllowedBaseUrl($url)) {
                $errors[] = View::t('error.base_url');
            } elseif ($url !== $settings->apiBaseUrl()) {
                $settings->set('api_base_url', $url);
                if ($settings->hasApiToken()) {
                    // The token was verified against the old host; it is never sent to the new one unverified. The
                    // organization binding stays (round 2, D9): a token of another organization there needs a switch.
                    $settings->setApiToken(null);
                    $notes[] = View::t('settings.base_url_changed');
                }
            }
        }
        if (self::has($post, 'default_package_type')) {
            $package = self::text($post, 'default_package_type');
            if ($package !== $settings->defaultPackageType()) {
                $error = $this->packageError($package);
                if ($error === null) {
                    $settings->set('default_package_type', $package === '' ? null : $package);
                } else {
                    $errors[] = $error;
                }
            }
        }
        if (self::has($post, 'default_monitoring_type')) {
            $type = self::text($post, 'default_monitoring_type');
            if (in_array($type, Settings::MONITORING_TYPES, true)) {
                $settings->set('default_monitoring_type', $type);
            } else {
                $errors[] = View::t('error.monitoring_type');
            }
        }
        if (self::has($post, 'default_check_interval')) {
            $interval = (int) self::text($post, 'default_check_interval');
            $settings->set('default_check_interval', (string) max(Settings::CHECK_INTERVAL_MIN, min(Settings::CHECK_INTERVAL_MAX, $interval)));
        }
        if (self::has($post, 'on_terminate')) {
            $mode = self::text($post, 'on_terminate');
            if (in_array($mode, self::ON_TERMINATE, true)) {
                $settings->set('on_terminate', $mode);
            } else {
                $errors[] = View::t('error.on_terminate');
            }
        }
        if (self::has($post, 'brand_name')) {
            $brand = self::text($post, 'brand_name');
            $settings->set('brand_name', $brand === '' ? null : mb_substr($brand, 0, 100));
        }
        if (self::has($post, 'status_portal_url')) {
            $portal = self::text($post, 'status_portal_url');
            if ($portal === '') {
                $settings->set('status_portal_url', null);
            } elseif (self::isWebUrl($portal)) {
                $settings->set('status_portal_url', $portal);
            } else {
                $errors[] = View::t('error.portal_url');
            }
        }
        $listed = is_array($post['form_bools'] ?? null) ? $post['form_bools'] : [];
        foreach (self::BOOL_KEYS as $key) {
            if (in_array($key, $listed, true)) {
                $settings->setBool($key, in_array($post[$key] ?? null, ['1', 'on', 'true', 1, true], true));
            }
        }

        if ($errors !== []) {
            Flash::set('error', implode(' ', [...$errors, ...$notes]));
        } elseif ($notes !== []) {
            Flash::set('warning', implode(' ', $notes));
        } else {
            Flash::set('success', View::t('settings.saved'));
        }
    }

    /** Forgets the token (and a pending one); the organization binding stays, like the mappings (round 2, D9). */
    public function disconnect(): void
    {
        $settings = Container::settings();
        $settings->setApiToken(null);
        $settings->setPendingOrganization(null);
        Flash::set('success', View::t('settings.disconnected'));
    }

    /**
     * Package rows by their key `packageType` (docs/api-surface.md), the value `POST /api/customers` takes.
     *
     * @return array<string,array<string,mixed>>
     */
    private function packageConfigs(): array
    {
        $out = [];
        foreach (Container::guard(true)->run(fn (): array => Container::apiOrFail()->listPackageConfigs()) as $row) {
            if (is_string($row['packageType'] ?? null) && $row['packageType'] !== '') {
                $out[$row['packageType']] = $row;
            }
        }
        return $out;
    }

    private function packageError(string $package): ?string
    {
        if ($package === '') {
            return null;
        }
        try {
            $known = $this->packageConfigs();
        } catch (UnauthorizedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return View::t('error.packages_unavailable', ['message' => $e->getMessage()]);
        }
        return isset($known[$package]) ? null : View::t('error.package_unknown', ['package' => $package]);
    }

    /** @param array<string,mixed> $post */
    private static function has(array $post, string $key): bool
    {
        return array_key_exists($key, $post) && is_scalar($post[$key]);
    }

    /** @param array<string,mixed> $post */
    private static function text(array $post, string $key): string
    {
        return is_scalar($post[$key] ?? null) ? trim((string) $post[$key]) : '';
    }

    private static function isWebUrl(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return filter_var($url, FILTER_VALIDATE_URL) !== false && in_array($scheme, ['http', 'https'], true);
    }
}
