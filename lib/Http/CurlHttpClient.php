<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Uptimeify\Http;

final class CurlHttpClient implements HttpClientInterface
{
    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): HttpResponse
    {
        $ch = curl_init();
        if ($ch === false) {
            throw new TransportException('curl_init failed');
        }
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        /** @var array<string,string> $responseHeaders */
        $responseHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT_MS => (int) round($timeout * 1000),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => static function (\CurlHandle $ch, string $line) use (&$responseHeaders): int {
                // A new status line starts a new header block (e.g. after "100 Continue"): keep only the last one.
                if (str_starts_with($line, 'HTTP/')) {
                    $responseHeaders = [];
                }
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[trim($parts[0])] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($ch);
        if ($raw === false) {
            throw new TransportException('Could not reach the API: ' . curl_error($ch));
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        return new HttpResponse($status, $responseHeaders, (string) $raw);
    }
}
