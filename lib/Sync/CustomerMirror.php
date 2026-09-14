<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Uptimeify\Sync;

use WHMCS\Module\Addon\Uptimeify\Api\Client;
use WHMCS\Module\Addon\Uptimeify\Api\Exception\ApiException;
use WHMCS\Module\Addon\Uptimeify\Api\Exception\NotFoundException;
use WHMCS\Module\Addon\Uptimeify\Api\Exception\RateLimitedException;
use WHMCS\Module\Addon\Uptimeify\Api\Exception\UnauthorizedException;
use WHMCS\Module\Addon\Uptimeify\Bridge;
use WHMCS\Module\Addon\Uptimeify\Settings;
use WHMCS\Module\Addon\Uptimeify\Store\CustomerMap;
use WHMCS\Module\Addon\Uptimeify\Whmcs\ClientRow;

/**
 * Resolves the Uptimeify customer of a WHMCS client (spec §8.2): stored mapping, `customFields.whmcs_client_id`,
 * email, name, else create. An existing customer's package is never changed.
 */
final class CustomerMirror
{
    public const SOURCE_ID = 'matched_id';
    public const SOURCE_EMAIL = 'matched_email';
    public const SOURCE_NAME = 'matched_name';
    public const SOURCE_CREATED = 'created';
    public const SOURCE_MANUAL = 'manual';

    /** Message of the RuntimeException thrown when a customer must be created and no default package is set. */
    public const NO_DEFAULT_PACKAGE_MESSAGE = 'default package not configured';

    public function __construct(
        private readonly Client $api,
        private readonly Settings $settings,
        private readonly CustomerMap $map,
        private readonly RateLimitGuard $guard,
    ) {
    }

    /**
     * Returns the customer public id, or null when nothing matches and `$autoCreate` is off.
     *
     * A stored mapping whose customer is missing from the index is confirmed with a direct GET first: offset
     * paging can skip a row, so only a 404 lets the client fall through to matching or creation.
     *
     * `$result` (optional) counts a created customer and receives conflict messages.
     *
     * @throws ApiException on a failed lookup or create (quota, validation, 401, 429 after retries)
     * @throws \RuntimeException when a customer must be created and no default package is configured
     */
    public function resolve(ClientRow $client, RemoteIndex $index, bool $autoCreate, ?ReconcileResult $result = null): ?string
    {
        $stored = $this->map->get($client->id);
        if ($stored !== null) {
            $publicId = $stored['customer_public_id'];
            $customer = $index->customerByPublicId($publicId) ?? $this->fetchCustomer($publicId, $index);
            if ($customer !== null) {
                if (in_array($stored['source'], [self::SOURCE_EMAIL, self::SOURCE_NAME], true)
                    && RemoteIndex::whmcsIdOf($customer) === null
                    && $this->map->clientIdsFor($publicId) === [$client->id]) {
                    $this->tagWithWhmcsId($customer, $client->id, $index);
                }
                return $publicId;
            }
        }

        [$matched, $source] = $this->match($client, $index, $result);
        if ($matched !== null) {
            $publicId = (string) $matched['publicId'];
            $this->map->set($client->id, $publicId, self::packageOf($matched), $source);
            if ($source !== self::SOURCE_ID && RemoteIndex::whmcsIdOf($matched) === null) {
                $this->tagWithWhmcsId($matched, $client->id, $index);
            }
            return $publicId;
        }

        if ($stored !== null) {
            $this->map->remove($client->id);
            Bridge::logActivity(sprintf('Customer %s of WHMCS client #%d no longer exists, mapping removed', $stored['customer_public_id'], $client->id), $client->id);
        }
        return $autoCreate ? $this->create($client, $index, $result) : null;
    }

    /**
     * A customer tagged with this client's `whmcs_client_id` that customer_map already assigns to another client is
     * a conflict, not a match: taking it would let two clients see the same monitors (spec §10). It is logged and
     * skipped; the email/name candidates below skip it too.
     *
     * @return array{0: array<string,mixed>|null, 1: string}
     */
    private function match(ClientRow $client, RemoteIndex $index, ?ReconcileResult $result): array
    {
        $matched = $index->customerByWhmcsId($client->id);
        if ($matched !== null) {
            $publicId = (string) $matched['publicId'];
            $owners = array_values(array_diff($this->map->clientIdsFor($publicId), [$client->id]));
            if ($owners === []) {
                return [$matched, self::SOURCE_ID];
            }
            $message = sprintf(
                'Conflict: customer %s carries whmcs_client_id %d but is mapped to WHMCS client #%s; not used for client #%d',
                $publicId,
                $client->id,
                implode(', #', $owners),
                $client->id,
            );
            Bridge::logActivity($message, $client->id);
            if ($result !== null) {
                $result->messages[] = $message;
            }
        }
        $matched = $this->available($index->customersByEmail($client->email), $client->id);
        if ($matched !== null) {
            return [$matched, self::SOURCE_EMAIL];
        }
        return [$this->available($index->customersByName($client->displayName), $client->id), self::SOURCE_NAME];
    }

