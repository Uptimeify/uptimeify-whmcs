<?php

declare(strict_types=1);

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\Uptimeify\Admin\Dispatcher;
use WHMCS\Module\Addon\Uptimeify\Admin\View;
use WHMCS\Module\Addon\Uptimeify\Bridge;
use WHMCS\Module\Addon\Uptimeify\Client\Dispatcher as ClientDispatcher;
use WHMCS\Module\Addon\Uptimeify\Container;
use WHMCS\Module\Addon\Uptimeify\Lang;
use WHMCS\Module\Addon\Uptimeify\Store\Schema;
use WHMCS\Module\Addon\Uptimeify\Version;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/autoload.php';

/** @return array<string,mixed> */
function uptimeify_config(): array
{
    return [
        'name' => 'Uptimeify',
        'description' => 'Mirrors clients, hosting domains and registered domains to uptimeify.io uptime, domain-expiry and blacklist monitoring.',
        'author' => '<a href="https://uptimeify.io" target="_blank" rel="noopener">Uptimeify</a>',
        'language' => 'english',
        'version' => Version::VERSION,
        'fields' => [],
    ];
}

/**
 * Creates the module tables (spec §6). Settings live in tbladdonmodules and need no setup.
 *
 * @return array{status:string,description:string}
 */
function uptimeify_activate(): array
{
    $lang = Lang::load(Bridge::adminLanguage());
    try {
        Schema::install();
        return ['status' => 'success', 'description' => Lang::t($lang, 'lifecycle.activated')];
    } catch (\Throwable $e) {
        return ['status' => 'error', 'description' => Lang::t($lang, 'lifecycle.activate_failed', ['message' => $e->getMessage()])];
    }
}

/**
 * Keeps tables and settings unless `drop_data_on_deactivate` is set (spec §6).
 *
 * @return array{status:string,description:string}
 */
function uptimeify_deactivate(): array
{
    $lang = Lang::load(Bridge::adminLanguage());
    try {
        if (!Container::settings()->dropDataOnDeactivate()) {
            return ['status' => 'success', 'description' => Lang::t($lang, 'lifecycle.deactivated_kept')];
        }
        Schema::drop();
        Capsule::table('tbladdonmodules')->where('module', Bridge::MODULE)->delete();
        Container::reset();
        return ['status' => 'success', 'description' => Lang::t($lang, 'lifecycle.deactivated_dropped')];
    } catch (\Throwable $e) {
        return ['status' => 'error', 'description' => Lang::t($lang, 'lifecycle.deactivate_failed', ['message' => $e->getMessage()])];
    }
}

/**
 * WHMCS passes the previously installed version as `version`. Every step is idempotent (spec §6, §11).
 *
 * @param array<string,mixed> $vars
 */
function uptimeify_upgrade(array $vars): void
{
    $version = $vars['version'] ?? '0.0.0';
    Schema::upgrade(is_scalar($version) ? (string) $version : '0.0.0');
}

/**
 * Admin area (spec §9.1). WHMCS decides who may open the addon (its per-addon access control); every write is a POST
 * that the dispatcher checks with check_token('WHMCS.admin.default') and answers with a redirect.
 *
 * @param array<string,mixed> $vars
 */
function uptimeify_output(array $vars): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $out = (new Dispatcher())->handle($vars, $_GET, $_POST, is_string($method) ? $method : 'GET');
    if (str_starts_with($out, 'REDIRECT:')) {
        header('Location: ' . substr($out, strlen('REDIRECT:')));
        exit;
    }
    echo $out;
}

/**
 * Admin sidebar of the addon page: brand, organization (or "not connected") and the tabs.
 *
 * @param array<string,mixed> $vars
 */
function uptimeify_sidebar(array $vars): string
{
    $settings = Container::settings();
    $modulelink = is_string($vars['modulelink'] ?? null) ? $vars['modulelink'] : 'addonmodules.php?module=' . Bridge::MODULE;
    $organization = $settings->isConnected() ? $settings->organizationName() : View::t('sidebar.not_connected');
    $html = '<div class="sidebar-header"><i class="fas fa-heartbeat"></i> ' . View::e($settings->brandName()) . '</div>'
        . '<ul class="menu"><li><span class="text-muted">' . View::e($organization) . '</span></li>';
    if ($settings->isConnected()) {
        foreach (Dispatcher::TABS as $tab) {
            $html .= '<li><a href="' . View::e(View::url($modulelink, ['action' => $tab])) . '">' . View::e(View::t('tabs.' . $tab)) . '</a></li>';
        }
    }
    return $html . '</ul>';
}

/**
 * Client area `index.php?m=uptimeify` (spec §9.3), read only. The client id comes from the session inside the
 * dispatcher, never from `$vars` or the request.
 *
 * @param array<string,mixed> $vars
 * @return array<string,mixed>
 */
function uptimeify_clientarea(array $vars): array
{
    return (new ClientDispatcher())->clientarea($vars);
}
