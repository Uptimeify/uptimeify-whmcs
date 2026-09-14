<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Uptimeify;

use WHMCS\Module\Addon\Uptimeify\Api\Client;
use WHMCS\Module\Addon\Uptimeify\Client\ClientSnapshot;
use WHMCS\Module\Addon\Uptimeify\Http\CurlHttpClient;
use WHMCS\Module\Addon\Uptimeify\Http\HttpClientInterface;
use WHMCS\Module\Addon\Uptimeify\Store\CustomerMap;
use WHMCS\Module\Addon\Uptimeify\Store\MonitorMap;
use WHMCS\Module\Addon\Uptimeify\Store\Queue;
use WHMCS\Module\Addon\Uptimeify\Store\Schema;
use WHMCS\Module\Addon\Uptimeify\Store\StatusCache;
use WHMCS\Module\Addon\Uptimeify\Store\SyncLock;
use WHMCS\Module\Addon\Uptimeify\Sync\CustomerFilter;
use WHMCS\Module\Addon\Uptimeify\Sync\CustomerMirror;
use WHMCS\Module\Addon\Uptimeify\Sync\JobHandler;
use WHMCS\Module\Addon\Uptimeify\Sync\MonitorPlanner;
use WHMCS\Module\Addon\Uptimeify\Sync\OrganizationBinding;
use WHMCS\Module\Addon\Uptimeify\Sync\QueueWorker;
use WHMCS\Module\Addon\Uptimeify\Sync\RateLimitGuard;
use WHMCS\Module\Addon\Uptimeify\Sync\Reconciler;
use WHMCS\Module\Addon\Uptimeify\Whmcs\ClientRepository;
use WHMCS\Module\Addon\Uptimeify\Whmcs\DomainRepository;
use WHMCS\Module\Addon\Uptimeify\Whmcs\HostingRepository;
use WHMCS\Module\Addon\Uptimeify\Whmcs\ServerRepository;

/**
 * Builds the object graph for one WHMCS request (PHP process).
 *
 * - ONE Settings instance per request: its per-instance cache would otherwise hide a write made through another
 *   instance, e.g. the worker's `connection_validated` reset after a 401.
 * - Everything else is built fresh on each call. The sync graphs (reconciler, jobHandler, queueWorker) share one
 *   Client, RateLimitGuard and Reconciler inside the graph, and the worker shares its Queue's clock.
 * - A token that cannot be decrypted or decrypts to '' is "no usable token": api() returns null, never throws.
 * - A stored base URL that fails Settings::isAllowedBaseUrl() never gets the token (spec §10).
 * - `$interactive` graphs (admin buttons) use a RateLimitGuard without retries: a 429 never sleeps inside an admin
 *   request; the caller treats it as the time budget used up and queues the rest (spec §9.1).
 * - The client area (clientSnapshot) gets the interactive guard, a shorter HTTP timeout and no API at all while the
 *   connection is not validated or the token belongs to another organization than the bound one.
 */
final class Container
{
    private static ?Settings $settings = null;

    private static ?HttpClientInterface $http = null;

    /** Tests inject a fake transport; null restores cURL. */
    public static function useHttp(?HttpClientInterface $http): void
    {
        self::$http = $http;
    }

    /** Forgets the shared Settings instance and any injected transport (tests, and after wiping the settings rows). */
    public static function reset(): void
    {
        self::$settings = null;
        self::$http = null;
    }

    public static function settings(): Settings
    {
        return self::$settings ??= new Settings();
    }

    /**
     * Null when there is no usable token or the stored base URL is not allowed.
     *
     * @param float|null $timeout HTTP timeout in seconds; null keeps the Client default
     */
    public static function api(?float $timeout = null): ?Client
    {
        $settings = self::settings();
        try {
            $token = $settings->getApiToken();
        } catch (\RuntimeException) {
            return null;
        }
        $baseUrl = $settings->apiBaseUrl();
        if ($token === null || $token === '' || !Settings::isAllowedBaseUrl($baseUrl)) {
            return null;
        }
        return $timeout === null ? new Client(self::http(), $baseUrl, $token) : new Client(self::http(), $baseUrl, $token, $timeout);
    }

    /** @throws \RuntimeException when api() is null */
    public static function apiOrFail(): Client
    {
        $api = self::api();
        if ($api !== null) {
            return $api;
        }
        throw new \RuntimeException(Settings::isAllowedBaseUrl(self::settings()->apiBaseUrl())
            ? 'not connected: no usable API token'
            : 'not connected: the API base URL is not allowed (https only; http only for localhost and host.docker.internal)');
    }