    /**
     * First email/name candidate that belongs to no other WHMCS client: neither tagged with another
     * `whmcs_client_id` nor held in customer_map by another client (a manual mapping, or an earlier match
     * whose back-fill PATCH failed). Otherwise two clients would share one customer and see each other's
     * monitors (spec §10).
     *
     * @param list<array<string,mixed>> $candidates
     * @return array<string,mixed>|null
     */
    private function available(array $candidates, int $clientId): ?array
    {
        foreach ($candidates as $customer) {
            $tagged = RemoteIndex::whmcsIdOf($customer);
            if ($tagged !== null && $tagged !== $clientId) {
                continue;
            }
            $owners = array_diff($this->map->clientIdsFor((string) $customer['publicId']), [$clientId]);
            if ($owners === []) {
                return $customer;
            }
        }
        return null;
    }

    /**
     * Direct GET for a mapped customer the list walk did not return; null only on 404.
     *
     * @return array<string,mixed>|null
     */
    private function fetchCustomer(string $publicId, RemoteIndex $index): ?array
    {
        try {
            /** @var array<string,mixed> $customer */
            $customer = $this->guard->run(fn (): array => $this->api->getCustomer($publicId));
        } catch (NotFoundException) {
            return null;
        }
        $customer['publicId'] = $publicId;
        $index->addCustomer($customer);
        return $customer;
    }

    /**
     * A client without email is created with an empty email (the API stores it as given, workers skip an empty
     * address); a made-up address would make incident mails and reports bounce.
     */
    private function create(ClientRow $client, RemoteIndex $index, ?ReconcileResult $result): string
    {
        $package = $this->settings->defaultPackageType();
        if ($package === '') {
            throw new \RuntimeException(self::NO_DEFAULT_PACKAGE_MESSAGE);
        }
        $name = $client->displayName !== '' ? $client->displayName : 'whmcs-client-' . $client->id;
        /** @var array<string,mixed> $created */
        $created = $this->guard->run(fn (): array => $this->api->createCustomer($name, $client->email, $package, ['whmcs_client_id' => (string) $client->id]));
        $publicId = is_string($created['publicId'] ?? null) ? $created['publicId'] : '';
        if ($publicId === '') {
            throw new ApiException('uptimeify API returned an unexpected response for POST /api/customers: no publicId', 0, Client::INVALID_RESPONSE_CODE);
        }
        $fields = is_array($created['customFields'] ?? null) ? $created['customFields'] : [];
        $fields['whmcs_client_id'] = (string) $client->id;
        $created['customFields'] = $fields;
        $index->addCustomer($created);
        $this->map->set($client->id, $publicId, self::packageOf($created) ?? $package, self::SOURCE_CREATED);
        Bridge::logActivity(sprintf('Created customer %s for WHMCS client #%d', $publicId, $client->id), $client->id);
        if ($result !== null) {
            $result->customersCreated++;
        }
        return $publicId;
    }

    /**
     * Best-effort back-fill of `customFields.whmcs_client_id` so the next run matches by id. PATCH replaces the
     * whole `customFields` object, so the existing keys are sent along; the package is never sent. A failure is
     * logged and ignored, except 401 and an exhausted 429, which must stop the run.
     *
     * @param array<string,mixed> $customer
     */
    private function tagWithWhmcsId(array $customer, int $clientId, RemoteIndex $index): void
    {
        $publicId = (string) $customer['publicId'];
        $fields = is_array($customer['customFields'] ?? null) ? $customer['customFields'] : [];
        $fields['whmcs_client_id'] = (string) $clientId;
        try {
            $this->guard->run(fn (): array => $this->api->patchCustomer($publicId, ['customFields' => $fields]));
        } catch (UnauthorizedException|RateLimitedException $e) {
            throw $e;
        } catch (ApiException $e) {
            Bridge::logActivity(sprintf('Could not tag customer %s with WHMCS client #%d: %s', $publicId, $clientId, $e->getMessage()), $clientId);
            return;
        }
        $customer['customFields'] = $fields;
        $index->addCustomer($customer);
    }

    /**
     * Package for the map row: `packageType` (create response), else `packageDisplayName` (list items carry
     * no `packageType`, docs/api-surface.md).
     *
     * @param array<string,mixed> $customer
     */
    private static function packageOf(array $customer): ?string
    {
        foreach (['packageType', 'packageDisplayName'] as $key) {
            if (is_string($customer[$key] ?? null) && $customer[$key] !== '') {
                return $customer[$key];
            }
        }
        return null;
    }
}
