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
use WHMCS\Module\Addon\Uptimeify\Sync\OrganizationMismatchException;
use WHMCS\Module\Addon\Uptimeify\Sync\Reconciler;

/**
 * Admin entry point (`addonmodules.php?module=uptimeify&action=…`, spec §9.1).
 *
 * - GET renders a tab; the setup form replaces every tab while there is no validated, usable connection.
 * - POST runs one action, always after `check_token('WHMCS.admin.default')`, and answers `REDIRECT:<url>` back to a
 *   known tab (uptimeify_output turns that into a Location header). Access control is WHMCS' own per-addon setting.
 * - Errors of an action become a flash message; a 401 resets `connection_validated` (spec §5), so the next page is
 *   the setup form with the reset banner.
 */
final class Dispatcher
{
    public const TABS = ['dashboard', 'customers', 'log', 'settings'];

    /** Setup errors that stop monitors and need the admin (spec §5), shown as banners on every tab. */
    private const SETUP_ERROR_BANNERS = [
        Client::INVALID_PACKAGE_TYPE_CODE => 'banner.invalid_package',
        Reconciler::ERROR_NO_DEFAULT_PACKAGE => 'banner.no_default_package',
        Client::SCOPE_FORBIDDEN_CODE => 'banner.customer_scoped',
    ];

    /** Actions that work without a validated connection. */
    private const WITHOUT_CONNECTION = ['connect', 'switch_organization', 'cancel_organization_switch'];

    /**
     * Single actions refused while a run found the token of another organization (round 2, D9): they would pause, map or
     * (ignore) detach rows against that organization. Runs ("Jetzt abgleichen", "Status aktualisieren", "Ausgewählte
     * synchronisieren") check the organization themselves.
     */
    private const BLOCKED_ON_ORGANIZATION_MISMATCH = ['enable', 'pause', 'ignore', 'set_customer'];

    /** @param (\Closure():float)|null $clock seconds, for the time budget of synchronous actions (tests) */
    public function __construct(private readonly ?\Closure $clock = null)
    {
    }

    /**
     * @param array<string,mixed> $vars WHMCS module vars (`modulelink`, `version`)
     * @param array<string,mixed> $get
     * @param array<string,mixed> $post
     */
    public function handle(array $vars, array $get, array $post, string $method): string
    {
        $modulelink = is_string($vars['modulelink'] ?? null) ? $vars['modulelink'] : 'addonmodules.php?module=' . Bridge::MODULE;
        $action = is_string($get['action'] ?? null) ? $get['action'] : '';

        if (strtoupper($method) === 'POST') {
            Bridge::checkAdminToken();
            $back = $this->post($action, $post);
            return 'REDIRECT:' . View::url($modulelink, ['action' => $back]);
        }

        $settings = Container::settings();
        $ctx = [
            't' => static fn (string $key, array $v = []): string => View::t($key, $v),
            'modulelink' => $modulelink,
            'tokenField' => Bridge::adminTokenField(),
            'brand' => $settings->brandName(),
            'flash' => Flash::pull(),
            'banners' => [],
            'version' => is_scalar($vars['version'] ?? null) ? (string) $vars['version'] : '',
        ];
        if (!$settings->isConnected() || Container::api() === null) {
            return (new SettingsController())->showSetup($ctx + ['tab' => 'setup']);
        }
        $tab = in_array($action, self::TABS, true) ? $action : 'dashboard';
        $ctx['tab'] = $tab;
        $ctx['banners'] = $this->setupErrorBanners($settings, $modulelink);
        return match ($tab) {
            'settings' => (new SettingsController())->showSettings($ctx),
            'customers' => (new CustomersController())->show($ctx, $get),
            'log' => (new LogController())->show($ctx),
            default => (new DashboardController($this->clock))->show($ctx, $get),
        };
    }

