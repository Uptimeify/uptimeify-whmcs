# Smoke test (acceptance)

> **Status: OPEN — nicht in der Sandbox bewiesen, Lizenz fehlt.**
> No step below has been run: no WHMCS development licence and no WHMCS release archive were available.
> Until every step has passed, green unit tests are not an acceptance.

Setup: [`README.md`](README.md). Run from the repository root. Record every deviation from an "Expected" in the
result log at the end and open an issue for it; a failed step blocks the release.

**Fresh install required.** The 0.1.0 schema was changed before the first release (`monitor_map.origin`,
`monitor_map.admin_paused`, table `mod_uptimeify_detached`) and there is no upgrade step for pre-release builds. If the
module was ever activated in this sandbox with an older build, deactivate it with "Delete tables and settings" on (or
start over, sandbox README "Everyday commands") before step 1.

Shorthand used below:

```sh
SQL() { docker compose -f docker/docker-compose.sandbox.yml exec -T db mariadb -uwhmcs -pwhmcs whmcs -e "$1"; }
```

Hostnames: the Uptimeify sandbox SSRF guard may reject `.example` hosts. Replace every `*.example` host below
(`sandbox-smoke.example`, `sandbox-domain.example`, `sandbox-adopt.example`, `sandbox-moved.example`, …) with public
hostnames you own (they need an A record, see step 14 for one without).

"Module log" means **Utilities → Logs → Module Log** with debug logging on (step 21); clear it before a step that
counts requests.

## A. Install and connect

1. `make sandbox-up`, log into the admin area, activate the module (README "First run").
   Expected: activation message without error; `SQL 'show tables like "mod_uptimeify%"'` lists five tables
   (`customer_map`, `detached`, `monitor_map`, `queue`, `status_cache`);
   `SQL 'SHOW COLUMNS FROM mod_uptimeify_monitor_map'` includes `origin` (default `adopted`) and `admin_paused`
   (default `0`); `make sandbox-logs` shows no PHP warning or fatal.
2. WHMCS schema matches `lib/Whmcs/*Repository.php`:
   `SQL 'DESCRIBE tblclients; DESCRIBE tblhosting; DESCRIBE tbldomains; DESCRIBE tblservers; DESCRIBE tbladdonmodules'`.
   Expected: `tblclients` has `id firstname lastname companyname email status`; `tblhosting` has
   `id userid domain domainstatus server`; `tbldomains` has `id userid domain status expirydate registrar`;
   `tblservers` has `id name ipaddress disabled`; `tbladdonmodules` has `module setting value`.
   A mismatch is fixed only in the matching repository/row file.
   Also `SQL "DESCRIBE tblmodulelog"`: note the request/response column names for step 21.
3. Primary keys of the addon tables (older Illuminate versions add a PK with a separate `ALTER`):
   `SQL 'SHOW CREATE TABLE mod_uptimeify_customer_map\G SHOW CREATE TABLE mod_uptimeify_monitor_map\G SHOW CREATE TABLE mod_uptimeify_status_cache\G SHOW CREATE TABLE mod_uptimeify_detached\G'`.
   Then deactivate (default: tables kept), and as root force inline PKs and drop the tables:
   `docker compose -f docker/docker-compose.sandbox.yml exec -T db mariadb -uroot -proot whmcs -e 'SET GLOBAL innodb_force_primary_key=1; DROP TABLE mod_uptimeify_customer_map, mod_uptimeify_monitor_map, mod_uptimeify_queue, mod_uptimeify_status_cache, mod_uptimeify_detached'`,
   activate again, then `… -e 'SET GLOBAL innodb_force_primary_key=0'`.
   Expected: every table has its `PRIMARY KEY` (customer_map `whmcs_client_id`, monitor_map
   `kind, whmcs_ref_id, monitor_type`, queue `id`, status_cache `key`, detached `monitor_type, monitor_public_id`);
   re-activation under the forced PK succeeds (a `CREATE TABLE` without inline PK fails here, as it would on MySQL with
   `sql_require_primary_key`).
4. Open **Addons → Uptimeify**.
   Expected: setup form. The **Advanced** `<details>` block renders and opens in the admin theme. View source: every
   POST form carries the hidden CSRF field from `generate_token('form')` (`<input type="hidden" name="token" …>`).
   Set **API base URL** to `http://host.docker.internal:3001` there **before** connecting.
