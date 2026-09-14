<?php

declare(strict_types=1);

use WHMCS\Module\Addon\Uptimeify\Hooks\ClientHooks;
use WHMCS\Module\Addon\Uptimeify\Hooks\CronHooks;
use WHMCS\Module\Addon\Uptimeify\Hooks\DomainHooks;
use WHMCS\Module\Addon\Uptimeify\Hooks\NavigationHooks;
use WHMCS\Module\Addon\Uptimeify\Hooks\ServiceHooks;
use WHMCS\Module\Addon\Uptimeify\Widget\StatusWidget;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/autoload.php';

// Spec §7: object hooks only write queue rows; the cron hooks do the API work.
add_hook('ClientAdd', 1, [ClientHooks::class, 'onClientAdd']);
add_hook('ClientEdit', 1, [ClientHooks::class, 'onClientEdit']);
add_hook('ClientClose', 1, [ClientHooks::class, 'onClientClose']);
add_hook('ClientDelete', 1, [ClientHooks::class, 'onClientDelete']);

add_hook('AfterModuleCreate', 1, [ServiceHooks::class, 'onAfterModuleCreate']);
add_hook('AfterModuleSuspend', 1, [ServiceHooks::class, 'onAfterModuleSuspend']);
add_hook('AfterModuleUnsuspend', 1, [ServiceHooks::class, 'onAfterModuleUnsuspend']);
add_hook('AfterModuleTerminate', 1, [ServiceHooks::class, 'onAfterModuleTerminate']);
add_hook('ServiceEdit', 1, [ServiceHooks::class, 'onServiceEdit']);
add_hook('ServiceDelete', 1, [ServiceHooks::class, 'onServiceDelete']);

add_hook('AfterRegistrarRegistration', 1, [DomainHooks::class, 'onAfterRegistrarRegistration']);
add_hook('AfterRegistrarTransfer', 1, [DomainHooks::class, 'onAfterRegistrarTransfer']);
add_hook('DomainTransferCompleted', 1, [DomainHooks::class, 'onDomainTransferCompleted']);
add_hook('DomainEdit', 1, [DomainHooks::class, 'onDomainEdit']);
add_hook('DomainDelete', 1, [DomainHooks::class, 'onDomainDelete']);

add_hook('AfterCronJob', 1, [CronHooks::class, 'afterCronJob']);
add_hook('DailyCronJob', 1, [CronHooks::class, 'dailyCronJob']);

add_hook('ClientAreaPrimaryNavbar', 1, [NavigationHooks::class, 'primaryNavbar']);
add_hook('ClientAreaProductDetailsOutput', 1, [NavigationHooks::class, 'productDetailsOutput']);
add_hook('AdminHomeWidgets', 1, static fn (): StatusWidget => new StatusWidget());
