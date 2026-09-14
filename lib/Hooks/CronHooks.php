<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Uptimeify\Hooks;

use WHMCS\Module\Addon\Uptimeify\Bridge;
use WHMCS\Module\Addon\Uptimeify\Container;

/**
 * Cron hooks (spec §7): AfterCronJob drains the queue, DailyCronJob runs the full reconcile.
 *
 * Gate: the same `isConnected() && autoSync()` the queue worker checks, so neither a disconnected nor a paused
 * installation touches the API (the Reconciler itself has no gate: the admin button runs it on purpose). A stored
 * token that is unusable (not decryptable, empty) or a base URL that is not allowed resets `connection_validated`
 * with one log line, like a 401: the admin sees the setup form again and later crons stay quiet.
 */
final class CronHooks
{
    public static function afterCronJob(): void
    {
        self::guarded('AfterCronJob', static function (): void {
            Container::queueWorker()->run();
        });
    }

    public static function dailyCronJob(): void
    {
        self::guarded('DailyCronJob', static function (): void {
            Container::reconciler()->run();
        });
    }

    /** @param callable():void $fn */
    private static function guarded(string $hook, callable $fn): void
    {
        try {
            $settings = Container::settings();
            if (!$settings->isConnected() || !$settings->autoSync()) {
                return;
            }
            if (Container::api() === null) {
                $settings->setBool('connection_validated', false);
                Bridge::logActivity(sprintf('%s skipped: no usable API token or the API base URL is not allowed; connection reset, set the token again in the module', $hook));
                return;
            }
            $fn();
        } catch (\Throwable $e) {
            Bridge::logActivity(sprintf('%s cron hook failed: %s', $hook, $e->getMessage()));
        }
    }
}