5. Paste an organization-scoped `wsm_` token created in the Uptimeify sandbox, click **Connect**.
   Expected: flash "Connected to <org>"; the browser lands on a GET URL (the `header('Location')` + `exit` in
   `_output` works, no "headers already sent"); the **Settings** tab lists the packages. Choose the default package,
   keep "When a hosting is terminated" = pause, save. Reload: values kept.
   Organization binding (D9):
   `SQL "SELECT setting, value FROM tbladdonmodules WHERE module='uptimeify' AND setting IN ('organization_name','organization_public_id')"`
   shows the organization's name and its `publicId` (compare with `GET /api/organization` in the Uptimeify sandbox).

## B. Hooks, cron, sync, lifecycle

6. Create client "Sandbox Kunde" in the admin area.
   Expected: `SQL 'SELECT id, job, ref_id, status FROM mod_uptimeify_queue ORDER BY id DESC LIMIT 5'` shows
   `client.upsert` for the new client id. This proves `add_hook` accepts the array callables in `hooks.php`; if no
   row appears and the Activity Log is silent, wrap the callables in closures.
7. Create a product using the **Auto Release** server module (so Suspend/Unsuspend/Terminate run module commands and
   fire `AfterModule*`), give "Sandbox Kunde" an active hosting for `sandbox-smoke.example`, and add a domain
   `sandbox-domain.example` with status Active (any registrar).
   Expected: creating the hosting via the module queues `hosting.activate`. Record whether adding the domain queued a
   `domain.upsert` (only `AfterRegistrar*`, transfer and `DomainEdit` hooks queue one; a domain added without a registrar
   action is expected to queue nothing and to come from the daily reconcile in step 8).
8. `make sandbox-cron` (spec §12: the cron drives the sync, no button pressed yet).
   Expected: queue rows `client.upsert` and `hosting.activate` status `done`; the Uptimeify sandbox shows customer
   "Sandbox Kunde" (custom field `whmcs_client_id`) with a website monitor for `sandbox-smoke.example` (custom field
   `whmcs_hosting_id`), tagged `whmcs`. The customer domain for `sandbox-domain.example` comes from the daily
   reconcile (`DailyCronJob`, forced by `all --force`) and should appear in the same run, tagged `whmcs`. If it does
   not, record that, and whether a second `make sandbox-cron` creates it.
   `SQL 'SELECT kind, whmcs_ref_id, monitor_type, origin, admin_paused FROM mod_uptimeify_monitor_map'`: both rows
   `origin = created`, `admin_paused = 0` (D1).
9. Dashboard → **Reconcile now**.
   Expected: two monitor map rows `active` (`SQL 'SELECT kind, whmcs_ref_id, monitor_type, state FROM mod_uptimeify_monitor_map'`);
   **Reconcile now** creates nothing new in the Uptimeify sandbox (same monitors as after step 8, no duplicates). Only if
   step 8 recorded a missing domain monitor may it appear now. The dashboard has **Reconcile now** and **Refresh status**
   but no "Mirror all" button (D8).
10. Suspend the hosting, `make sandbox-cron`.
    Expected: queue row `hosting.pause` status `done`; the monitor is `paused` in the addon dashboard and in the
    Uptimeify sandbox.
11. Unsuspend, `make sandbox-cron`.
    Expected: `hosting.activate` done, monitor `active` in both UIs.
12. Terminate, `make sandbox-cron`.
    Expected: `hosting.remove` done, monitor `paused` (default). Then set "When a hosting is terminated" = delete,
    create a second hosting, `make sandbox-cron`, terminate it, `make sandbox-cron`: that monitor (`origin = created`) is
    gone in the Uptimeify sandbox and its map row is deleted.
13. Close "Sandbox Kunde" (Client Profile → Close Client), `make sandbox-cron`.
    Record: `SQL 'SELECT status FROM tblclients WHERE id=<id>; SELECT id, domainstatus FROM tblhosting WHERE userid=<id>; SELECT id, status FROM tbldomains WHERE userid=<id>'`
    (which statuses WHMCS sets on close).
    Expected: `tblclients.status = 'Closed'`; `client.pause_all` done; website monitors paused, other monitors kept.
    Re-activate the client afterwards (and its services) for the following parts.
