<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Uptimeify;

final class Version
{
    public const VERSION = '0.1.0';

    public static function userAgent(): string
    {
        return 'uptimeify-whmcs/' . self::VERSION;
    }
}
