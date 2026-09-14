<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Uptimeify\Api;

use WHMCS\Module\Addon\Uptimeify\Api\Exception\ApiException;
use WHMCS\Module\Addon\Uptimeify\Api\Exception\NotFoundException;
use WHMCS\Module\Addon\Uptimeify\Api\Exception\QuotaExceededException;
use WHMCS\Module\Addon\Uptimeify\Api\Exception\RateLimitedException;
use WHMCS\Module\Addon\Uptimeify\Api\Exception\UnauthorizedException;
use WHMCS\Module\Addon\Uptimeify\Bridge;
use WHMCS\Module\Addon\Uptimeify\Http\HttpClientInterface;
use WHMCS\Module\Addon\Uptimeify\Http\HttpResponse;
use WHMCS\Module\Addon\Uptimeify\Http\TransportException;
use WHMCS\Module\Addon\Uptimeify\Version;

/**
 * REST client for the Uptimeify API. Routes, bodies, response shapes and error codes are the
 * verified ones from docs/api-surface.md; change them there first.
 */
final class Client
{
    /** `data.code` values (HTTP 403) that mean a monitor quota is exhausted. */
    public const QUOTA_CODES = ['activeMonitorLimitReached', 'monitorQuotaReached', 'monitorQuotaNoHigherTier'];

    /**
     * Client-side code for the DNSBL IP limit: the API answers it with `data.code: generic` and
     * only the text tells it apart (api/customers/[id]/ips.post.ts).
     */
    public const IP_LIMIT_CODE = 'activeIpLimitReached';

    /** Every `errorCode` a QuotaExceededException can carry. */
    public const QUOTA_ERROR_CODES = [...self::QUOTA_CODES, self::IP_LIMIT_CODE];

    public const SCOPE_FORBIDDEN_CODE = 'customerScopedTokenForbidden';

    /** 400 on customer create/patch: the package key or name does not exist (a setup error, spec §5). */
    public const INVALID_PACKAGE_TYPE_CODE = 'invalidPackageType';

    /** Client-side: a 2xx answer that is not the verified shape (not JSON, missing items, …). */
    public const INVALID_RESPONSE_CODE = 'invalidResponse';

    /** Client-side: the request body could not be JSON-encoded (e.g. invalid UTF-8); nothing was sent. */
    public const INVALID_REQUEST_CODE = 'invalidRequest';