14. DNS monitor on a domain without an A record: enable "DNS monitor per registered domain" in Settings, add an
    Active domain that has NS records but no A record (parked or mail-only), **Reconcile now**, wait for two check
    intervals in the Uptimeify sandbox.
    Expected (known risk, record the outcome): a DNS monitor with `rrtypes A, NS`, `triggerOn.resolveError`. Note
    whether it alerts; the domain from step 7 (with A record) must not alert.
15. IDN: add an Active domain with a Unicode name (e.g. `bücher-<yours>.de`).
    Record: `SQL 'SELECT domain FROM tbldomains ORDER BY id DESC LIMIT 1'` (Unicode or `xn--` punycode).
    Expected: **Reconcile now** twice creates exactly one domain monitor for it; the second run adopts, never duplicates.

## C. Client area, widget, logs

16. Log in as the client (Login as Owner), open **Services → Monitoring**.
    Expected: the "Monitoring" item sits under **Services** (the theme's child is named `Services`; if it appears at
    the navbar root, record the real child name); the page renders from `templates/overview.tpl` (`templatefile`
    `overview`); tiles and table; no write controls; the page loads in under 1 s from cache (browser devtools, second
    load). Switch the client language to Deutsch: the page is German.
17. Open the product details page of the hosting.
    Expected: the status block is shown (WHMCS passes `$vars['service']` with an `id` to
    `ClientAreaProductDetailsOutput`). Change the service id in the URL to another client's service: WHMCS refuses,
    and no block with foreign data appears.
18. Escaping: set the brand name to `A&B <b>X</b>` in Settings, `make sandbox-cron`, then open the client page and
    the product block. On the admin home, first look at the widget immediately (WHMCS serves the widget body from its
    `getData()` cache for up to 5 min), record title and body text; then wait 5 min (or use the widget's refresh
    control) and record both again.
    Expected: the brand appears literally as `A&B <b>X</b>` everywhere, never bold and never as `A&amp;B` (which would
    mean Smarty or WHMCS escapes already escaped output a second time); this covers the widget title, which
    `StatusWidget` escapes itself. Record whether title and cached body showed different brands before the cache
    expired (known lag ≤ 5 min). Reset the brand name.
19. Admin home.
    Expected: the Uptimeify widget renders (the real `AbstractWidget` needs no parent constructor call, no fatal in
    `make sandbox-logs`) and shows totals from the cache; after a disconnect it may lag up to 5 min (WHMCS widget cache).
20. MySQL user locks: `SQL "SELECT GET_LOCK('uptimeify_smoke', 0) AS g, IS_FREE_LOCK('uptimeify_smoke') AS f, RELEASE_LOCK('uptimeify_smoke') AS r"`.
    Expected: `g=1 f=0 r=1` (no NULL, no permission error). Reload the client Monitoring page while
    `make sandbox-cron` runs: no error, the page serves the cached snapshot.
21. **Utilities → Logs → Module Log** (enable debug logging first), then run **Reconcile now** once.
    Expected: API calls logged under `uptimeify`, the token masked;
    `SQL "SELECT COUNT(*) FROM tblmodulelog WHERE request LIKE '%wsm_%' OR response LIKE '%wsm_%'"` returns `0`.
