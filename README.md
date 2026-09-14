# Uptimeify for WHMCS

> Open-source WHMCS addon that mirrors your clients, hosting domains and registered domains to
> [uptimeify.io](https://uptimeify.io) uptime, domain-expiry and blacklist monitoring.

The addon is a thin, **API-only client** for the (closed-source) uptimeify.io platform. A WHMCS admin connects one
organization API token; from then on every WHMCS client is mirrored to an Uptimeify customer and every active hosting
domain, registered domain and server IP gets its monitor under that customer. Provisioning, suspension and
termination follow automatically through hooks and the WHMCS cron. Clients see their monitors **read-only** in the
client area. Nothing is sold or billed in WHMCS, and no monitoring logic runs there.

---

## Features

- **Connect & validate** — paste an organization-wide `wsm_…` token; the addon checks it against the API
  (organization handshake plus a scope probe) and refuses tokens bound to a single customer. The token is stored
  encrypted, and the installation is bound to that token's organization.
- **Mirror clients 1:1** — each WHMCS client maps to one Uptimeify customer: matched by the `whmcs_client_id` custom
  field, then email, then name, otherwise created with your default package.
- **Four monitor types, each switchable** — website monitor per active hosting, domain-expiry monitor per registered
  domain (and optionally per hosting domain), DNS monitor per registered domain, blacklist (DNSBL) monitor per server
  IP and client.
- **Hybrid sync** — hooks write queue jobs, the 5-minute cron works the queue, a daily full reconcile corrects drift
  and adopts monitors that already exist instead of duplicating them.
- **Lifecycle mirroring** — suspend pauses, unsuspend resumes, terminate pauses or deletes (your choice), closed
  clients get their websites paused. Only monitors the addon created are ever deleted; configuration changes never
  delete anything.
- **Admin dashboard** — every target with WHMCS client, Uptimeify customer, type, state, live status and last error;
  search, state filter, "Sync selected", per-row Activate / Pause / Ignore / Choose customer, "Reconcile now" and the
  read-only "Refresh status".
- **Customer filter** — blacklist or whitelist mode with Always / Never / Default per client, plus manual mapping.
- **Quota-aware** — a quota error skips only that customer and is shown as "quota reached"; the rest of the run
  continues.
- **Admin home widget** — "All monitors are up" or "N monitors down, M open incidents", from cache only.
- **Client area** — a Monitoring page with tiles, uptime, response times, open incidents and domain expiry, plus a
  status block on the product details page; WHMCS sub-users need the "products" permission.
- **White label** — a brand name replaces "Uptimeify" in admin and client area; optional status portal link.
- **English and German** throughout.

## How the mirror works (important)

In Uptimeify a **package belongs to a customer**, not to a monitor: every monitor inherits its limits from its
customer's package. The addon mirrors the WHMCS structure accordingly:

| Step | What the addon does |
| --- | --- |
| Resolve the customer | Stored mapping (still present remotely?) → customer whose custom field `whmcs_client_id` equals the client ID → email (trimmed, lower case) → name (company name, else first + last name). |
| No match, auto-create on | `POST /api/customers` with the **default package** and `customFields.whmcs_client_id`. |
| No match, auto-create off | The client stays unmapped ("no customer" on the dashboard); its monitors are skipped until you map it. |
| Matched by email or name | `whmcs_client_id` is written back to the customer so the next run matches by ID. |
| Plan the monitors | From the enabled types, after the customer filter and ignored targets (see table below). |
| Apply | Adopt an existing remote monitor with the same normalized target and customer, otherwise create it and tag it `whmcs`. |

| Source | Condition | Monitor |
| --- | --- | --- |
| `tblhosting` | status Active, domain not empty | Website `https://<domain>` |
| `tblhosting` | same, "Domain expiry monitor per hosting domain" on | Domain expiry `<domain>` |
| `tbldomains` | status Active | Domain expiry `<domain>` |
| `tbldomains` | same, "DNS monitor per registered domain" on | DNS `<domain>` (A and NS) |
| `tblservers` | enabled, IP set, "Blacklist (DNSBL)" on | Blacklist IP per client with at least one active hosting on the server (with or without a domain, so VPS and dedicated services count), labelled with the server name |

Rules the addon keeps:

- An existing customer's package is **never changed**.
- One Uptimeify customer belongs to at most one WHMCS client; a mapping that would share a customer is refused.
- A monitor used by several targets (the same host as hosting and registered domain, two servers with one IP) is
  created once and only retired when nothing references it any more.
- A mapped monitor that is missing remotely is first marked **orphaned**; only if it is still missing on the next load
  is the row removed or the monitor recreated (so a paging glitch never deletes or duplicates anything).
- Targets are normalized: lower case, no scheme, path or leading `www.`.
- Every mapped monitor remembers whether the addon **created** it or **adopted** an existing one, and whether you
  paused it on the dashboard. A detached monitor keeps both: when it is adopted again (a type switched back on, a
  client back to "Default"), a created monitor is still "created" and a paused one stays paused.
- **Configuration never deletes.** When a monitor drops out only because of your settings (customer filter "Never",
  whitelist without "Always", a monitor type switched off, an ignored target, a disabled server), the addon
  **detaches** it: the mapping goes, the monitor stays in Uptimeify untouched (also a suspended service's monitor), and
  the activity log says why. A detached monitor is no longer managed: WHMCS end events (terminate, delete) no longer
  reach it, so clean it up in Uptimeify if the service ends while it is detached.
- **Ignore** on the dashboard is such a configuration change: the target goes to the ignored list (shown as "ignored"
  below the table) and its monitors are detached at once, without any change in Uptimeify. To stop a monitor, use
  **Pause** instead (or first). **Stop ignoring** queues the target's services and domains; the next queue run takes
  the monitors over again with their previous origin and pause.

What happens on WHMCS events:

| WHMCS event | Website monitor | Domain-expiry and DNS monitors |
| --- | --- | --- |
| Hosting active (new or unsuspended) | create or resume | create (hosting domain, if enabled) |
| Hosting suspended | pause | keep |
| Hosting terminated, cancelled or deleted | pause or delete (setting "When a hosting is terminated") | delete |
| Domain active | — | create |
| Domain expired, transferred away, cancelled or deleted | — | delete |
| Client closed | pause all | keep |
| Client deleted | like terminated, for every monitor | delete |
| Hosting domain changed | the website is pointed at the new domain in place (same monitor and history) | old monitor deleted, new one created |

"Delete" and "When a hosting is terminated" apply only to monitors the addon **created**. An **adopted** website is
paused instead, any other adopted monitor is detached; the addon never deletes a monitor it did not create. The same
holds for a changed domain: an adopted monitor is detached and a new one is created for the new domain. If the new
URL is refused (`invalidUrl`), the row shows the error and the old monitor stays as it was.

`invalidUrl` is not only a private or malformed address: Uptimeify resolves the host when a website is created or its
URL changed, so a domain whose DNS is not set up yet (a new order, a domain just moved) is refused with the same code.
Such a row is therefore tried again: its queue job backs off like any other failure (1, 5, 30, 120 … minutes, dead
after 8 attempts), and every daily reconcile tries it once more. **Reconcile now** does not send it again from the
admin request; **Activate** or **Sync selected** on the row, or **Retry** of its dead job on the Log tab, does.

A blacklist IP monitor exists while the client has at least one active hosting on that server. When all of the
client's hostings on that server are suspended it is paused (disabled) and resumed on unsuspend; only when no active
or suspended hosting is left does the table above apply (created: deleted, adopted: detached). A hosting that is only
Pending does not count: when the client's only hostings on that server are Pending, that is treated as an end event
too. A closed client keeps
it. Dedicated IPs of a service (`tblhosting.dedicatedip`) are not monitored yet.

When a WHMCS client is deleted, its monitors follow the table above; then the addon forgets the client's customer
mapping and cached status. The Uptimeify customer itself is never deleted: that stays your decision in Uptimeify.

Known behaviour worth knowing:

- **DNS monitors** alert when a domain does not resolve (no answer, NXDOMAIN, SERVFAIL, timeout); record values are
  not compared. Domains without an A record (parked, mail-only) will therefore alert; turn the DNS type off if that
  applies to many of your domains.
- **"Pause" on the dashboard persists** (websites and blacklist IPs): the dashboard shows "paused (admin)", and no
  sync, cron job or unsuspend resumes the monitor; a monitor resumed in Uptimeify is paused again. **Activate**
  lifts the pause and resumes the monitor if WHMCS still wants it. A WHMCS end event (terminate, delete) still applies.
  The pause survives a detach and follows the service to a new monitor when its domain changes.
- Adopted websites and blacklist IPs that are paused in Uptimeify are resumed while WHMCS wants them, unless you
  paused them on the dashboard.

When changes reach Uptimeify:

- **Through a hook, with the next queue run (5-minute cron):** everything in the hook table under "Hooks and cron":
  a module create, suspend, unsuspend or terminate; a registrar registration or transfer; a client added, edited,
  closed or deleted; and **any edit saved in the admin area** on a service (`ServiceEdit`: its domain, status or
  server) or a domain (`DomainEdit`). Each job re-reads the live WHMCS object, so an edited domain updates the website
  in place and a status set by hand pauses, resumes or ends the monitors. That a status change saved on those pages
  fires `ServiceEdit` / `DomainEdit` is expected (the documented hook trigger) and is confirmed in smoke step 29. A client edit re-plans all of that client's
  monitors.
- **With the daily reconcile, within 24 hours:** changes WHMCS makes without firing one of these hooks: direct database
  changes, and status or domain changes made outside the admin edit pages without a module or registrar command (for
  example by WHMCS automation for a product without a server module, or a domain added without a registrar action).
  Whether a given automation fires a hook is a sandbox check (smoke steps 7 and 29). A hook for the same client earlier
  (e.g. a client edit), **Sync selected** or **Reconcile now** brings them in sooner.
- **"Sync automatically" off:** hooks queue nothing and neither the queue worker nor the daily reconcile runs. Dashboard
  actions still run in the request, but work they hand to the queue (the rest of a **Reconcile now** or **Sync
  selected** whose 20-second budget ran out, **Stop ignoring**, **Choose customer**) waits there until you switch it
  on again.

## Architecture

```
uptimeify.php            WHMCS entry points: _config, _activate, _deactivate, _upgrade, _output, _sidebar, _clientarea
hooks.php                Hook registrations only (see "Hooks and cron")
whmcs.json, logo.png     Marketplace metadata and 200×200 logo
lib/                     WHMCS\Module\Addon\Uptimeify\ (own PSR-4 loader in lib/autoload.php)
  Api/Client.php           REST client, error-code mapping, pagination, masked module log
  Api/Exception/           Unauthorized, QuotaExceeded, RateLimited, NotFound, ApiException
  Http/                    HttpClientInterface, CurlHttpClient (no Guzzle, no vendor code)
  Settings.php             Settings in tbladdonmodules, token encrypted
  Bridge.php               Wrapper for WHMCS functions and globals (localAPI, logs, session, CSRF tokens)
  Container.php            Wires the object graph per request
  Whmcs/                   Read-only repositories for clients, hosting, domains, servers
  Sync/                    MonitorPlanner, CustomerMirror, RemoteIndex, Reconciler, JobHandler, QueueWorker,
                           RateLimitGuard, SnapshotBuilder, TargetNormalizer, CustomerFilter, IncidentWindow,
                           OrganizationBinding
  Store/                   Schema, CustomerMap, MonitorMap, DetachedMonitors, Queue, StatusCache, SyncLock
  Admin/                   Dispatcher and controllers for Dashboard, Customers, Log, Settings (plus Setup)
  Client/                  Client area dispatcher, MonitoringController, ClientSnapshot
  Hooks/                   Client, Service, Domain, Cron and Navigation hooks
  Widget/StatusWidget.php  Admin home widget
templates/admin/         Plain PHP templates for the admin pages (incl. the organization switch)
templates/overview.tpl   Smarty template for the client area page (WHMCS looks up `templatefile` in templates/);
                         the product details block is rendered in PHP
lang/                    english.php, german.php (same keys, parity tested)
tests/                   PHPUnit with WHMCS stubs on SQLite and a fake HTTP client
docker/                  Dev toolchain image and the WHMCS sandbox (docker/sandbox/)
docs/                    API surface, design spec, Marketplace listing draft (not shipped)
bin/build.sh             Release ZIP
```

Own tables: `mod_uptimeify_customer_map`, `mod_uptimeify_monitor_map` (with `origin` created/adopted and
`admin_paused`), `mod_uptimeify_queue`, `mod_uptimeify_status_cache`, `mod_uptimeify_detached` (origin and admin pause
of detached monitors, so they survive a later re-adoption).

## Requirements

- WHMCS 8.13 or 9.0
- PHP 8.2 or 8.3 with `curl`, `json` and `mbstring` (WHMCS requires them anyway)
- The WHMCS system cron running **every 5 minutes** (see "Hooks and cron")
- An uptimeify.io account with an **organization-wide** API token (`wsm_…`)

No Composer dependencies are needed at runtime.

## Install

### From a release ZIP

1. Download `uptimeify-whmcs-<version>.zip` from the
   [Releases](https://github.com/Uptimeify/uptimeify-whmcs/releases) page.
2. Unpack it into `<whmcs>/modules/addons/`. The ZIP contains one folder, so you get `modules/addons/uptimeify/`.
3. In WHMCS: **System Settings → Addon Modules → Uptimeify → Activate**, then **Configure** and grant access to the
   admin role groups that should use it. Activation creates the module tables.
4. Open **Addons → Uptimeify**, paste the token and click **Connect**.
5. In **Settings** choose the default package and the monitor types, then run **Reconcile now** on the dashboard.

**Hooks are registered on activation.** WHMCS only picks up `hooks.php` of an activated addon. After you upgrade the
files manually, open **System Settings → Addon Modules** and save the Uptimeify configuration once so WHMCS reloads the
hooks. WHMCS runs the module's upgrade step (idempotent schema migrations) when it sees the new version.

### Build it yourself

```bash
git clone https://github.com/Uptimeify/uptimeify-whmcs.git
cd uptimeify-whmcs
make build-image
make build              # produces dist/uptimeify-whmcs-<version>.zip
```

The ZIP holds `uptimeify.php`, `hooks.php`, `whmcs.json`, `logo.png`, `lib/`, `templates/`, `lang/`, `LICENSE`,
`README.md` and `CHANGELOG.md`, nothing else (no tests, docker, docs, vendor, dotfiles).

## Configuration

The Setup page asks for the token (and, under **Advanced**, the API base URL). Everything else lives on the
**Settings** tab. All values are stored in `tbladdonmodules` under module `uptimeify`.

| Setting (key) | Default | Purpose |
| --- | --- | --- |
| API token (`api_token`) | — | Organization-wide `wsm_…` token, stored encrypted with WHMCS `EncryptPassword`. "Set token again" keeps the old token until the new one is verified. |
| API base URL (`api_base_url`) | `https://uptimeify.io` | `https://` only; `http://` only for `localhost` and `host.docker.internal` (sandbox). Changing it resets the connection. |
| Connection validated (`connection_validated`) | off | Internal. Set by a successful connect, reset on HTTP 401 or an unusable token. |
| Organization (`organization_name`, `organization_public_id`) | — | Internal. The organization this installation is bound to (see "Organization binding"); the name is shown in the sidebar. Disconnecting or changing the base URL keeps the binding. |
| Organization switch (`organization_mismatch`, `organization_pending`, `pending_api_token`) | — | Internal. A token of another organization found by a run, or a refused connect waiting for "Switch organization" (its token stored encrypted). |
| Default package (`default_package_type`) | none | Package for customers the addon creates. Existing customers keep their package. |
| Create missing customers automatically (`auto_create_customers`) | on | Off: unmatched clients stay unmapped. |
| Sync automatically (`auto_sync`) | on | Off: hooks enqueue nothing, and neither the queue worker nor the daily reconcile runs. Dashboard actions still run in the request, but work they hand to the queue waits there until you switch it on again (see "When changes reach Uptimeify"). |
| Website monitor per active hosting (`sync_hosting_website`) | on | Switching a type off detaches its monitors, it never deletes them. |
| Domain expiry monitor per hosting domain (`sync_hosting_domain_expiry`) | off | |
| Domain expiry monitor per registered domain (`sync_domain_expiry`) | on | |
| DNS monitor per registered domain (`sync_domain_dns`) | off | See the DNS note above. |
| Blacklist (DNSBL) monitor per server IP and client (`sync_server_ips`) | off | Counts against the customer's IP quota. |
| Check interval in minutes (`default_check_interval`) | 5 | 1–60; the package may impose a higher minimum. |
| Monitoring type for websites (`default_monitoring_type`) | `combined` | `combined` (HTTP status and SSL certificate), `http_status`, `ssl_check`. |
| When a hosting is terminated (`on_terminate`) | `pause` | `pause` or `delete` the website monitor the addon created. Domain-expiry monitors it created are always deleted. Adopted monitors are never deleted. |
| Customer filter mode (`filter_mode`) | `blacklist` | Customers tab. Blacklist: sync everyone except "Never". Whitelist: sync only "Always". Clients left out are detached, not deleted. |
| Per-client mode (`customer_filter`) | empty | Customers tab: Always / Never / Default per client. |
| Ignored targets (`ignored_targets`) | empty | Dashboard "Ignore" (detaches the target's monitors) / "Stop ignoring" (takes them over again). |
| Brand name (`brand_name`) | empty = "Uptimeify" | Shown to admins and clients. |
| Status portal URL (`status_portal_url`) | empty | Optional `http(s)` link shown in the client area. |
| Show the monitor status on the product details page (`product_block_enabled`) | on | |
| Delete tables and settings when the addon is deactivated (`drop_data_on_deactivate`) | off | Off: deactivating keeps mappings, queue and settings. Remote monitors are never deleted by deactivation. |

## Hooks and cron

Object hooks only write a row to the queue; they never call the API, and a failing hook never breaks the WHMCS
action (it logs one activity line instead).

| Hook | Queue job |
| --- | --- |
| `ClientAdd`, `ClientEdit` | `client.upsert` |
| `ClientClose` | `client.pause_all` |
| `ClientDelete` | `client.remove` |
| `AfterModuleCreate`, `AfterModuleUnsuspend`, `ServiceEdit` | `hosting.activate` (the job follows the live service state, so it also fits edits and status changes) |
| `AfterModuleSuspend` | `hosting.pause` |
| `AfterModuleTerminate`, `ServiceDelete` | `hosting.remove` |
| `AfterRegistrarRegistration`, `AfterRegistrarTransfer`, `DomainTransferCompleted`, `DomainEdit` | `domain.upsert` (follows the live domain state) |
| `DomainDelete` | `domain.remove` |
| `AfterCronJob` | Queue worker |
| `DailyCronJob` | Full reconcile |
| `ClientAreaPrimaryNavbar` | "Monitoring" menu item under Services, only for mapped clients and users allowed to view products |
| `ClientAreaProductDetailsOutput` | Monitor status block on the product details page |
| `AdminHomeWidgets` | Status widget |

**Cron requirement.** `AfterCronJob` runs with the WHMCS system cron. Configure the cron every 5 minutes as WHMCS
recommends (`*/5 * * * * php -q /path/to/whmcs/crons/cron.php`); with a less frequent cron, changes reach Uptimeify only
that often. `DailyCronJob` runs once a day with the same cron.

Queue worker (`AfterCronJob`):

- Runs only when connected and "Sync automatically" is on; shares one database lock (`uptimeify_sync`) with the
  reconcile, so they never overlap.
- Up to 50 due jobs per run, oldest first. Each job re-reads the live WHMCS object, so a late `hosting.pause` never
  pauses a hosting that is active again.
- Failure backoff 1, 5, 30, 120, 120 … minutes; after 8 attempts the job is **dead** (also for `invalidUrl`, see
  above). Quota errors and configuration errors (unknown package, customer-scoped token, missing default package,
  customer conflict) go to dead immediately. A known `invalidUrl` or quota error of a monitor the job did not send again
  (already tried in the same run) does not fail the job. Dead jobs are counted on the dashboard and can be retried from
  the **Log** tab.
- When jobs are due, the organization binding is checked first (one `GET /api/organization`); a token of another
  organization stops the run and every job waits.
- HTTP 401 resets the connection and stops the run; HTTP 429 stops the run and leaves the job pending.
- Jobs stuck in `running` for 15 minutes are reset; finished jobs are purged after 7 days.
- Every job is written to the WHMCS module log with the token masked.

Full reconcile (`DailyCronJob`, or **Reconcile now** on the dashboard):

- Checks the organization binding first (one `GET /api/organization`); a token of another organization stops the run
  before anything else is read or written.
- Loads customers and monitors page by page, plans the desired set, adopts, creates, pauses, resumes and retires as
  described above, and refreshes the status cache for the widget and the client area.
- A quota error skips only that customer; HTTP 429 sleeps as `Retry-After` says (at most 60 s, three times).
- From the dashboard it runs inside the request with a 20-second budget and without sleeping on 429; whatever is left
  is queued and the cron finishes it. It reads no incidents (see "Open incidents").
- The result is logged to the WHMCS activity log.

**Refresh status** on the dashboard only reads: after the organization check it loads the customer list and the
monitor lists of the types in use (GET requests only, 20-second budget checked before every list, no sleeping on 429)
and rewrites the status cache. It creates, pauses and deletes nothing, queues nothing and reads no incidents: the open
incidents shown stay those last read, with the time they were read. They are read again by the next queue-worker run
that handles jobs (the worker refreshes the cache only then), by the daily reconcile (within 24 hours), or by a
client-area page view once they are older than 5 minutes. If the budget runs out, nothing is written.

**Open incidents.** The Uptimeify incidents API has no filter for open incidents, so the addon reads only the newest
1,000 incidents (two pages of 500) in the daily reconcile, the queue worker (when it handled jobs) and the client-area
refresh. When older incidents exist, the widget and the dashboard say that open incidents may be incomplete (an
incident that started before those 1,000 and is still open is not shown); the client area says so only while one of
the client's monitors is down. Requests from the admin dashboard never read incidents; the counts they write keep the
open incidents last read.

### Organization binding

The first successful connect binds the installation to the token's organization (its `publicId`), because every
mapping, monitor row and queue job belongs to that organization.

- **Set token again** with a token of another organization is refused with a message naming both organizations; the
  working connection stays. The Settings page (or the setup page) then offers **Switch organization**, a separate,
  confirmed action: it checks the new token's organization again, deletes the addon's local data in WHMCS (customer
  mappings, monitor rows, queue, status cache, detached-monitor records; in one database transaction) and binds the installation to the new
  organization. Nothing is deleted in Uptimeify; the old organization's monitors keep running there. The default package
  stays only if the new organization has a package with the same key; otherwise it is cleared and you choose it again in
  Settings (so no customer is created against the old organization's package). Run **Reconcile now** afterwards.
  **Keep the current organization** discards the new token.
- Every cron reconcile, queue-worker run with due jobs, **Reconcile now**, **Sync selected** and **Refresh status**
  checks the organization once. If the stored token now belongs to another organization, the run stops, every tab shows
  a banner, and Activate, Pause, Ignore and Choose customer are refused until you set a token of the bound organization
  again or switch. The client area keeps showing the last snapshot and does not refresh meanwhile; the check never runs
  in the client area.

## Client area

- `index.php?m=uptimeify`, login required. The client ID comes from the WHMCS session only, never from the request.
- Tiles for total, online, down and paused; a monitor table with status, uptime for day / month / year, average
  response time and open incidents; domain monitors with expiry date and registrar; a list of open incidents with start
  time.
- Data comes from a per-client snapshot in the status cache. When it is older than 5 minutes, the page refreshes it
  with read-only API calls (5 s HTTP timeout, about 10 s overall). If that fails, the last snapshot is shown with a "may be
  out of date" note.
- Before anything is shown, the controller checks that the snapshot and every monitor and incident belong to the
  customer mapped to this client.
- Only monitors the addon currently maps to the client are shown. A detached or ignored monitor (see "Configuration
  never deletes") disappears from the page, the tiles and the product details block, even though it keeps running in
  Uptimeify.
- The "Monitoring" menu item appears only for clients with a mapping. The product details block (setting "Show the
  monitor status on the product details page") shows the website monitor of that hosting from the cached snapshot.
- **WHMCS users and permissions.** The page, the menu item and the product details block require that the logged-in
  user may view the account's products and services: the account owner, an admin who used "Login as Owner", or a user
  whose permissions for that account include `products`. The user's permissions are read for that account with the
  WHMCS API `GetUserPermissions`; the client model's own `hasPermission()` is not used because it takes no user. Anyone
  else sees a "no permission" note and no data is loaded; if WHMCS cannot tell (error, unexpected answer), nothing is
  shown.
- Open incidents may carry a "may be incomplete" note while a monitor of the client is down (see "Open incidents" above).
- There are no write actions in the client area.

## Development

No PHP or Composer on the host: everything runs in the dev container (`docker/Dockerfile.dev`).

```bash
git config core.hooksPath .githooks   # enable the secret-scan pre-commit hook
make build-image                      # once
make install                          # composer install in the container
make test                             # PHPUnit
make stan                             # PHPStan level 6
make lint                             # php-cs-fixer dry run
make fix                              # php-cs-fixer apply
make ci                               # lint + stan + test, like CI
make build                            # release ZIP into dist/
make shell                            # shell in the container
```

WHMCS is not available locally: `tests/stubs/whmcs.php` provides `Capsule` on in-memory SQLite, `add_hook`,
`localAPI`, `logModuleCall` and the other globals as recorders; `tests/Fake/FakeHttpClient.php` scripts API responses.

Unit tests are not an acceptance test. The WHMCS sandbox (`docker/docker-compose.sandbox.yml`, setup in
`docker/sandbox/README.md`, smoke test in `docker/sandbox/smoke.md`) needs a WHMCS development license, which the WHMCS
installer writes into `configuration.php` like on any WHMCS installation, and points `api_base_url` at a local
Uptimeify sandbox (`http://host.docker.internal:3001`), never at production with a real token.

**Sandbox acceptance: not yet proven.** The WHMCS development license is still pending, so no smoke-test step has been
run; green unit tests are all there is so far, and a passed smoke test is a release blocker. The 0.1.0 schema was
changed in place before the first release (no upgrade step exists for pre-release builds): install the sandbox fresh.

## CI / Releases

- **CI** (`.github/workflows/ci.yml`) — on pushes to `main` and on pull requests: PHP 8.2 and 8.3 matrix with
  `composer validate --strict`, php-cs-fixer, PHPStan and PHPUnit (including language parity and the release ZIP
  contents), then a package job that builds the ZIP and uploads it as an artifact.
- **Release** (`.github/workflows/release.yml`) — on a `v*` tag: runs the same quality steps on the same PHP 8.2 and
  8.3 matrix first (from a clean `composer install`, no cache), then fails unless the tag equals `v` +
  `Version::VERSION` (`lib/Version.php`) and `whmcs.json` carries the same version, builds the ZIP and publishes a
  GitHub Release with it. Tags containing `-rc` or `-beta` become pre-releases.
- **Workflow hardening** — both workflows run with a read-only `GITHUB_TOKEN` (`permissions: contents: read`); only
  the `release` job gets `contents: write`. Every third-party action is pinned to a full commit SHA with its version
  in a comment, checkouts do not persist credentials, and Dependabot (`.github/dependabot.yml`) proposes action
  updates weekly.

To cut a release (details in [VERSIONING.md](VERSIONING.md)):

```bash
# bump Version::VERSION and "version" in whmcs.json, move [Unreleased] in CHANGELOG.md, commit, then:
git tag v0.1.0
git push origin v0.1.0
```

The WHMCS Marketplace listing uses `whmcs.json` and `logo.png`; the addon is free. Listing text, screenshot list and
submission checklist: [`docs/marketplace/listing.md`](docs/marketplace/listing.md) (not submitted yet).

## Security & privacy

- The API token is stored encrypted (WHMCS `EncryptPassword`), never rendered in a template and masked in the module
  log.
- API calls use HTTPS only (plain HTTP only to `localhost` / `host.docker.internal` for the sandbox); every path
  parameter is URL-encoded.
- Admin pages rely on WHMCS' per-addon access control; every write is a POST checked with the WHMCS CSRF token, and IDs
  posted to admin actions are checked against the addon's own data.
- The client area only shows monitors of the customer mapped to the logged-in client and never writes.
- Data sent to Uptimeify: the client's display name (company name, else first and last name), email and
  `whmcs_client_id`; hosting and registered domain names;
  server IPs and names; the hosting ID as a custom field on website monitors.
- Deactivating the addon keeps local data unless configured otherwise and never deletes remote customers or monitors.

## License

[MIT](LICENSE) — this WHMCS addon is open source. The uptimeify.io platform itself is a separate, closed-source SaaS
product.
