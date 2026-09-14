# Uptimeify API reference used by the WHMCS addon

Verified against the Uptimeify API on 2026-09-13/14.

This file lists the endpoints, fields, error codes and behaviours of the Uptimeify REST API that the addon relies on,
as observed from outside (requests and responses). It is the reference for every API field name in the addon: a field
not listed here is verified against the API first and added here in the same commit. Where the public documentation
at [docs.uptimeify.io](https://docs.uptimeify.io) differs in a way that matters for the addon, the last section says so.

## 1. Transport, auth and error envelope

### Auth

- `Authorization: Bearer wsm_…`. The token acts with the role of the user who issued it. The organization comes from
  the token; no `organizationId` is ever sent.
- An unknown, expired or missing token is answered with **401 without `data.code`** (`statusMessage: "Unauthorized"`).
  Some other 401 responses carry `data.code: "unauthorized"`. The client maps by HTTP status 401, never by code.
- **An organization-wide token is required.** A customer-bound token (a token limited to one customer) is refused on
  organization paths with 403 `customerScopedTokenForbidden`: `POST /api/customers`, every method under
  `/api/custom-fields`, writes under `/api/package-configs` and more. `GET /api/organization` and
  `GET /api/package-configs` stay reachable for such a token, so these reads alone do not detect it. The connect
  handshake therefore probes with the read-only `GET /api/custom-fields` right after `GET /api/organization` (see
  below) and refuses the token before it is stored.
- Path guard for **every** `wsm_` token: a raw path containing `%25`, `%2e`/`..`, `%2f`, `%5c`/`\` or `//` is refused
  with 403 `apiTokenPathAmbiguous`. Consequence: `rawurlencode()` on a path parameter is still mandatory (it keeps a
  hostile id inside its segment), but an id containing `/`, `%` or `..` then fails closed with 403
  `apiTokenPathAmbiguous` instead of reaching another route. Real public ids are UUIDs, for which `rawurlencode()` is
  the identity.

### Error envelope

Errors are JSON of this shape:

```json
{ "error": true, "url": "…", "statusCode": 403, "statusMessage": "Forbidden", "message": "Forbidden", "data": { "code": "monitorQuotaReached" } }
```

- The machine code is **`body.data.code`** (not `body.code`, not `body.data.data.code`).
- `data` can be absent. Several relevant errors carry no code: the 401 for a rejected token, 404 for an unknown UUID
  (`statusMessage: "<Entity> not found"`), 400 `Invalid <Entity> identifier` (not a UUID and not a positive integer),
  423 `Organization suspended`, 429 from the rate limit.
- Request validation failures are 400 with `data.issues[]` (a list of field issues) and no `data.code`.

### Rate limit

- 600 requests per 60 s window per client IP for every `/api/**` path, applied before authentication.
- Exceeded: **429**, `statusMessage: "Too Many Requests"`, `data: { retryAfter: <seconds> }` (no `data.code`), and
  header **`Retry-After: <seconds>`** (1..60, seconds to the end of the window).
- No `X-RateLimit-Remaining` header is sent.

### Pagination summary

| Endpoint | Without `page` | With `page` | `perPage` default / max |
| --- | --- | --- | --- |
| `GET /api/customers` | plain array (all customers) | `{items,total,page,perPage[,packageOptions]}` | 10 / 100 |
| `GET /api/websites` | always `{items,total,page,perPage}` | same | 50 / 200 |
| `GET /api/dns-monitors` | always `{items,total,page,perPage}` | same | 50 / 200 |
| `GET /api/customer-domains` | always `{items,total,page,perPage}` | same | 50 / 200 |
| `GET /api/customer-ips` | always `{items,total,page,perPage}` | same | 50 / 200 |
| `GET /api/incidents` | plain array | `limit`/`offset`; with `includeTotal=1` `{incidents,total,limit,offset}` | limit 100 / 500 |
| `GET /api/tags`, `GET /api/package-configs`, `GET /api/custom-fields` | plain array, not paginated | n/a | n/a |

The client pages every paginated list with `page=N&perPage=100&sortBy=createdAt&sortOrder=asc` (valid on all five list
endpoints), and the incidents with `offset` += rows received, until the rows received reach `total` (re-read on every
page) or a page comes back empty.

Ordering, all offset-paged: without `sortBy` the defaults are websites by monitor status, then name, then id
(**changes while walking** when a site goes down or recovers), dns-monitors and customer-domains `createdAt desc` (tie:
`id asc`), customer-ips `createdAt desc` (tie: `id desc`), customers `createdAt desc` (tie: `id asc`). With
`sortBy=createdAt&sortOrder=asc` every list is oldest first (tie by `id`: websites and customer-ips `id desc`, the others
`id asc`), so rows created during a walk land at the end instead of shifting earlier pages. Incidents have no sort
parameter: `startedAt desc` with **no** tiebreaker, so a new incident shifts the last row of page N onto page N+1 and
equal start times can overlap even without writes.

Dedupe rule: a row whose `publicId` (incidents: `id`) was already received is skipped silently; only a non-empty page
that adds **no** new id at all (the API ignoring `page`/`offset`) is an error.

Offset paging can also silently *skip* a row when another row is deleted during the walk (everything behind it moves
one position forward). A remote row missing from one run's lists is therefore not proof that it is gone: the reconcile
never takes a destructive action (delete the map row, recreate the monitor) on a row missing in a single run; it
confirms with a direct `GET` of that resource or waits for a second run.

### Client-side response checks (`lib/Api/Client.php`)

A 2xx whose body is not JSON, an object endpoint that returns anything but a non-empty JSON object, a list endpoint
that returns anything but an array of objects, a page without `items` or `total`, a non-empty page that adds no new
publicId (incidents: `id`), or more than 1000 pages all throw `ApiException` with `errorCode = 'invalidResponse'`
(status 0), never an empty or partial list: an empty remote list would make the reconcile orphan and recreate monitors.
A request body that cannot be JSON-encoded (invalid UTF-8) throws `errorCode = 'invalidRequest'` before anything is sent.

### Error codes the addon reacts to

| `data.code` | HTTP | Raised by | Client mapping |
| --- | --- | --- | --- |
| `unauthorized` or none | 401 | any endpoint (rejected or missing token) | `UnauthorizedException` (by status) |
| `customerScopedTokenForbidden` | 403 | organization paths called with a customer-bound token | `ApiException`, `errorCode` = the code |
| `apiTokenPathAmbiguous` | 403 | any path with an ambiguous encoding (see Auth) | `ApiException`, `errorCode` = the code |
| `activeMonitorLimitReached` | 403 | monitor create/resume, customer package limit | `QuotaExceededException` |
| `monitorQuotaReached` | 403 | monitor create/resume, organization quota | `QuotaExceededException` |
| `monitorQuotaNoHigherTier` | 403 | monitor create/resume, no higher quota tier available | `QuotaExceededException` |
| `generic` + `statusMessage` starting `Active IP limit reached` | 403 | `POST /api/customers/:id/ips`, `PATCH /api/customer-ips/:id` (activation) | `QuotaExceededException`, `errorCode` = `activeIpLimitReached` (client-side name, see below) |
| `invalidUrl` | 400 | `POST /api/websites`, `PATCH /api/websites/:id` | `ApiException`, `errorCode` = the code |
| `invalidPackageType` | 400 | `POST /api/customers`, `PATCH /api/customers/:id` | `ApiException`, `errorCode` = the code |
| `customerNotFound`, `websiteNotFound`, `monitorNotFound`, `customerDomainNotFound`, `customerIpNotFound`, `tagNotFound`, `organizationNotFound`, or none | 404 | endpoints addressing a resource | `NotFoundException` (by status) |
| none | 429 | rate limit | `RateLimitedException` with `Retry-After` |

- `selfServiceQuotaReached` and `selfServiceNotAllowed` are only raised for customer-restricted access. An
  organization-wide token never sees them, so the client does not map them to quota.
- `paidQuotaUpgradeNotAuthorized` is not raised for API tokens.
- **IP-limit message prefix.** The IP limit has no code of its own (`data.code: "generic"`). The exact text is
  `Active IP limit reached. Your <organization IP quota|customer package> allows maximum <n> active IPs for DNSBL monitoring. Please disable an IP or upgrade your package.`
  The client matches only `status === 403 && code === 'generic' && statusMessage` starting with
  `Active IP limit reached`, and reports it as `errorCode = 'activeIpLimitReached'` so that a job's `last_error` names
  the reason and a fatal-error list never has to contain `generic`.
- Further codes the addon may meet (plain `ApiException`): `noActiveSubscription` 403 (website create and resume),
  `quotaRequired` 403 and `billingSetupRequired` 403 (customer create), `checkIntervalTooLow` 400 (website create),
  `invalidHostname` 400 (DNS monitor create), `invalidIpAddress` 400 (customer IP create), `readonlyMayNotDelete` 403
  (monitor delete), `tagLimitReached` 422, `invalidMonitorType` 400 and `tagMonitorOrgMismatch` 400 (monitor tags),
  `invalidTagColor` 400 (tag create), `forbidden` 403 (many).

## 2. Endpoints

### `GET /api/organization`

- Reachable for customer-bound tokens too.
- Response: plain object. Fields the addon reads: `id` (int), `publicId` (uuid), `name`, `status`. Billing/quota
  fields only for organization admins.
- The addon binds the installation to `publicId` on connect and compares it once per run
  (`Sync\OrganizationBinding`). A response without `publicId` is treated as `invalidResponse`.
- Errors: 401 (`unauthorized`/none), 400 `Organization ID is required in the authenticated session` (no code),
  403 `forbidden`, 404 `organizationNotFound`.

### `GET /api/package-configs`

- Reachable for customer-bound tokens too. Query `summary=1` optional (not used).
- Response: **plain array**. Item fields: `id` (int), **`packageType`** (string, the package key; this is the
  identifier `POST /api/customers` takes), `displayName` (string|null), `maxUrls` (int|null), `checkIntervalMinutes`
  (int|null), `usedByCustomerCount` (int), plus feature flags.

### `GET /api/customers?page=N&perPage=100`

- Query: `page` (switches the shape), `perPage` (1..100, default 10), `search` (case-insensitive match on name, email,
  package type and custom field values), `status` (`active`|`inactive`), `packageTypes` (csv), `sortBy`, `sortOrder`,
  `summary=1`, `fields=minimal` (plain array `id,publicId,name,status,customFields`; takes precedence over `page`).
- Response with `page`: `{ items, total, page, perPage, packageOptions? }`. Item fields: `id` (int), `publicId`
  (uuid), `organizationId`, `name`, `email` (string|null), `customFields` (object), `packageId` (int|null),
  `packageDisplayName`, `status` (`active`, `inactive`, `marked_for_cancellation`, …), `monitorCount`, `createdAt`.
  **No `packageType`** in list items.

### `POST /api/customers`

- Requires an organization-wide token (403 `customerScopedTokenForbidden`) whose role may write (403 `forbidden`).
- Body: `name` (string, required non-empty after trim, else 400 `invalidRequestBody`), `email` (string, optional),
  `packageType` (string: package key, else a package `displayName`) **or** `packageId` (int) — one of them required
  (400 `invalidRequestBody`), unknown → 400 `invalidPackageType`; `customFields` (any JSON, stored verbatim, **not
  validated against custom-field definitions**), `status` (optional, default `active`), `monthlyReportsEnabled`,
  notification fields (unused).
- Response: HTTP 200 `{ "success": true, "customer": { … } }`. Customer fields: `id`, `publicId`, `organizationId`,
  `name`, `email`, `packageId`, `packageType`, `status`, `customFields`, …
- Errors also: 403 `quotaRequired`, 403 `billingSetupRequired`, 404 `organizationNotFound`, 500
  `customerCreationFailed`.

### `GET /api/customers/:publicId`

- Response: **plain object** (no `customer` envelope): `id`, `publicId`, `organizationId`, `name`, `email`,
  `packageId`, `packageDisplayName`, `status`, `customFields`, `monthlyReportsEnabled`, notification fields,
  `createdAt`, `updatedAt`.
- Errors: 400 `Invalid Customer identifier` (no code), 404 no code (unknown UUID) or `customerNotFound` (other
  organization), 403 `organizationIdRequired`.

### `PATCH /api/customers/:publicId`

- Body: every key optional; only present keys change. `customFields` **replaces the whole object**, it is not merged:
  to add `whmcs_client_id`, send the existing `customFields` plus the new key. `packageType`/`packageId` as in POST
  (400 `invalidPackageType`, 403 `packageOrgOnly`), `status` (changing it also changes the status of the customer's
  monitors).
- Response: **plain object**: `id`, `publicId`, `organizationId`, `name`, `email`, `packageId`, `status`,
  `monthlyReportsEnabled`, `customFields`, notification fields, `updatedAt`.
- Errors: 400/404 as GET, 500 `generic`.

### `GET /api/websites?page=N&perPage=100`

- Query: `page`, `perPage` (1..200, default 50), `customerId` (UUID or int), `search`, `monitoringType(s)`,
  `excludeMonitoringType`, `statuses` (`active`,`inactive`,`maintenance`), `tags` (tag ids csv), `sortBy`, `sortOrder`,
  `skipMetrics` (`1`/`true` drops the uptime and response metrics).
- Response: always `{ items, total, page, perPage }`. Item fields (secrets excluded): `id` (int, the numeric id
  `monitor-tags` needs), `publicId`, `customerId` (**int**), `name`, `url`, `monitoringType`, `status` (`active`,
  `inactive`, `maintenance`, legacy `disabled`/`paused`), `checkInterval`, `customFields` (object), `createdAt`; plus
  `customerName`, `customerPublicId` (uuid), `inMaintenance` (bool), `isDown` (bool), `isRecovering` (bool),
  `hasCritical`, `hasWarning`, `upSinceAt` (ISO|null), `uptimeDay`, `uptimeMonth`, `uptimeYear` (**strings** like
  `"99.95"`), `avgResponseDay`, `avgResponseMonth`, `avgResponseYear` (int ms|null), `responseGraph {values[24], avg}`,
  `tags` (array of `{id,publicId,name,color}`). Metric keys are absent with `skipMetrics`.

### `POST /api/websites`

- A customer-bound token may only create for its own customer.
- Body keys the addon sends: `customerId` (UUID string or positive int, required), `name` (1..255, required), `url`
  (≤2048, required for `combined`/`http_status`/`ssl_check`, must parse as a URL with protocol), `monitoringType`
  (`combined` default, `http_status`, `ssl_check`, `playwright`, `heartbeat`, `dns`), `checkInterval` (int
  **minutes**, 1..1440 for non-heartbeat, default 30; below the package's `checkIntervalMinutes` → 400
  `checkIntervalTooLow`), `customFields` (object of arbitrary keys, stored verbatim, **no definition needed**),
  optional `status` (`active` default).
- Response: HTTP 200, **plain website object** (no envelope): `id` (int), `publicId`, `customerId` (int), `name`, `url`,
  `monitoringType`, `status`, `checkInterval`, `customFields`, …
- Errors: 400 `invalidUrl` (see below), 400 validation, 400 `checkIntervalTooLow`, 403 quota codes, 403
  `noActiveSubscription`, 404 `customerNotFound` or code-less, 500 `websiteCreationFailed`.
- No deduplication: a repeated POST creates a second website.
- **`invalidUrl` is not only a private target.** The API refuses a URL that does not parse with protocol, a scheme
  other than `http`/`https`, an IP literal in a private or reserved range, a localhost or local-only hostname, **and a
  hostname that does not resolve** in DNS (no answer or a lookup error) or resolves to a private or loopback address.
  Create and PATCH turn **every** such failure into 400 `data.code: invalidUrl` with a descriptive `statusMessage`.
  Only the free-text `statusMessage` tells the cases apart (no separate code), and a DNS state can change (a new domain
  starts resolving, a domain stops pointing at a private address). The addon therefore treats `invalidUrl` as
  retryable with limits: job backoff, once per daily reconcile, not from a budgeted admin run.

### `PATCH /api/websites/:publicId` (pause/resume, target update)

- **Full vs. partial update:** a body with all of `customerId`, `name` and `url` is a *full* update that rewrites the
  monitor: unsent settings fall back to their defaults (check interval, timeout, expected status codes, SSL notice
  days, search term, …) and a missing `status` becomes `active`. The addon therefore **never sends `customerId`** in a
  PATCH. Any other body is a *partial* update: only sent keys change; `monitoringType` is ignored there.
- Pause/resume body `{ "status": "inactive" | "active" }`. `paused` is stored as `inactive`. Resume (`→ active` from
  another status) checks billing and quotas: 403 `noActiveSubscription`, `monitorQuotaReached`,
  `monitorQuotaNoHigherTier`, `activeMonitorLimitReached`.
- Target update body `{ "url": "https://<host>", "name": "<host>" }` (`Client::updateWebsiteTarget`): the partial update
  accepts both. `url` goes through the same URL check as create unless the monitor type is `heartbeat`/`dns`; a
  refusal is **400 `invalidUrl`** (same cases as create, including a host that does not resolve). The status, id,
  numeric id, `customFields`, tags and history stay; no quota check runs (the status does not change).
- Other errors of the partial update: 400 without code (`Invalid Website identifier`), 404 `websiteNotFound` or
  code-less, 403 `forbidden`, 500 `updateFailed`.
- Response: **plain website object** (secrets stripped; `url`, `name`, `status`, … but no `customerPublicId`).

### `DELETE /api/websites/:publicId`

- Response: `{ "success": true }`. Errors: 403 `readonlyMayNotDelete`, 404 `websiteNotFound`/no code, 500 `generic`.

### `GET /api/websites/:publicId/uptime-stats`

- Response: plain `{ day, month, year, dayAvgResponse, monthAvgResponse, yearAvgResponse }`: `day`/`month`/`year` are
  uptime strings (`day` = last 24 h, `month` = last 30 days, `year` = since Jan 1), the averages are int ms (`0` when
  nothing was measured).

### `POST /api/customers/:publicId/domains` (domain expiry)

- `:publicId` must be a UUID (400 `Invalid Customer identifier`); numeric ids are refused here.
- Body: `domainName` (string 1..253, trimmed + lower-cased, must be a public domain name, else 400 `generic`; a TLD
  without RDAP support → 400 without code), `label` (string ≤255 | null, optional), `status` (`active`|`disabled`,
  optional, **accepted**, default `active`), `expiryWarningDays` (int 1..365, default 30), `expiryErrorDays` (int 1..90,
  default 7), `managementType` (unused). `Client::createCustomerDomain()` always sends
  `expiryWarningDays`/`expiryErrorDays`; its optional parameters default to 30 and 7 (identical to the API defaults),
  so a repeat call with the defaults is recognised as `deduplicated`.
- Response: HTTP 200, **plain customer-domain object + `deduplicated`**: `id`, `publicId`, `customerId` (int), `label`,
  `domainName`, `status`, `expiryWarningDays`, `expiryErrorDays`, `managementType`, `createdAt`, `updatedAt`,
  `deduplicated` (bool). A repeat with exactly the same `domainName`, `label`, `status`, `expiryWarningDays`,
  `expiryErrorDays` returns the existing entry with `deduplicated: true` and consumes no quota. A different label
  creates a second entry.
- Errors: 403 quota codes (`monitorQuotaReached`, `monitorQuotaNoHigherTier`, `activeMonitorLimitReached`), 404
  `customerNotFound`.

### `GET /api/customer-domains?page=N&perPage=100`

- Query: `page`, `perPage` (1..200), `customerId` (UUID or int), `search`, `statuses`
  (`ok`,`warning`,`critical`,`expired`,`unknown`,`disabled`), `sortBy`, `sortOrder`. Empty-string values are
  validation errors: omit unused keys.
- Response: `{ items, total, page, perPage }`. Item fields: `id`, `publicId`, `customerId` (int), `customerPublicId`
  (uuid), `customerName`, `label`, `domainName`, `status` (`active`|`disabled`), `expiryWarningDays`,
  `expiryErrorDays`, `inMaintenance`, `expiry` (null until the first check) `{ domainExpiresAt, domainRegistrar,
  isExpired, lastError, lastCheckedAt, … }`, `tags`.

### `DELETE /api/customer-domains/:publicId`

- Response: `{ "success": true }`. Errors: 403 `readonlyMayNotDelete`, 404 `customerDomainNotFound`.

### `POST /api/dns-monitors`

- Body: `customerId` (UUID or int, required), `name` (1..255), `hostname` (1..253, no protocol or path, else 400
  `invalidHostname`), `dnsConfig` (required) `{ rrtypes: string[] (min 1, upper-cased), matchMode: "exact" |
  "contains", expectedValues: { <RRTYPE>: string[] } (at least one value for **every** listed rrtype, else 400
  validation), triggerOn?: { resolveError?: bool, mismatch?: bool }, requireExpectedValues?: bool }`, optional `status`
  (`active`,`inactive`,`maintenance`,`disabled`; stored as `active`|`maintenance`|`disabled`), `checkInterval`
  (1..1440), `timeoutSeconds`, `allowedCheckCountryCodes`.
- Response: HTTP 200, **plain dns-monitor object**: `id`, `publicId`, `organizationId`, `customerId` (int), `name`,
  `hostname`, `status`, `dnsConfig`, … DNS monitors have no `customFields`. No deduplication.
- Errors: 403 quota codes, 404 `customerNotFound`/no code.

### `GET /api/dns-monitors?page=N&perPage=100`

- Query: `page`, `perPage` (1..200), `customerId` (**positive int only**, a UUID → 400 `invalidCustomerId`), `search`,
  `statuses`, `tags`, `sortBy`, `sortOrder`.
- Response: `{ items, total, page, perPage }`. Item fields: `id`, `publicId`, `customerId` (int), `name`, `hostname`,
  `status`, `dnsConfig`, … plus `customerName`, `customerPublicId`, `inMaintenance`, `dnsStatus`
  (`up`|`mismatch`|`down`), `isRecovering`, `upSinceAt`, inline metrics (`uptimeDay`…, `avgResponseDay`…, `isDown`),
  `tags`.

### `DELETE /api/dns-monitors/:publicId`

- Response: HTTP 200 `{ "success": true }`. Errors: 403 `readonlyMayNotDelete`, 404 `monitorNotFound`.

### `POST /api/customers/:publicId/ips` (DNSBL)

- `:publicId` accepts a UUID or a numeric id.
- Body: `ipAddress` (string 1..128, must be IPv4/IPv6, else 400 `invalidIpAddress`), `label` (string ≤255 | null),
  `status` (`active`|`disabled`, optional, default `active`), `ipFamily` (`v4`|`v6`, optional, inferred),
  `managementType` (unused).
- Response: HTTP 200, **plain customer-ip object + `deduplicated`**: `id`, `publicId`, `customerId` (int), `label`,
  `ipAddress` (lower-cased), `ipFamily`, `status`, `managementType`, `createdAt`, `updatedAt`, `deduplicated`. Same
  repeat rule as domains (compares `ipAddress`, `ipFamily`, `label`, `status`, `managementType`).
- Errors: 403 `generic` + `Active IP limit reached…` (limit: the organization IP quota if one is set, else the customer
  package), 404 `customerNotFound`.

### `GET /api/customer-ips?page=N&perPage=100`

- Query: `page`, `perPage` (1..200), `customerId` (UUID or int), `search`, `statuses` (`active`,`disabled`), `sortBy`,
  `sortOrder`.
- Response: `{ items, total, page, perPage }`. Item fields: `id`, `publicId`, `customerId` (int), `label`, `ipAddress`,
  `ipFamily`, `status`, `inMaintenance`, **`customer`** (the full customer object incl. `publicId`; there is **no**
  `customerPublicId` here), `dnsbl` (null or `{isListed, listedCount, listings, lastCheckedAt, …}`), `tags`.

### `DELETE /api/customer-ips/:publicId`

- Response: `{ "success": true }`. Errors: 404 `customerIpNotFound`.

### `PATCH /api/customer-ips/:publicId` (pause/resume)

- `:publicId` accepts a UUID or a numeric id (400 code-less for a malformed id, 404 code-less for an unknown UUID).
- Body: `label` (string ≤255 | null, optional), `status` (`active` | `disabled`, optional), `managementType` (unused).
  The addon sends only `{ "status": "disabled" | "active" }` (`Client::setCustomerIpStatus`).
- Activating (`status: active` on an IP that is not active) counts the organization's other active IPs against the
  organization IP quota, else the package: **403 `generic` + `Active IP limit reached. …`**, the same text as on create,
  so the client maps it to `QuotaExceededException` (`activeIpLimitReached`). While another activation for the
  organization is in progress: 429 `customerIpActivationInProgress` (no `Retry-After`) or 503 `lockUnavailable`.
  Disabling has no quota check.
- Errors: 403 `forbidden` (IP of another organization), 404 `ipNotFound` or code-less, 400 validation (invalid body).
- Response: HTTP 200, **plain updated customer-ip object** (`id`, `publicId`, `customerId` int, `label`, `ipAddress`,
  `status`, `managementType`, …; no `customer` object, no `dnsbl`).

### `GET /api/incidents?limit=500&includeTotal=1&offset=N`

- Query: `limit` (1..500, default 100), `offset` (≥0), `includeTotal` (`1`|`true`), `source` (`manual`|`monitor`).
  **No status, customer or sort parameter.** Ordered by start time, newest first.
- Response with `includeTotal=1`: `{ incidents, total, limit, offset }`. Item fields: `id`, `publicId`, `websiteId`
  (int|null), `status` (`open`|`acknowledged`|`resolved`), `severity` (the incident type, e.g. `downtime`), `source`,
  `title`, `started_at` (**snake_case** ISO), `resolved_at` (ISO|null), `error_message`, `status_code`, `website` (null
  or `{id, publicId, name, url, status}`), `monitor` (null or `{name, hostname, publicId, kind}` for dns/icmp/…),
  `customer` (null or `{id, publicId, email, company}`).
- "Open" as the Uptimeify dashboard counts it: `status` is `open` or `acknowledged` and `resolved_at` is null.
- Because there is no open or customer filter, the addon reads at most `Sync\IncidentWindow::PAGES` (2) pages of 500,
  newest first, everywhere, and flags the snapshots `incidentsComplete=false` when more exist. There is no unbounded
  incident walk in the client.

### `GET /api/tags`

- Response: **plain array** ordered by name. Item: `id` (int), `publicId`, `organizationId`, `customerId` (int|null),
  `name`, `color`, `createdBy`, `createdAt`, `updatedAt`, plus `customer`, `owner`, `customers`.

### `POST /api/tags`

- Body: `name` (trimmed, 1..24), `color` (one of `slate`, `red`, `amber`, `green`, `teal`, `blue`, `indigo`, `violet`,
  `pink`, `gray`; else 400 `invalidTagColor`), `customerId` (int, optional; omit for an organization-wide tag).
- Response: HTTP 200, **plain tag object** `{ id, publicId, organizationId, customerId, name, color, createdBy,
  createdAt, updatedAt }`.
- **No uniqueness on `name`** and no 409: a second `POST {name:"whmcs"}` creates a second tag. To reuse `whmcs`, list
  `GET /api/tags` and pick the entry with `name === 'whmcs'` (and `customerId === null`) before creating.

### `POST /api/monitor-tags`

- Body: `monitorType` (`website`, `dns`, `icmp`, `smtp`, `ssh`, `tcp`, `ftp`, `imap_pop`, `customer_domain`,
  `customer_ip`; else 400 `invalidMonitorType`), `monitorId` (**positive int**, the numeric `id`, not the publicId),
  `tagId` (**positive int**).
- Response: HTTP 200, the assignment `{ id, tagId, monitorType, monitorId, organizationId, createdAt }`. Idempotent: an
  existing assignment is returned unchanged.
- Errors: 404 `tagNotFound`, 404 `monitorNotFound`, 400 `tagMonitorOrgMismatch`, 422 `tagLimitReached` (max 5 tags per
  monitor).

### `GET /api/custom-fields` (scope probe) / `POST /api/custom-fields` (not used)

- `GET /api/custom-fields`: 401 `unauthorized` without a valid token; response **plain array** of the organization's
  active custom-field definitions, ordered by display order (empty array when there are none); optional
  `organizationId` query (not sent).
- A customer-bound token gets **403 `customerScopedTokenForbidden`** for every method on `/api/custom-fields`, while an
  organization-wide token (any role) reads it. The connect handshake calls it right after `GET /api/organization` to
  refuse a customer-bound token before it is stored, without writing anything (`Client::listCustomFieldDefinitions()`);
  the rows are not used.
- Definitions only drive the Uptimeify dashboard's customer forms and table columns. Neither `POST /api/customers`,
  `PATCH /api/customers/:id` nor `POST /api/websites` validates `customFields` against them; `customFields` is stored as
  given. The addon therefore needs **no** definition for `whmcs_client_id` / `whmcs_hosting_id` and never creates one.
  (Creating one needs organization write permission; a duplicate key → 409 `customFieldAlreadyExists`.)

## 3. Keys the addon writes into `customFields`

| Resource | Key | Value |
| --- | --- | --- |
| customer | `whmcs_client_id` | WHMCS client id as string |
| website | `whmcs_hosting_id` | WHMCS service (`tblhosting.id`) as string |

Customer domains, customer IPs and DNS monitors have **no** `customFields`; they are matched by
`customerPublicId`/`customer.publicId`/`customerId` plus the normalized target (`domainName`, `ipAddress`, `hostname`).

## 4. Pitfalls

| Easy assumption | Actual behaviour |
| --- | --- |
| `GET /api/customers/:id` returns `{customer: …}` | plain object |
| `PATCH /api/customers/:id` returns `{customer: …}`; back-filling `customFields.whmcs_client_id` is a one-key PATCH | plain object; `customFields` replaces the whole object, send the existing keys too |
| `POST /api/websites` returns `{website: …}`, HTTP 201 | plain object, HTTP 200 |
| domain/IP create return an envelope | plain object with `deduplicated` |
| DNS monitor create returns an envelope | plain object |
| `GET /api/tags` returns `items`/`tags` | plain array |
| `GET /api/websites` / `GET /api/dns-monitors` may be a plain array without `page` | always `{items,total,page,perPage}`, max 200 |
| `POST /api/tags {name:"whmcs"}` conflicts when the tag exists | no uniqueness, a duplicate tag is created; list and reuse |
| `POST /api/monitor-tags` accepts a tag publicId | `tagId` and `monitorId` must be positive ints |
| Quota codes include `selfServiceQuotaReached` | only for customer-restricted access, never for an organization token |
| The error code may sit at `body.code` | only `body.data.code` |
| 401 always carries `data.code: unauthorized` | the 401 for a rejected token has no code |
| The 404 family always carries a `*NotFound` code | unknown UUIDs give a code-less 404 |
| `rawurlencode` makes `apiTokenPathAmbiguous` impossible | encoding `/` yields `%2F`, which the path guard refuses; UUIDs are unaffected |
| Domain create must not send `status` | `status` is accepted (`active`|`disabled`, default `active`); `expiryWarningDays`/`expiryErrorDays` are optional with defaults 30/7 |
| DNS config `matchMode:'any'`, `triggerOn:'no_answer'`, no expected values | `matchMode` is `exact`|`contains`; `triggerOn` is `{resolveError, mismatch}`; every rrtype needs at least one expected value |
| Open incidents are `status=open` | open = `open` or `acknowledged` with `resolved_at` null; the start time is `started_at` |
| Custom-field definitions are required before writing `customFields` | not checked by customer/website writes |
| The DNS monitor list can be filtered per customer by publicId | the `customerId` query is int only |
| Customer list items carry `packageType` | only `packageId` + `packageDisplayName` |

## 5. Differences from the public docs at docs.uptimeify.io

Observed API behaviour, which the addon follows:

- `DELETE /api/dns-monitors/:id` answers 200 `{success:true}`, not "204 No Content".
- `GET /api/dns-monitors` accepts `customerId` only as a positive integer.
- `POST /api/customers/:id/domains` requires a customer UUID; numeric customer ids are refused.
- `GET /api/customers` paginates on `page`/`perPage`, and the organization comes from the token (`organizationId` is
  not required).
- Error responses carry the machine code in `data.code`; the rate limit sends `Retry-After` only, no
  `X-RateLimit-Remaining`.
- `GET /api/incidents` includes `publicId` on the nested `customer` and `website` objects.
