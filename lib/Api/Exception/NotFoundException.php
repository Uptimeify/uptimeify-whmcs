<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Uptimeify\Api\Exception;

/**
 * HTTP 404: the resource does not exist or is outside the token scope (with or without data.code).
 */
final class NotFoundException extends ApiException
{
}
