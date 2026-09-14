<?php

declare(strict_types=1);

namespace Uptimeify\Tests\Fake;

use WHMCS\Module\Addon\Uptimeify\Http\HttpClientInterface;
use WHMCS\Module\Addon\Uptimeify\Http\HttpResponse;

/**
 * Not final: Client and Reconciler tests extend it with anonymous classes (preflight T4-2).
 */
class FakeHttpClient implements HttpClientInterface
{
    /** @var list<array{method:string,path:string,status:int,body:string,headers:array<string,string>}> */
    private array $routes = [];
    /** @var list<array{method:string,url:string,headers:array<string,string>,body:?string}> */
    private array $requests = [];

    /**
     * @param array<mixed>|string $body
     * @param array<string,string> $headers
     */
    public function on(string $method, string $pathOrRegex, int $status, array|string $body, array $headers = []): self
    {
        $this->routes[] = [
            'method' => strtoupper($method),
            'path' => $pathOrRegex,
            'status' => $status,
            'body' => is_array($body) ? (string) json_encode($body) : $body,
            'headers' => $headers,
        ];
        return $this;
    }

    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): HttpResponse
    {
        $this->requests[] = ['method' => strtoupper($method), 'url' => $url, 'headers' => $headers, 'body' => $body];
        $path = (string) parse_url($url, PHP_URL_PATH);
        foreach ($this->routes as $route) {
            if ($route['method'] !== strtoupper($method)) {
                continue;
            }
            $isRegex = str_starts_with($route['path'], '#');
            if (($isRegex && preg_match($route['path'], $path) === 1) || (!$isRegex && $route['path'] === $path)) {
                return new HttpResponse($route['status'], $route['headers'], $route['body']);
            }
        }
        throw new \LogicException("FakeHttpClient: no route for {$method} {$url}");
    }

    /** @return list<array{method:string,url:string,headers:array<string,string>,body:?string}> */
    public function requests(): array
    {
        return $this->requests;
    }

    /** @return array<mixed>|null decoded JSON body of the last request */
    public function lastJson(): ?array
    {
        $last = end($this->requests);
        if ($last === false || $last['body'] === null) {
            return null;
        }
        $decoded = json_decode($last['body'], true);
        return is_array($decoded) ? $decoded : null;
    }
}
