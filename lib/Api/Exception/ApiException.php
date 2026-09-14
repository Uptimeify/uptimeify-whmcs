<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Uptimeify\Api\Exception;

/**
 * Any failed API call. `$status` is the HTTP status (0 when no usable response arrived), `$errorCode` the
 * API's `data.code` ('' when the response carried none) or a client-side code: 'transport'
 * (network failure), 'invalidResponse' (2xx not in the verified shape), 'invalidRequest' (body not
 * encodable, nothing sent).
 * Named `errorCode`, not `code`: `Exception::$code` cannot be redeclared (preflight T4-1).
 */
class ApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly string $errorCode = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }
}