    /**
     * @param array<string,mixed> $post
     * @return string the tab to go back to
     */
    private function post(string $action, array $post): string
    {
        $back = is_string($post['back'] ?? null) && in_array($post['back'], self::TABS, true) ? $post['back'] : 'dashboard';
        try {
            if (!in_array($action, self::WITHOUT_CONNECTION, true) && !Container::settings()->isConnected()) {
                Flash::set('error', View::t('error.not_connected'));
                return $back;
            }
            if (in_array($action, self::BLOCKED_ON_ORGANIZATION_MISMATCH, true) && Container::settings()->organizationMismatch() !== null) {
                Flash::set('error', View::t('error.organization_mismatch'));
                return $back;
            }
            $settings = new SettingsController();
            $dashboard = new DashboardController($this->clock);
            $customers = new CustomersController();
            switch ($action) {
                case 'connect':
                    return $settings->connect($post) ? 'settings' : $back;
                case 'save_settings':
                    $settings->saveSettings($post);
                    break;
                case 'disconnect':
                    $settings->disconnect();
                    break;
                case 'switch_organization':
                    $settings->switchOrganization($post);
                    break;
                case 'cancel_organization_switch':
                    $settings->cancelOrganizationSwitch();
                    break;
                case 'reconcile':
                    $dashboard->reconcile();
                    break;
                case 'refresh_status':
                    $dashboard->refreshStatus();
                    break;
                case 'sync_selected':
                    $dashboard->syncSelected(self::strings($post['keys'] ?? []));
                    break;
                case 'enable':
                    $dashboard->enable(self::string($post['key'] ?? ''));
                    break;
                case 'pause':
                    $dashboard->pause(self::string($post['key'] ?? ''));
                    break;
                case 'ignore':
                    $dashboard->ignore(self::string($post['target'] ?? ''));
                    break;
                case 'unignore':
                    $dashboard->unignore(self::string($post['target'] ?? ''));
                    break;
                case 'set_customer':
                    $dashboard->setCustomer(self::int($post['client_id'] ?? 0), self::string($post['customer_public_id'] ?? ''));
                    break;
                case 'save_filter':
                    $customers->saveFilter($post);
                    break;
                case 'set_client_mode':
                    $customers->setClientMode(self::int($post['client_id'] ?? 0), self::string($post['mode'] ?? ''));
                    break;
                case 'retry_dead':
                    (new LogController())->retryDead(self::int($post['id'] ?? 0));
                    break;
                default:
                    Flash::set('error', View::t('error.unknown_action'));
            }
        } catch (\Throwable $e) {
            self::flashError($e);
        }
        return $back;
    }

    /**
     * The admin-visible reaction to an error of an action (spec §5). Messages of the API client never contain the token.
     */
    public static function flashError(\Throwable $e): void
    {
        if ($e instanceof UnauthorizedException) {
            Container::settings()->setBool('connection_validated', false);
            Bridge::logActivity('Admin action stopped: the API token was rejected, connection reset: ' . $e->getMessage());
            Flash::set('error', View::t('error.unauthorized'));
        } elseif ($e instanceof OrganizationMismatchException) {
            Flash::set('error', View::t('error.organization_mismatch'));
        } elseif ($e instanceof RateLimitedException) {
            Flash::set('error', View::t('error.rate_limited'));
        } elseif ($e instanceof ApiException && $e->errorCode === Client::SCOPE_FORBIDDEN_CODE) {
            Flash::set('error', View::t('error.customer_scoped'));
        } elseif ($e instanceof ApiException && $e->errorCode === Client::INVALID_PACKAGE_TYPE_CODE) {
            Flash::set('error', View::t('error.invalid_package'));
        } elseif ($e instanceof ApiException) {
            Flash::set('error', View::t('error.api', ['message' => $e->getMessage()]));
        } elseif ($e instanceof \InvalidArgumentException) {
            Flash::set('error', $e->getMessage()); // input errors are thrown already translated
        } elseif ($e instanceof \RuntimeException && str_starts_with($e->getMessage(), 'not connected')) {
            Flash::set('error', View::t('error.not_connected'));
        } else {
            Flash::set('error', View::t('error.unexpected', ['message' => $e->getMessage()]));
        }
    }

    /** @return list<array{type:string,message:string,link:?string,linkText:?string}> */
    private function setupErrorBanners(Settings $settings, string $modulelink): array
    {
        $banners = [];
        $mismatch = $settings->organizationMismatch();
        if ($mismatch !== null) {
            $banners[] = [
                'type' => 'danger',
                'message' => View::t('banner.organization_mismatch', ['new' => $mismatch['name'] !== '' ? $mismatch['name'] : $mismatch['publicId'], 'bound' => SettingsController::boundLabel($settings)]),
                'link' => View::url($modulelink, ['action' => 'settings']),
                'linkText' => View::t('banner.open_settings'),
            ];
        }
        $counts = Container::monitorMap()->errorCounts();
        foreach (self::SETUP_ERROR_BANNERS as $code => $key) {
            if (($counts[$code] ?? 0) > 0) {
                $banners[] = [
                    'type' => 'danger',
                    'message' => View::t($key, ['count' => $counts[$code], 'package' => $settings->defaultPackageType()]),
                    'link' => View::url($modulelink, ['action' => 'settings']),
                    'linkText' => View::t('banner.open_settings'),
                ];
            }
        }
        return $banners;
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        return is_array($value) ? array_values(array_map(self::string(...), array_filter($value, 'is_scalar'))) : [];
    }

    private static function string(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private static function int(mixed $value): int
    {
        return is_string($value) && preg_match('/^\d{1,18}$/', trim($value)) === 1 ? (int) trim($value) : (is_int($value) ? $value : 0);
    }
}
