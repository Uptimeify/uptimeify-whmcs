<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Uptimeify\Hooks;

use WHMCS\Module\Addon\Uptimeify\Bridge;
use WHMCS\Module\Addon\Uptimeify\Container;

/**
 * The only thing an object hook does (spec §7): write one queue row. Never an API call, never an exception into
 * WHMCS: every failure ends as one activity log line.
 */
final class Enqueue
{
    public static function job(string $job, int $refId): void
    {
        try {
            $settings = Container::settings();
            if (!$settings->isConnected() || !$settings->autoSync()) {
                return;
            }
            if ($refId <= 0) {
                Bridge::logActivity(sprintf('Hook for %s ignored: WHMCS passed no usable id', $job));
                return;
            }
            Container::queue()->push($job, $refId);
        } catch (\Throwable $e) {
            Bridge::logActivity(sprintf('Hook for %s #%d failed: %s', $job, $refId, $e->getMessage()));
        }
    }

    /**
     * The first positive integer found under one of the key paths, else 0. WHMCS passes ids as int or numeric string;
     * anything else (missing, array, garbage) counts as missing.
     *
     * @param array<mixed> $vars
     * @param list<string> ...$paths e.g. ['params', 'serviceid'], ['serviceid']
     */
    public static function id(array $vars, array ...$paths): int
    {
        foreach ($paths as $path) {
            $value = $vars;
            foreach ($path as $key) {
                if (!is_array($value) || !array_key_exists($key, $value)) {
                    continue 2;
                }
                $value = $value[$key];
            }
            if (is_int($value) && $value > 0) {
                return $value;
            }
            if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
                return (int) $value;
            }
        }
        return 0;
    }
}
