<?php

declare(strict_types=1);

/*
 * PSR-4 loader for WHMCS\Module\Addon\Uptimeify\ → lib/. Insurance: WHMCS autoloads lib/ of an addon by convention,
 * but the module also works if that convention ever changes.
 *
 * Its own file (loaded with require_once by uptimeify.php and hooks.php) instead of hooks.php including uptimeify.php:
 * if WHMCS ever included the module file without _once after hooks.php, its functions would be declared twice (fatal).
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'WHMCS\\Module\\Addon\\Uptimeify\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
