<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Uptimeify\Client;

use WHMCS\Module\Addon\Uptimeify\Bridge;
use WHMCS\Module\Addon\Uptimeify\Container;
use WHMCS\Module\Addon\Uptimeify\Lang;
use WHMCS\Module\Addon\Uptimeify\Settings;

/**
 * `uptimeify_clientarea` (spec §9.3): the client id comes from the logged-in session only (Bridge::currentClientId),
 * never from `$vars` or the request; `requirelogin` makes WHMCS send visitors to the login first. A WHMCS user without
 * the permission to view the account's products and services (round 2, D10) gets a plain "no permission" page and no
 * data is loaded for it. Nothing here writes
 * to the API. An unexpected error shows "temporarily unavailable" and goes to the activity log, never to the page.
 */
final class Dispatcher
{
    /**
     * @param array<string,mixed> $vars ignored on purpose: no client id is taken from it
     * @return array<string,mixed>
     */
    public function clientarea(array $vars): array
    {
        $lang = Lang::load(Bridge::clientLanguage());
        $title = Lang::t($lang, 'nav.monitoring');
        try {
            $clientId = Bridge::currentClientId();
            $page = match (true) {
                $clientId === null => MonitoringController::baseVars(MonitoringController::STATE_NOT_LOGGED_IN, Container::settings()->brandName(), $lang),
                // Checked before anything is loaded or refreshed (round 2, D10).
                !Bridge::currentUserCanViewProducts() => MonitoringController::baseVars(MonitoringController::STATE_NO_PERMISSION, Container::settings()->brandName(), $lang),
                default => MonitoringController::fromContainer()->overview($clientId, $lang),
            };
        } catch (\Throwable $e) {
            Bridge::logActivity('client area page failed: ' . $e->getMessage());
            try {
                $brand = Container::settings()->brandName();
            } catch (\Throwable) {
                $brand = Settings::DEFAULT_BRAND;
            }
            $page = MonitoringController::baseVars(MonitoringController::STATE_UNAVAILABLE, $brand, $lang);
        }
        return [
            'pagetitle' => $title,
            'breadcrumb' => [MonitoringController::MODULE_LINK => $title],
            'templatefile' => 'overview',
            'requirelogin' => true,
            'vars' => $page,
        ];
    }
}
