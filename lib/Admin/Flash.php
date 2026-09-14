<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Uptimeify\Admin;

/**
 * One message carried across the POST → redirect → GET of an admin action, in the WHMCS admin session.
 * Types: success, warning, error.
 */
final class Flash
{
    private const KEY = 'uptimeify_flash';

    public static function set(string $type, string $message): void
    {
        $_SESSION[self::KEY] = ['type' => $type, 'message' => $message];
    }

    /** @return array{type:string,message:string}|null */
    public static function peek(): ?array
    {
        $flash = $_SESSION[self::KEY] ?? null;
        return is_array($flash) && is_string($flash['type'] ?? null) && is_string($flash['message'] ?? null)
            ? ['type' => $flash['type'], 'message' => $flash['message']]
            : null;
    }

    /** @return array{type:string,message:string}|null */
    public static function pull(): ?array
    {
        $flash = self::peek();
        unset($_SESSION[self::KEY]);
        return $flash;
    }
}
