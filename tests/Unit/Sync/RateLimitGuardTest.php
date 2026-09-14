<?php

declare(strict_types=1);

namespace Uptimeify\Tests\Unit\Sync;

use PHPUnit\Framework\TestCase;
use WHMCS\Module\Addon\Uptimeify\Api\Exception\ApiException;
use WHMCS\Module\Addon\Uptimeify\Api\Exception\RateLimitedException;
use WHMCS\Module\Addon\Uptimeify\Sync\RateLimitGuard;

final class RateLimitGuardTest extends TestCase
{
    public function testRetriesWithCappedSleepThenRethrows(): void
    {
        $sleeps = [];
        $guard = new RateLimitGuard(function (int $s) use (&$sleeps): void {
            $sleeps[] = $s;
        });
        $calls = 0;
        $thrown = false;
        try {
            $guard->run(function () use (&$calls): void {
                $calls++;
                throw new RateLimitedException('slow', 120);
            });
        } catch (RateLimitedException) {
            $thrown = true;
        }
        self::assertTrue($thrown, 'the last RateLimitedException is rethrown');
        self::assertSame(4, $calls);
        self::assertSame([60, 60, 60], $sleeps);
    }

    public function testReturnsValueOnSuccessAfterRetry(): void
    {
        $sleeps = [];
        $guard = new RateLimitGuard(function (int $s) use (&$sleeps): void {
            $sleeps[] = $s;
        });
        $n = 0;
        $v = $guard->run(function () use (&$n): string {
            if ($n++ === 0) {
                throw new RateLimitedException('x', 1);
            }
            return 'ok';
        });
        self::assertSame('ok', $v);
        self::assertSame([1], $sleeps);
    }

    public function testOtherApiErrorsAreNotRetried(): void
    {
        $guard = new RateLimitGuard(function (int $s): void {
            self::fail('no sleep for a non-429 error');
        });
        $calls = 0;
        $this->expectException(ApiException::class);
        try {
            $guard->run(function () use (&$calls): void {
                $calls++;
                throw new ApiException('boom', 500, 'generic');
            });
        } finally {
            self::assertSame(1, $calls);
        }
    }

    public function testZeroRetriesRethrowsTheFirst429WithoutSleeping(): void
    {
        $guard = new RateLimitGuard(function (int $s): void {
            self::fail('an interactive guard never sleeps');
        }, 0);
        $calls = 0;
        $this->expectException(RateLimitedException::class);
        try {
            $guard->run(function () use (&$calls): void {
                $calls++;
                throw new RateLimitedException('slow', 60);
            });
        } finally {
            self::assertSame(1, $calls);
        }
    }
}
