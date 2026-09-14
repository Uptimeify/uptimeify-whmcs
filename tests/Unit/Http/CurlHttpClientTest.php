<?php

declare(strict_types=1);

namespace Uptimeify\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use WHMCS\Module\Addon\Uptimeify\Http\CurlHttpClient;
use WHMCS\Module\Addon\Uptimeify\Http\TransportException;

/**
 * Offline: talks only to a built-in PHP server on 127.0.0.1 started for this test class.
 */
final class CurlHttpClientTest extends TestCase
{
    /** @var resource|null */
    private static $server = null;
    private static int $port = 0;

    public static function setUpBeforeClass(): void
    {
        self::$port = self::freePort();
        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, __DIR__ . '/../../Fixtures/http-echo.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            self::fail('Could not start the built-in PHP server');
        }
        self::$server = $process;
        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline) {
            $socket = @fsockopen('127.0.0.1', self::$port, $errno, $errstr, 0.1);
            if ($socket !== false) {
                fclose($socket);
                return;
            }
            usleep(50_000);
        }
        self::fail('Built-in PHP server did not come up');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            proc_terminate(self::$server);
            proc_close(self::$server);
            self::$server = null;
        }
    }

    public function testSendsMethodHeadersBodyAndReadsResponse(): void
    {
        $r = (new CurlHttpClient())->send(
            'post',
            'http://127.0.0.1:' . self::$port . '/api/customers?status=429',
            ['X-Test' => 'yes', 'Content-Type' => 'application/json'],
            '{"name":"Acme"}',
            5.0,
        );

        self::assertSame(429, $r->status);
        self::assertSame('7', $r->header('retry-after'));
        self::assertSame(
            ['method' => 'POST', 'path' => '/api/customers', 'xTest' => 'yes', 'body' => '{"name":"Acme"}'],
            $r->json(),
        );
    }

    public function testGetWithoutBody(): void
    {
        $r = (new CurlHttpClient())->send('GET', 'http://127.0.0.1:' . self::$port . '/api/organization', [], null, 5.0);

        self::assertSame(200, $r->status);
        self::assertSame('application/json', $r->header('Content-Type'));
        self::assertSame(['method' => 'GET', 'path' => '/api/organization', 'xTest' => null, 'body' => ''], $r->json());
    }

    public function testUnreachableHostThrowsTransportException(): void
    {
        $this->expectException(TransportException::class);
        (new CurlHttpClient())->send('GET', 'http://127.0.0.1:' . self::freePort() . '/', [], null, 2.0);
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            self::fail('Could not allocate a local port: ' . $errstr);
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }
}
