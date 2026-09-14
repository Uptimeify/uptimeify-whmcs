<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Uptimeify\Sync;

/**
 * Reduces a URL, hostname or IP to the comparable host (spec §8.1): lowercase, without scheme, credentials,
 * port, path, query, fragment, trailing dot and one leading `www.` label. IPv6 addresses lose their brackets
 * and are written in canonical short form. Lowercasing is UTF-8 aware (like the API's own domain normalization);
 * Unicode IDNs are not converted to punycode.
 */
final class TargetNormalizer
{
    public static function host(string $urlOrDomain): string
    {
        $value = mb_strtolower(trim($urlOrDomain), 'UTF-8');
        if ($value === '') {
            return '';
        }
        if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return self::ipv6($value);
        }
        if (preg_match('~^[a-z][a-z0-9+.\-]*://~', $value) !== 1) {
            $value = 'http://' . $value;
        }
        $host = parse_url($value, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return '';
        }
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $ip = substr($host, 1, -1);
            return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? self::ipv6($ip) : '';
        }
        $host = rtrim($host, '.');
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }
        return $host;
    }

    private static function ipv6(string $ip): string
    {
        $packed = inet_pton($ip);
        $canonical = $packed === false ? false : inet_ntop($packed);
        return $canonical === false ? $ip : $canonical;
    }
}
