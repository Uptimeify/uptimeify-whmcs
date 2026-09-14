<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Uptimeify\Api\Exception;

/**
 * HTTP 403 with a monitor quota code, or the IP limit (see Client::QUOTA_ERROR_CODES).
 */
final class QuotaExceededException extends ApiException
{
}
