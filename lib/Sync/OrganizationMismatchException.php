<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Uptimeify\Sync;

/**
 * The API token belongs to another Uptimeify organization than the one this installation is bound to (round 2, D9).
 * Stops a run before it reads or writes anything; the admin sets the right token again or switches the organization.
 */
final class OrganizationMismatchException extends \RuntimeException
{
    public function __construct(
        public readonly string $boundPublicId,
        public readonly string $publicId,
        public readonly string $name,
    ) {
        parent::__construct(sprintf('the API token belongs to organization %s (%s), but this installation is bound to organization %s', $publicId, $name, $boundPublicId));
    }
}
