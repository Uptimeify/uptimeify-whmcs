<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Uptimeify\Api\Exception;

/**
 * HTTP 429 from the per-IP limiter. The API sends no `data.code` for it.
 */
final class RateLimitedException extends ApiException
{
    public function __construct(string $message, public readonly int $retryAfterSeconds)
    {
        parent::__construct($message, 429, 'rateLimited');
    }
}