22. WHMCS users (D10), account owner and a sub-user without products. Log in as the account owner of "Sandbox Kunde"
    (its own login, not an admin): menu item, page with data and product block as in steps 16–17. Then invite a second
    user (client area **Account → User Management → Invite New User**, or the admin client profile → **Users**) with
    only the invoices permission, accept the invite from Mailpit, and log in as that user for "Sandbox Kunde". Clear the
    Module log first.
    Expected: no "Monitoring" menu item; `index.php?m=uptimeify` shows the no-permission note ("You do not have
    permission to view the monitoring of this account …"); the product details page has no status block; the Module
    log has no `uptimeify` request from these page views.
    Record the raw permission answer (gate for `Bridge::currentUserCanViewProducts()`): find the ids with
    `SQL 'SELECT id, email FROM tblusers; SELECT * FROM tblusers_clients'` (record the real table and column names if
    they differ), then
    `docker compose -f docker/docker-compose.sandbox.yml exec -T -u www-data php php -r 'require "/var/www/html/init.php"; var_export(localAPI("GetUserPermissions", ["user_id" => <user id>, "client_id" => <client id>]));'`.
    Expected / record: `result` is `success` and the answer carries a `permissions` field; note its exact shape (list
    of names or comma-separated string). If the field is missing or shaped differently, step 23 fails closed and
    `Bridge::permissionList()` needs the real shape (release blocker for sub-user access, not for owners).
23. Sub-user with products (D10). Add the products permission ("View products & services", id `products`) for that
    user, reload.
    Expected: menu item, page with data and product block appear for the sub-user. Record the `GetUserPermissions`
    answer again (step 22 command). Revoke `products` again, reload: denied again at once (no cached permission).
    A user linked to two accounts with `products` only on account A: switching to account B shows the no-permission
    note there.
24. Login as Owner (D10). Admin area → client profile of "Sandbox Kunde" → **Login as Owner**.
    Expected: menu item, page and product block are shown (`isMasqueradingAdmin()` or the owner check). Confirm that an
    admin cannot reach the page as the invoices-only sub-user this way (Login as Owner always acts as the owner).

## D. Configuration never deletes, adoption, admin pause (round 2)

25. Adopted monitors and end events (D1). In the Uptimeify sandbox create, for customer "Sandbox Kunde", a website
    monitor `https://sandbox-adopt.example` and a customer domain `sandbox-adopt-domain.example`. In WHMCS give the
    client an Auto Release hosting for `sandbox-adopt.example` and an Active domain `sandbox-adopt-domain.example`;
    keep "When a hosting is terminated" = delete (step 12). `make sandbox-cron`.
    Expected: both map rows `origin = adopted`, no duplicate monitor in Uptimeify.
    Terminate the hosting and set the domain to Cancelled, `make sandbox-cron`.
    Expected: the adopted website still exists in Uptimeify, paused (status inactive), its row `paused`; the adopted
    customer domain still exists, its map row is gone, and the Activity Log has "Monitor detached: …". Nothing the addon
    did not create was deleted.
26. Configuration changes detach and keep the origin (D1). Give the client a new Auto Release hosting
    `sandbox-toggle.example`, `make sandbox-cron` (row `origin = created`). Clear the Activity Log view.
    - Settings: the monitor-type switches show the note that switching a type off detaches monitors. Switch "Website
      monitor per active hosting" off, save, **Reconcile now**.
      Expected: the website rows are gone from `mod_uptimeify_monitor_map`, every website monitor is untouched in
      Uptimeify (still active, not paused, not deleted), one "Monitor detached: … type_disabled …" line per row, and
      `SQL 'SELECT * FROM mod_uptimeify_detached'` holds `origin = created` for `sandbox-toggle.example`.
    - Switch it on again, **Reconcile now**.
      Expected: rows back, no duplicate monitor; `sandbox-toggle.example` is `origin = created` again (not `adopted`),
      its detached record is gone. Terminate it, `make sandbox-cron`: it is deleted (on_terminate delete applies to
      created).
    - Repeat with the Customers tab (it shows the detach note): "Sandbox Kunde" → Never, **Reconcile now**
      (rows detached, monitors untouched, reason `client_filter`), back to Default, **Reconcile now** (re-adopted,
      origins as before).
    - Give the client an Auto Release hosting `sandbox-ignore.example`, `make sandbox-cron` (row `origin = created`).
      Dashboard → **Pause** on its website. Clear the Module log, then **Ignore** on that target.
      Expected: no request in the Module log (no PATCH, no DELETE); the target's rows are gone from the table and from
      `mod_uptimeify_monitor_map`; below the table "Ignored targets" lists `sandbox-ignore.example` as "ignored" with
      **Stop ignoring**; one "Monitor detached: … ignored_target …" line per row; `mod_uptimeify_detached` holds the
      website with `origin = created`, `admin_paused = 1`; in Uptimeify the website is still paused and any other
      monitor of the target unchanged.
      **Stop ignoring**, `make sandbox-cron`.
      Expected: `hosting.activate` for the service was queued and is `done`; rows back without a duplicate monitor,
      the website `origin = created` and "paused (admin)", its detached record gone. **Activate** it afterwards.
    - Suspend a hosting, `make sandbox-cron`, then switch the website type off, **Reconcile now**: the row is detached
      (reason `type_disabled`), not handled as an end event; the monitor keeps its state in Uptimeify. Switch the type on
      and unsuspend afterwards.
27. Domainless VPS counts for the server IP (D3). Give a server (Auto Release is fine) an IP in **Servers**
    (`tblservers.ipaddress`), enable "Blacklist (DNSBL)" in Settings, and create for "Sandbox Kunde" an Active hosting on
    that server **without a domain** (product type server/VPS). `make sandbox-cron` (or **Reconcile now**).
    Expected: one customer IP monitor for that IP under the client, labelled with the server name, map row
    `kind = server_ip`; no website or domain-expiry monitor for the domainless hosting. Dedicated IPs
    (`tblhosting.dedicatedip`) are not monitored.
28. Server IP pause and resume (D5). Suspend every hosting of the client on that server, `make sandbox-cron`.
    Expected: the customer IP is `disabled` in Uptimeify (not deleted), its row `paused`. Unsuspend one,
    `make sandbox-cron`: `active` again.
    - IP quota: use up the customer's active-IP quota in Uptimeify (package with a small IP limit, or other active IPs),
      suspend and unsuspend again, `make sandbox-cron`.
      Expected: the resume is refused with the IP limit; the row is `error` with `last_error = activeIpLimitReached`
      (dashboard "quota reached"), the rest of the run continues, and the job is not retried in a loop (record whether
      it ends `done` or `dead`). Free the quota and **Reconcile now**: active.
    - Record (hand-off note): set the only hosting on that server to Pending, `make sandbox-cron`: this counts as an end
      event (created IP deleted, adopted IP detached).
    - Terminate all of the client's hostings on that server, `make sandbox-cron`: a created IP is deleted.
29. Changed domain: update in place (D4). Give the client an Auto Release hosting `sandbox-moved.example`,
    `make sandbox-cron`, note the website's `publicId` and one check in its history. Clear the Module log. In the admin
    area change the service's domain to `sandbox-moved2.example` (client profile → Products/Services → Domain, Save
    Changes).
    Expected: `SQL 'SELECT job, ref_id, status FROM mod_uptimeify_queue ORDER BY id DESC LIMIT 3'` shows
    `hosting.activate` for that service id, queued by `ServiceEdit` (if not, record it: the change then waits for the
    daily reconcile). Then `make sandbox-cron`.
    Expected: the job is `done`; the same website `publicId` now has URL `https://sandbox-moved2.example`, the history
    is kept, no second website and no orphan; the map row `target` is the new host. The Module log shows one
    `PATCH /api/websites/<publicId>` with `url` and `name` only (no `customerId`).
    - Manual status change (record): create a product with server module "None", give the client an Active service
      with a public domain for it and **Reconcile now** (website monitor created). Change that service's **Status** to
      Suspended in the admin area and Save Changes; then on the domain from step 7 change the status to Cancelled and
      save. Record whether `ServiceEdit` / `DomainEdit` queued `hosting.activate` / `domain.upsert`.
      Expected when queued: after `make sandbox-cron` the website is paused and the created domain monitor is deleted.
      Restore both statuses (and save) afterwards; the monitors come back with the next cron.
    - Edit the URL of the `sandbox-moved2.example` website in the Uptimeify UI to another host, `make sandbox-cron` (the daily reconcile):
      the URL is pointed back to the WHMCS domain in place. Record whether **Reconcile now** alone also does it (known
      gap: a budgeted run may leave it for the cron).
    - `invalidUrl`: change the domain of the `sandbox-moved2.example` service to a host the SSRF guard refuses (e.g. `localhost` or a private IP),
      Save Changes, `make sandbox-cron`.
      Expected: the row is in error with `invalidUrl`, the monitor keeps its previous URL; the `hosting.activate` job is
      `pending` with `attempts = 1` and a later `next_run_at` (backoff, not dead). **Reconcile now** sends no PATCH for
      it (Module log). **Sync selected** on the row sends one PATCH at once (still refused). Set a public domain again,
      Save Changes, `make sandbox-cron`: updated in place, error cleared, the job `done`.
    - Domain that does not resolve yet: give the client an Auto Release hosting for a name under a zone you control that
      has **no DNS record yet** (e.g. `new-<yours>.example-zone`), `make sandbox-cron`.
      Expected: the website row is in error with `invalidUrl` (Module log: 400 with "could not be resolved"), the job is
      pending with backoff. Create the DNS record, wait until it resolves from the Uptimeify sandbox, then
      `make sandbox-cron` again after the backoff (or **Sync selected**).
      Expected: the website is created, the row `active`, `last_error` empty. (Without a controllable zone: record it as
      not run; the daily reconcile also retries such a row once per run.)
    - Adopted website: create a website `https://sandbox-adopt2.example` for "Sandbox Kunde" in Uptimeify, give the
      client an Auto Release hosting for that domain, `make sandbox-cron` (row `origin = adopted`), then change the
      hosting's domain to `sandbox-adopt3.example`, **Reconcile now**.
      Expected: the adopted website is detached (untouched in Uptimeify, URL unchanged, record in
      `mod_uptimeify_detached`) and a new `created` website is made for the new host.