    private const IP_LIMIT_TEXT = 'Active IP limit reached';
    private const PER_PAGE = 100;
    private const INCIDENT_PAGE = 500;
    private const MAX_PAGES = 1000;
    private const MAX_MESSAGE_LENGTH = 300;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly float $timeout = 10.0,
    ) {
    }

    // ---- organization / packages -------------------------------------------------

    /** @return array<mixed> */
    public function getOrganization(): array
    {
        return $this->object('GET', '/api/organization');
    }

    /** @return list<array<string,mixed>> package rows, identifier in `packageType` */
    public function listPackageConfigs(): array
    {
        return $this->listOf('GET', '/api/package-configs');
    }

    /**
     * Custom field definitions of the organization. The addon only uses this read as the scope probe of the connect
     * handshake: a customer-bound token gets 403 `customerScopedTokenForbidden` here, while `GET /api/organization`
     * and `GET /api/package-configs` stay open to it (docs/api-surface.md).
     *
     * @return list<array<string,mixed>>
     */
    public function listCustomFieldDefinitions(): array
    {
        return $this->listOf('GET', '/api/custom-fields');
    }

    // ---- customers ----------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    public function listCustomers(): array
    {
        return $this->paginate('/api/customers');
    }

    /** @return array<mixed> */
    public function getCustomer(string $publicId): array
    {
        return $this->object('GET', '/api/customers/' . rawurlencode($publicId));
    }

    /**
     * @param array<string,scalar> $customFields
     * @return array<mixed>
     */
    public function createCustomer(string $name, string $email, string $packageType, array $customFields): array
    {
        $data = $this->object('POST', '/api/customers', [
            'name' => $name,
            'email' => $email,
            'packageType' => $packageType,
            'customFields' => (object) $customFields,
        ]);
        if (!is_array($data['customer'] ?? null) || $data['customer'] === [] || array_is_list($data['customer'])) {
            throw self::invalidResponse('POST /api/customers', 'no customer object');
        }
        return $data['customer'];
    }

    /**
     * `customFields` replaces the stored object as a whole: pass the existing keys too.
     *
     * @param array<string,mixed> $fields
     * @return array<mixed>
     */
    public function patchCustomer(string $publicId, array $fields): array
    {
        return $this->object('PATCH', '/api/customers/' . rawurlencode($publicId), $fields);
    }

    // ---- websites -----------------------------------------------------------------

    /**
     * @param string|null $customerPublicId only this customer's websites (`customerId` accepts the UUID)
     * @return list<array<string,mixed>>
     */
    public function listWebsites(?string $customerPublicId = null): array
    {
        return $this->paginate('/api/websites', self::customerFilter($customerPublicId));
    }

    /**
     * @param array<string,scalar> $customFields
     * @return array<mixed>
     */
    public function createWebsite(string $customerPublicId, string $name, string $url, string $monitoringType, int $checkInterval, array $customFields): array
    {
        return $this->object('POST', '/api/websites', [
            'customerId' => $customerPublicId,
            'name' => $name,
            'url' => $url,
            'monitoringType' => $monitoringType,
            'checkInterval' => $checkInterval,
            'customFields' => (object) $customFields,
        ]);
    }

    /** @return array<mixed> */
    public function setWebsiteStatus(string $publicId, string $status): array
    {
        return $this->object('PATCH', '/api/websites/' . rawurlencode($publicId), ['status' => $status]);
    }

    /**
     * Points an existing website at another URL and name, keeping its id, history and status. A partial update: the body
     * never carries `customerId`, which would turn it into the full update that resets unsent settings. The SSRF guard
     * answers 400 `invalidUrl` (docs/api-surface.md).
     *
     * @return array<mixed> the updated website row
     */
    public function updateWebsiteTarget(string $publicId, string $url, string $name): array
    {
        return $this->object('PATCH', '/api/websites/' . rawurlencode($publicId), ['url' => $url, 'name' => $name]);
    }

    public function deleteWebsite(string $publicId): void
    {
        $this->request('DELETE', '/api/websites/' . rawurlencode($publicId));
    }

    /** @return array<mixed> */
    public function getUptimeStats(string $publicId): array
    {
        return $this->object('GET', '/api/websites/' . rawurlencode($publicId) . '/uptime-stats');
    }

    // ---- customer domains (expiry) ------------------------------------------------

    /**
     * @param string|null $customerPublicId only this customer's domains (`customerId` accepts the UUID)
     * @return list<array<string,mixed>>
     */
    public function listCustomerDomains(?string $customerPublicId = null): array
    {
        return $this->paginate('/api/customer-domains', self::customerFilter($customerPublicId));
    }

    /** @return array<mixed> the domain row including `deduplicated` */
    public function createCustomerDomain(string $customerPublicId, string $domainName, string $label, int $expiryWarningDays = 30, int $expiryErrorDays = 7): array
    {
        return $this->object('POST', '/api/customers/' . rawurlencode($customerPublicId) . '/domains', [
            'domainName' => $domainName,
            'label' => $label,
            'expiryWarningDays' => $expiryWarningDays,
            'expiryErrorDays' => $expiryErrorDays,
        ]);
    }

    public function deleteCustomerDomain(string $publicId): void
    {
        $this->request('DELETE', '/api/customer-domains/' . rawurlencode($publicId));
    }

    // ---- dns monitors -------------------------------------------------------------

    /**
     * @param int|null $customerNumericId only this customer's DNS monitors; this endpoint's `customerId` filter takes
     *                                    the numeric id only (a UUID is a 400, docs/api-surface.md)
     * @return list<array<string,mixed>>
     */
    public function listDnsMonitors(?int $customerNumericId = null): array
    {
        return $this->paginate('/api/dns-monitors', $customerNumericId === null ? [] : ['customerId' => $customerNumericId]);
    }

    /**
     * @param array<string,mixed> $dnsConfig `{rrtypes, matchMode, expectedValues, triggerOn?}`
     * @return array<mixed>
     */
    public function createDnsMonitor(string $customerPublicId, string $name, string $hostname, array $dnsConfig): array
    {
        return $this->object('POST', '/api/dns-monitors', [
            'customerId' => $customerPublicId,
            'name' => $name,
            'hostname' => $hostname,
            'dnsConfig' => $dnsConfig,
        ]);
    }

    public function deleteDnsMonitor(string $publicId): void
    {
        $this->request('DELETE', '/api/dns-monitors/' . rawurlencode($publicId));
    }

    // ---- customer ips (dnsbl) -----------------------------------------------------

    /**
     * @param string|null $customerPublicId only this customer's IPs (`customerId` accepts the UUID)
     * @return list<array<string,mixed>>
     */
    public function listCustomerIps(?string $customerPublicId = null): array
    {
        return $this->paginate('/api/customer-ips', self::customerFilter($customerPublicId));
    }

    /** @return array<mixed> the IP row including `deduplicated` */
    public function createCustomerIp(string $customerPublicId, string $ip, string $label): array
    {
        return $this->object('POST', '/api/customers/' . rawurlencode($customerPublicId) . '/ips', [
            'ipAddress' => $ip,
            'label' => $label,
            'status' => 'active',
        ]);
    }

    /**
     * `active` or `disabled`. Activating counts against the IP pool: 403 `generic` + `Active IP limit reached` becomes a
     * QuotaExceededException, like on create (docs/api-surface.md).
     *
     * @return array<mixed> the updated customer-ip row
     */
    public function setCustomerIpStatus(string $publicId, string $status): array
    {
        return $this->object('PATCH', '/api/customer-ips/' . rawurlencode($publicId), ['status' => $status]);
    }

    public function deleteCustomerIp(string $publicId): void
    {
        $this->request('DELETE', '/api/customer-ips/' . rawurlencode($publicId));
    }

    // ---- incidents ----------------------------------------------------------------

    /**
     * The newest incidents, at most `$maxPages` pages of 500 (the API orders by start, newest first, and has no
     * status or customer filter). `complete` is false when older incidents were left unread. There is deliberately no
     * unbounded walk (round 2, D2): callers go through Sync\IncidentWindow.
     *
     * @return array{incidents: list<array<string,mixed>>, complete: bool}
     */
    public function listRecentIncidents(int $maxPages): array
    {
        return $this->walkIncidents(max(1, $maxPages));
    }

    // ---- tags ---------------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    public function listTags(): array
    {
        return $this->listOf('GET', '/api/tags');
    }

    /**
     * The API does not deduplicate tag names: look the tag up with listTags() first.
     *
     * @return array<mixed>
     */
    public function createTag(string $name, string $color): array
    {
        return $this->object('POST', '/api/tags', ['name' => $name, 'color' => $color]);
    }

    /** Idempotent on the API side. `$monitorId` and `$tagId` are the numeric ids. */
    public function addMonitorTag(string $monitorType, int $monitorId, int $tagId): void
    {
        $this->request('POST', '/api/monitor-tags', ['monitorType' => $monitorType, 'monitorId' => $monitorId, 'tagId' => $tagId]);
    }

    // ---- internals ----------------------------------------------------------------

    /** @return array{incidents: list<array<string,mixed>>, complete: bool} */
    private function walkIncidents(int $maxPages): array
    {
        $all = [];
        $seen = [];
        $offset = 0;
        $action = 'GET /api/incidents';
        for ($pageNo = 1; $pageNo <= $maxPages; $pageNo++) {
            $data = $this->object('GET', '/api/incidents?limit=' . self::INCIDENT_PAGE . '&includeTotal=1&offset=' . $offset);
            $items = self::listField($data, 'incidents', $action);
            $total = self::totalField($data, $action);
            self::appendUnique($all, $seen, $items, 'id', $action);
            $offset += count($items);
            if ($items === [] || $offset >= $total) {
                return ['incidents' => $all, 'complete' => true];
            }
        }
        return ['incidents' => $all, 'complete' => false];
    }

    /** @return array<string,string> */
    private static function customerFilter(?string $customerPublicId): array
    {
        return $customerPublicId === null ? [] : ['customerId' => $customerPublicId];
    }

    /**
     * Walks `?page=N&perPage=100&sortBy=createdAt&sortOrder=asc` (oldest first, so rows created
     * during the walk land at the end; the websites default order is by monitor status, which
     * reorders while walking) until the rows fetched reach `total` or a page is empty. Offset
     * paging overlaps when rows are inserted mid-walk (or on sort ties), so an already seen
     * publicId is skipped; a non-empty page with no new publicId at all (server ignoring `page`),
     * a page without `items`/`total` or more than MAX_PAGES pages is an error: an empty or
     * truncated remote list would make the reconcile orphan and recreate monitors.
     *
     * `$query` adds filters (e.g. `customerId`), URL-encoded after the paging parameters.
     *
     * @param array<string,scalar> $query
     * @return list<array<string,mixed>>
     */
    private function paginate(string $path, array $query = []): array
    {
        $all = [];
        $seen = [];
        $fetched = 0;
        $action = 'GET ' . $path;
        for ($page = 1; ; $page++) {
            if ($page > self::MAX_PAGES) {
                throw self::invalidResponse($action, 'more than ' . self::MAX_PAGES . ' pages');
            }
            $data = $this->object('GET', $path . '?page=' . $page . '&perPage=' . self::PER_PAGE . '&sortBy=createdAt&sortOrder=asc' . ($query === [] ? '' : '&' . http_build_query($query, '', '&', PHP_QUERY_RFC3986)));
            $items = self::listField($data, 'items', $action);
            $total = self::totalField($data, $action);
            self::appendUnique($all, $seen, $items, 'publicId', $action);
            $fetched += count($items);
            if ($items === [] || $fetched >= $total) {
                return $all;
            }
        }
    }

    /**
     * Appends the rows whose id was not seen yet. Throws when a non-empty page brings no new row.
     *
     * @param list<array<string,mixed>> $all
     * @param array<string,true> $seen
     * @param list<array<string,mixed>> $items
     */
    private static function appendUnique(array &$all, array &$seen, array $items, string $idKey, string $action): void
    {
        $added = 0;
        foreach ($items as $item) {
            $id = $item[$idKey] ?? null;
            if (is_string($id) || is_int($id)) {
                if (isset($seen[(string) $id])) {
                    continue;
                }
                $seen[(string) $id] = true;
            }
            $all[] = $item;
            $added++;
        }
        if ($items !== [] && $added === 0) {
            throw self::invalidResponse($action, 'duplicate ' . $idKey . ' page: no new rows');
        }
    }

    /**
     * @param array<mixed> $data
     * @return list<array<string,mixed>>
     */
    private static function listField(array $data, string $key, string $action): array
    {
        if (!array_key_exists($key, $data)) {
            throw self::invalidResponse($action, 'missing ' . $key);
        }
        return self::rows($data[$key], $action);
    }

    /** @param array<mixed> $data */
    private static function totalField(array $data, string $action): int
    {
        if (!is_int($data['total'] ?? null) || $data['total'] < 0) {
            throw self::invalidResponse($action, 'missing total');
        }
        return $data['total'];
    }

    /** @return list<array<string,mixed>> */
    private static function rows(mixed $list, string $action): array
    {
        if (!is_array($list) || !array_is_list($list)) {
            throw self::invalidResponse($action, 'expected a JSON array');
        }
        $rows = [];
        foreach ($list as $row) {
            if (!is_array($row)) {
                throw self::invalidResponse($action, 'expected an array of objects');
            }
            /** @var array<string,mixed> $row */
            $rows[] = $row;
        }
        return $rows;
    }

    private static function invalidResponse(string $action, string $reason): ApiException
    {
        return new ApiException('uptimeify API returned an unexpected response for ' . $action . ': ' . $reason, 0, self::INVALID_RESPONSE_CODE);
    }

    /**
     * @param array<string,mixed>|null $body
     * @return array<mixed> a non-empty JSON object
     */
    private function object(string $method, string $path, ?array $body = null): array
    {
        $data = $this->request($method, $path, $body);
        if (!is_array($data) || $data === [] || array_is_list($data)) {
            throw self::invalidResponse($method . ' ' . $path, 'expected a JSON object');
        }
        return $data;
    }

    /** @return list<array<string,mixed>> */
    private function listOf(string $method, string $path): array
    {
        return self::rows($this->request($method, $path), $method . ' ' . $path);
    }

    /**
     * @param array<string,mixed>|null $body
     * @return mixed the decoded JSON body, null for an empty 2xx body
     */
    private function request(string $method, string $path, ?array $body = null): mixed
    {
        $action = $method . ' ' . $path;
        $url = rtrim($this->baseUrl, '/') . $path;
        $headers = [
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
            'User-Agent' => Version::userAgent(),
        ];
        $json = null;
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
            try {
                $json = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            } catch (\JsonException $e) {
                throw new ApiException('uptimeify API request for ' . $action . ' not encodable: ' . $e->getMessage(), 0, self::INVALID_REQUEST_CODE, $e);
            }
        }
        try {
            $response = $this->http->send($method, $url, $headers, $json, $this->timeout);
        } catch (TransportException $e) {
            Bridge::logModuleCall($action, $json ?? '', $e->getMessage(), [$this->token]);
            throw new ApiException('uptimeify API unreachable: ' . $e->getMessage(), 0, 'transport', $e);
        }
        Bridge::logModuleCall($action, $json ?? '', $response->status . ' ' . $response->body, [$this->token]);
        $this->assertSuccess($response);
        if (trim($response->body) === '') {
            return null;
        }
        try {
            return json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw self::invalidResponse($action, 'body is not JSON (HTTP ' . $response->status . ')');
        }
    }

    private function assertSuccess(HttpResponse $response): void
    {
        $status = $response->status;
        if ($status >= 200 && $status < 300) {
            return;
        }
        $decoded = $response->json() ?? [];
        $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
        $code = is_string($data['code'] ?? null) ? $data['code'] : '';
        $statusMessage = is_string($decoded['statusMessage'] ?? null) ? $decoded['statusMessage'] : '';
        $message = $statusMessage !== '' ? $statusMessage : (is_string($decoded['message'] ?? null) ? $decoded['message'] : $response->body);
        $text = sprintf(
            'uptimeify API error (HTTP %d%s): %s',
            $status,
            $code !== '' ? ', ' . $code : '',
            mb_substr($message, 0, self::MAX_MESSAGE_LENGTH),
        );

        if ($status === 401) {
            throw new UnauthorizedException($text, 401, $code !== '' ? $code : 'unauthorized');
        }
        if ($status === 429) {
            $header = $response->header('Retry-After');
            $retry = $header !== null && is_numeric($header)
                ? (int) $header
                : (is_int($data['retryAfter'] ?? null) ? $data['retryAfter'] : 5);
            throw new RateLimitedException($text, max(1, $retry));
        }
        if ($status === 404) {
            throw new NotFoundException($text, 404, $code);
        }
        if ($status === 403 && in_array($code, self::QUOTA_CODES, true)) {
            throw new QuotaExceededException($text, 403, $code);
        }
        if ($status === 403 && $code === 'generic' && str_starts_with($statusMessage, self::IP_LIMIT_TEXT)) {
            throw new QuotaExceededException($text, 403, self::IP_LIMIT_CODE);
        }
        throw new ApiException($text, $status, $code);
    }
}