    /**
     * A client for a token that is not stored yet (the connect handshake), against the stored base URL or the one the
     * setup form posted (not stored until the handshake succeeded).
     *
     * @throws \RuntimeException when the base URL is not allowed
     */
    public static function apiWithToken(string $token, ?string $baseUrl = null): Client
    {
        $baseUrl ??= self::settings()->apiBaseUrl();
        if (!Settings::isAllowedBaseUrl($baseUrl)) {
            throw new \RuntimeException('the API base URL is not allowed (https only; http only for localhost and host.docker.internal)');
        }
        return new Client(self::http(), $baseUrl, $token);
    }

    public static function guard(bool $interactive = false): RateLimitGuard
    {
        return $interactive ? new RateLimitGuard(null, 0) : new RateLimitGuard();
    }

    /** The page-view snapshot of the client area (spec §9.3): bounded refresh, never sleeps on 429. */
    public static function clientSnapshot(): ClientSnapshot
    {
        $settings = self::settings();
        // No refresh while a run found the token of another organization (round 2, D9); the check itself never runs here.
        $api = $settings->isConnected() && $settings->organizationMismatch() === null ? self::api(ClientSnapshot::HTTP_TIMEOUT) : null;
        return new ClientSnapshot(self::statusCache(), self::customerMap(), self::monitorMap(), $api, self::guard(true), new SyncLock());
    }

    /**
     * The organization check of an admin run (round 2, D9), with the interactive guard.
     *
     * @throws \RuntimeException when not connected
     */
    public static function organizationBinding(): OrganizationBinding
    {
        return new OrganizationBinding(self::settings(), self::apiOrFail(), self::guard(true));
    }

    public static function queue(): Queue
    {
        return new Queue();
    }

    public static function statusCache(): StatusCache
    {
        return new StatusCache();
    }

    public static function customerMap(): CustomerMap
    {
        return new CustomerMap();
    }

    public static function monitorMap(): MonitorMap
    {
        return new MonitorMap();
    }

    public static function planner(): MonitorPlanner
    {
        $settings = self::settings();
        return new MonitorPlanner($settings, new CustomerFilter($settings), new ClientRepository(), new HostingRepository(), new DomainRepository(), new ServerRepository());
    }

    /** @throws \RuntimeException when not connected */
    public static function reconciler(bool $interactive = false): Reconciler
    {
        return self::buildReconciler(self::apiOrFail(), self::guard($interactive), self::queue());
    }

    /** @throws \RuntimeException when not connected */
    public static function jobHandler(bool $interactive = false): JobHandler
    {
        $api = self::apiOrFail();
        $guard = self::guard($interactive);
        return self::buildJobHandler(self::buildReconciler($api, $guard, self::queue()), $api, $guard);
    }

    /** @throws \RuntimeException when not connected */
    public static function queueWorker(): QueueWorker
    {
        $api = self::apiOrFail();
        $guard = self::guard();
        $clock = static fn (): string => Schema::now();
        $queue = new Queue($clock);
        $reconciler = self::buildReconciler($api, $guard, $queue);
        return new QueueWorker($queue, self::buildJobHandler($reconciler, $api, $guard), $api, $guard, new SyncLock(), $reconciler, self::settings(), $clock);
    }

    private static function buildReconciler(Client $api, RateLimitGuard $guard, Queue $queue): Reconciler
    {
        $settings = self::settings();
        $customerMap = self::customerMap();
        return new Reconciler(
            $api,
            $settings,
            $customerMap,
            self::monitorMap(),
            self::planner(),
            new CustomerMirror($api, $settings, $customerMap, $guard),
            self::statusCache(),
            new ClientRepository(),
            new HostingRepository(),
            new SyncLock(),
            $guard,
            null,
            $queue,
            new DomainRepository(),
        );
    }

    private static function buildJobHandler(Reconciler $reconciler, Client $api, RateLimitGuard $guard): JobHandler
    {
        return new JobHandler($reconciler, self::planner(), self::monitorMap(), new ClientRepository(), new HostingRepository(), $api, $guard, self::settings(), self::customerMap(), self::statusCache());
    }

    private static function http(): HttpClientInterface
    {
        return self::$http ?? new CurlHttpClient();
    }
}
