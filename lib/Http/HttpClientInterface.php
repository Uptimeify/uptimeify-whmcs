<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Uptimeify\Http;

interface HttpClientInterface
{
    /**
     * @param array<string,string> $headers
     * @throws TransportException when the request could not be sent or no response arrived
     */
    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): HttpResponse;
}
