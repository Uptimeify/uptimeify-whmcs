<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Uptimeify\Http;

final class HttpResponse
{
    /** @var array<string,string> lower-cased header names */
    private array $headers = [];

    /** @param array<string,string> $headers */
    public function __construct(public readonly int $status, array $headers, public readonly string $body)
    {
        foreach ($headers as $name => $value) {
            $this->headers[strtolower($name)] = $value;
        }
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** @return array<mixed>|null */
    public function json(): ?array
    {
        if (trim($this->body) === '') {
            return null;
        }
        $decoded = json_decode($this->body, true);
        return is_array($decoded) ? $decoded : null;
    }
}