30. Persistent admin pause (D7). Dashboard → **Pause** on an active website.
    Expected: flash "… stays paused until you click "Activate"."; state "paused (admin)"; `admin_paused = 1`.
    Then each of these leaves it paused (`admin_paused = 1`, status inactive in Uptimeify): **Reconcile now**;
    `make sandbox-cron`; resume the monitor in the Uptimeify UI and **Reconcile now** (paused again); suspend and
    unsuspend its hosting with `make sandbox-cron` after each; switch the website type off, **Reconcile now**, on again,
    **Reconcile now** (the detached record carried `admin_paused = 1`). Same **Pause** on a blacklist IP row: the IP
    stays `disabled`.
    **Activate**: `admin_paused = 0`, the monitor resumes because WHMCS still wants it. A pause of a hosting that is
    then terminated (on_terminate delete, created) is still deleted: end events apply.
31. Admin pause on a shared monitor (D7, A2 check). Create a second server with the **same IP** as the server of step 27,
    and an Active domainless hosting of the client on it, **Reconcile now**: both `server_ip` rows share one customer IP
    monitor. **Pause** one row (both show "paused (admin)"), then disable the first server (Servers → Disable),
    **Reconcile now**: that row is detached (`server_unavailable`), the detached record has `admin_paused = 1`.
    **Activate** on the remaining row, then enable the first server again and **Reconcile now**.
    Expected: the IP resumes on Activate and stays active after the reconcile; the re-adopted row is not paused and the
    detached record is gone.
