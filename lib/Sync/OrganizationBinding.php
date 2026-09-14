<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Uptimeify\Sync;

use WHMCS\Module\Addon\Uptimeify\Api\Client;
use WHMCS\Module\Addon\Uptimeify\Api\Exception\ApiException;
use WHMCS\Module\Addon\Uptimeify\Bridge;
use WHMCS\Module\Addon\Uptimeify\Settings;

/**
 * The installation is bound to one Uptimeify organization (round 2, D9): `organization_public_id` is stored on the first
 * successful connect, and every run (cron reconcile, queue worker, admin reconcile, "Ausgewählte synchronisieren",
 * "Status aktualisieren") checks it once with `GET /api/organization` (`publicId`, docs/api-surface.md) through the
 * run's own rate-limit guard (the interactive one in admin requests). Never called from the client area.
 *
 * On a mismatch the run stops before any other request, `organization_mismatch` records the token's organization for
 * the admin banner (logged when it starts), and the binding stays. A later run that sees the bound organization again
 * clears the record. Only the admin moves the binding ("Organisation wechseln").
 */
final class OrganizationBinding
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Client $api,
        private readonly RateLimitGuard $guard,
    ) {
    }

    /**
     * @throws OrganizationMismatchException
     * @throws ApiException also for a response without `publicId`
     */
    public function verify(): void
    {
        $organization = self::identity($this->guard->run(fn (): array => $this->api->getOrganization()));
        $bound = $this->settings->organizationPublicId();
        if ($bound === '') {
            // Connected before a binding existed: the first run binds.
            $this->settings->set('organization_public_id', $organization['publicId']);
            if ($organization['name'] !== '') {
                $this->settings->set('organization_name', $organization['name']);
            }
            return;
        }
        if ($bound === $organization['publicId']) {
            if ($this->settings->organizationMismatch() !== null) {
                $this->settings->set('organization_mismatch', null);
                Bridge::logActivity('The API token belongs to the bound organization again; synchronisation continues');
            }
            return;
        }
        if ($this->settings->organizationMismatch() !== $organization) {
            $this->settings->setJson('organization_mismatch', $organization);
            Bridge::logActivity(sprintf('Synchronisation stopped: the API token belongs to another organization (%s, %s) than the bound one (%s). Set the token again or switch the organization in the module settings.', $organization['name'], $organization['publicId'], $bound));
        }
        throw new OrganizationMismatchException($bound, $organization['publicId'], $organization['name']);
    }

    /**
     * `publicId` and `name` of a `GET /api/organization` response.
     *
     * @param array<mixed> $organization
     * @return array{publicId:string,name:string}
     * @throws ApiException when `publicId` is missing
     */
    public static function identity(array $organization): array
    {
        $publicId = $organization['publicId'] ?? null;
        if (!is_string($publicId) || trim($publicId) === '') {
            throw new ApiException('uptimeify API returned an unexpected response for GET /api/organization: no publicId', 0, Client::INVALID_RESPONSE_CODE);
        }
        $name = $organization['name'] ?? null;
        return ['publicId' => trim($publicId), 'name' => is_string($name) ? trim($name) : ''];
    }
}
