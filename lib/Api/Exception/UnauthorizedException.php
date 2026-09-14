<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Uptimeify\Api\Exception;

/**
 * HTTP 401: token missing, unknown or expired.
 */
final class UnauthorizedException extends ApiException
{
}