32. Client deleted (D6). Create a throwaway client with one created website (Auto Release hosting) and one adopted
    customer domain (as in step 25), `make sandbox-cron`, open its client area page once (so `client:<id>` exists).
    Delete the client in WHMCS, `make sandbox-cron`.
    Expected: `client.remove` done; the created website is handled like a terminated hosting (on_terminate), the adopted
    domain is detached; `SQL "SELECT * FROM mod_uptimeify_customer_map WHERE whmcs_client_id=<id>"` is empty,
    `SQL "SELECT \`key\` FROM mod_uptimeify_status_cache WHERE \`key\` IN ('client:<id>','client_retry:<id>','client_conflict_log:<id>')"`
    is empty; the Uptimeify customer still exists.

## E. Dashboard, incidents, organization (round 2)

33. Refresh status is read-only (D8). Note `SQL 'SELECT COUNT(*) FROM mod_uptimeify_queue'` and the monitor list in
    Uptimeify, clear the Module log, dashboard → **Refresh status**.
    Expected: flash "Status refreshed: N monitors …"; the Module log has only `GET` requests
    (`/api/organization`, `/api/customers`, the monitor lists of the enabled types) and no `/api/incidents`; queue count
    unchanged; no monitor created, paused, resumed or deleted; dashboard statuses updated. **Reconcile now** also
    requests no `/api/incidents`.
34. Bounded incident window (D2). The incident note in the client area appears only while a monitor of that client is
    down: make one of the client's websites fail (point the domain at a closed port or stop its host) until Uptimeify
    opens an incident.
    - With fewer than 1,000 incidents in the organization: `make sandbox-cron` → widget, dashboard and client area show
      the open incident without a "may be incomplete" note.
    - With more than 1,000 incidents (seed them in the Uptimeify sandbox; record if that is not feasible):
      `make sandbox-cron` → the Module log shows at most two `GET /api/incidents` requests per run (500 each); widget and
      dashboard say "Open incidents may be incomplete …"; the client area says so only while one of the client's
      monitors is down. **Reconcile now** and **Refresh status** request no incidents and keep the open incidents last
      read.
35. Token of another organization is refused (D9). Create a second organization in the Uptimeify sandbox with its own
    organization-scoped token. **Settings → Set token again** with it.
    Expected: refusal naming both organizations; the dashboard still works with the old token
    (`organization_public_id` unchanged); Settings offers **Switch organization** and **Keep the current organization**;
    `SQL "SELECT setting FROM tbladdonmodules WHERE module='uptimeify' AND setting IN ('pending_api_token','organization_pending')"`
    lists both rows (the pending token encrypted). Do not click either button yet.
