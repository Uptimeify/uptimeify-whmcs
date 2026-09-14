<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Uptimeify\Hooks;

use WHMCS\Module\Addon\Uptimeify\Bridge;
use WHMCS\Module\Addon\Uptimeify\Client\MonitoringController;
use WHMCS\Module\Addon\Uptimeify\Container;
use WHMCS\Module\Addon\Uptimeify\Lang;
use WHMCS\View\Menu\Item;

/**
 * Client-area hooks (spec §7): the "Monitoring" navigation item and the product details block. Both take the client
 * from the session only and never break the WHMCS page: any error is logged and the hook adds nothing.
 */
final class NavigationHooks
{
    public const NAV_ITEM = 'uptimeifyMonitoring';

    /**
     * Adds "Monitoring" under Services (at the root when the theme has no Services menu), only for mapped clients and only
     * for a user who may view the account's products and services (round 2, D10).
     */
    public static function primaryNavbar(Item $primaryNavbar): void
    {
        try {
            $clientId = Bridge::currentClientId();
            // The customer map is a cheap local lookup; the permission check may cost a localAPI call, so it comes last.
            if ($clientId === null || Container::customerMap()->get($clientId) === null || !Bridge::currentUserCanViewProducts()) {
                return;
            }
            $lang = Lang::load(Bridge::clientLanguage());
            ($primaryNavbar->getChild('Services') ?? $primaryNavbar)->addChild(self::NAV_ITEM, [
                'label' => Lang::t($lang, 'nav.monitoring'),
                'uri' => MonitoringController::MODULE_LINK,
                'order' => 90,
                'icon' => 'fas fa-heartbeat',
            ]);
        } catch (\Throwable $e) {
            Bridge::logActivity('client area navigation hook failed: ' . $e->getMessage());
        }
    }

    /**
     * WHMCS passes the service model as `service`; `serviceid` is accepted as well. Either id is only a lookup key:
     * the controller shows the block only when that service belongs to the logged-in client. Nothing for a user without the
     * permission to view products and services (round 2, D10).
     *
     * @param array<string,mixed> $vars
     */
    public static function productDetailsOutput(array $vars): string
    {
        try {
            $clientId = Bridge::currentClientId();
            if ($clientId === null || Container::customerMap()->get($clientId) === null || !Bridge::currentUserCanViewProducts()) {
                return '';
            }
            $service = $vars['service'] ?? null;
            $id = is_object($service) && isset($service->id) ? $service->id : ($vars['serviceid'] ?? null);
            $serviceId = is_int($id) || (is_string($id) && ctype_digit($id)) ? (int) $id : 0;
            return MonitoringController::fromContainer()->productBlock($clientId, $serviceId, Lang::load(Bridge::clientLanguage()));
        } catch (\Throwable $e) {
            Bridge::logActivity('client area product block hook failed: ' . $e->getMessage());
            return '';
        }
    }
}
