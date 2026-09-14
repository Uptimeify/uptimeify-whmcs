<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Uptimeify;

final class Lang
{
    /** @return array<string,string> */
    public static function load(string $language): array
    {
        $dir = dirname(__DIR__) . '/lang/';
        $file = $dir . preg_replace('/[^a-z_-]/', '', strtolower($language)) . '.php';
        if (!is_file($file)) {
            $file = $dir . 'english.php';
        }
        $_ADDONLANG = [];
        require $file;
        /** @var array<string,string> $_ADDONLANG */
        return $_ADDONLANG;
    }

    /**
     * @param array<string,string> $lang
     * @param array<string,scalar> $vars
     */
    public static function t(array $lang, string $key, array $vars = []): string
    {
        $text = $lang[$key] ?? "[[{$key}]]";
        foreach ($vars as $name => $value) {
            $text = str_replace('%' . $name . '%', (string) $value, $text);
        }
        return $text;
    }
}