36. Runtime mismatch aborts runs (D9). Save the current token:
    `SQL "SELECT value FROM tbladdonmodules WHERE module='uptimeify' AND setting='api_token'"` (keep the value locally,
    never paste it anywhere). Put the pending token of step 35 in its place:
    `SQL "UPDATE tbladdonmodules t JOIN (SELECT value FROM tbladdonmodules WHERE module='uptimeify' AND setting='pending_api_token') p SET t.value = p.value WHERE t.module='uptimeify' AND t.setting='api_token'"`.
    Queue one job (edit "Sandbox Kunde"), `make sandbox-cron`, open the addon.
    Expected: the run stops after `GET /api/organization` (no other request in the Module log, the job stays pending);
    every tab shows the banner "Synchronisation is stopped: the API token now belongs to the organization …";
    **Reconcile now**, **Sync selected** and **Refresh status** stop with the organization error; **Pause**, **Activate**,
    **Ignore** and **Choose customer** are refused; the client Monitoring page shows the last snapshot and sends no API
    request. Restore the saved `api_token` value with an `UPDATE`, `make sandbox-cron`: banner gone, the job runs.
37. Switch organization and back (D9). Settings → **Keep the current organization**.
    Expected: the pending token is discarded, the binding is unchanged. **Set token again** with the second
    organization's token → **Switch organization** → confirm.
    Expected: `SQL 'SELECT COUNT(*) FROM mod_uptimeify_customer_map; SELECT COUNT(*) FROM mod_uptimeify_monitor_map; SELECT COUNT(*) FROM mod_uptimeify_queue; SELECT COUNT(*) FROM mod_uptimeify_status_cache; SELECT COUNT(*) FROM mod_uptimeify_detached'`
    all `0`; nothing deleted in the first organization (its monitors keep running); the sidebar shows the new
    organization; `organization_public_id` is the new `publicId`; **Reconcile now** maps the clients to customers of the
    second organization. Switch back to the first organization the same way for the next steps, **Reconcile now**.
    Record: the existing monitors of the first organization come back as `origin = adopted` (the switch cleared the
    local records, so the addon no longer knows it created them).

## F. Screenshots

38. Save screenshots of the admin dashboard (with a "paused (admin)" row), the Customers tab, the Settings tab, the
    client Monitoring page, the product block and the admin widget under `docs/sandbox-screenshots/`, then **open and
    look at each one**. Screenshot list for the Marketplace: `docs/marketplace/listing.md`.
    Expected: no raw language keys, no escaped entities, no broken layout; buttons (`btn-default`/`btn-secondary`),
    badges and cards styled in the active client theme (twenty-one); the Customers datalist picker and the `form=`
    checkboxes work in the admin theme.

## G. Module lifecycle

39. Hook reload on re-save (README claim): temporarily add
    `add_hook('AdminAreaFooterOutput', 1, static fn (): string => '<!-- uptimeify-smoke -->');` to `hooks.php`, reload
    an admin page and view source; then **System Settings → Addon Modules → Uptimeify → Save Changes**, reload again.
    Expected / record: whether the comment appears before the save; it appears after the save. `git checkout hooks.php`.
40. `_upgrade` on version change: set `VERSION` in `lib/Version.php` to `0.1.1` (not committed), open the addon page.
    Expected: no error; `SQL "SELECT value FROM tbladdonmodules WHERE module='uptimeify' AND setting='version'"` shows
    `0.1.1`; tables and data intact; opening the page again runs nothing twice. `git checkout lib/Version.php`.
41. Deactivate with "Delete tables and settings" **off**.
    Record: `SQL "SELECT setting FROM tbladdonmodules WHERE module='uptimeify'"` (does WHMCS delete the rows itself?).
    Expected: the five tables stay; after activating again the module is still connected with its settings and its
    organization binding. If WHMCS deleted the rows, the "settings are kept" message is wrong: open an issue.
42. Deactivate with "Delete tables and settings" **on**.
    Expected: no `mod_uptimeify_*` table and no `tbladdonmodules` row for `uptimeify`; activating again shows the
    setup form. No remote monitor was deleted.

## Result log

| Date | WHMCS version | Tester | Steps passed | Deviations / open issues |
| --- | --- | --- | --- | --- |
| — | — | — | none run (OPEN, licence missing) | — |
