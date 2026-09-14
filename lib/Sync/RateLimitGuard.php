<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Uptimeify\Sync;

use WHMCS\Module\Addon\Uptimeify\Api\Exception\RateLimitedException;

/**
 * Retries an API call on HTTP 429: sleeps `Retry-After` (at most 60 s) and tries again, at most three
 * times, then rethrows (spec §8.3 step 8). Every other exception passes through untouched.
 *
 * An interactive guard (`$maxRetries = 0`, the admin buttons) never sleeps: a 429 inside an admin request is rethrown
 * at once and treated like a used-up time budget, instead of holding the request for up to three minutes.
 */
final class RateLimitGuard
{
    private const MAX_RETRIES = 3;
    private const MAX_SLEEP = 60;

    /** @var callable(int):void */
    private $sleep;

    /** @param (callable(int):void)|null $sleep */
    public function __construct(?callable $sleep = null, private readonly int $maxRetries = self::MAX_RETRIES)
    {
        $this->sleep = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    /**
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public function run(callable $fn): mixed
    {
        $attempt = 0;
        while (true) {
            try {
                return $fn();
            } catch (RateLimitedException $e) {
                if ($attempt++ >= $this->maxRetries) {
                    throw $e;
                }
                ($this->sleep)(max(1, min($e->retryAfterSeconds, self::MAX_SLEEP)));
            }
        }
    }
}
