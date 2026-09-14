# WHMCS Marketplace listing (draft)

> **Status: not submitted.** Submission is an owner action in the WHMCS Marketplace account and waits for the
> v0.1.0 release and the sandbox screenshots (`docker/sandbox/smoke.md` step 38). This file is repository
> documentation only; `bin/build.sh` does not ship `docs/`.

Source of truth for the texts is `whmcs.json` (`description.tagline`, `description.long`, `description.features`); the
fields below repeat them verbatim so the listing and the in-product "Apps & Integrations" entry say the same.
`PackagingTest` checks that every listing text in `whmcs.json` is free of links and domain names.

## Listing fields

| Field | Value |
| --- | --- |
| Product name | Uptimeify for WHMCS (`description.name`: Uptimeify) |
| Type | Addon module (`whmcs.json` `type`: `whmcs-addons`) |
| Category | **Utilities** (`whmcs.json` `category`: `utilities`), see "Category" below |
| Price | Free |
| Licence | MIT, open source |
| Version | 0.1.0 (`whmcs.json` `version` = `Version::VERSION`) |
| Logo | `logo.png`, 200 × 200 PNG |
| Compatibility | WHMCS 8.13 and 9.0; PHP 8.2 and 8.3 |
| Support channel | GitHub issues of the repository (`whmcs.json` `support.support_url`); documentation is the repository README (`support.docs_url`). Both go into the Marketplace's own support and documentation URL fields, never into the description. |

### Short description (tagline)

Mirror WHMCS clients and domains to Uptimeify uptime, domain-expiry and blacklist monitoring.

### Long description

Connect one organization API token and every active hosting domain and registered domain in WHMCS gets monitored in
Uptimeify under the matching customer. Provisioning, suspension and termination are mirrored automatically via hooks
and cron; clients see their monitors read-only in the client area. Free and open source.

(Line breaks here are for readability; the text is one paragraph, identical to `description.long`.)

### Feature bullets

- Mirrors WHMCS clients 1:1 to Uptimeify customers
- Website, domain-expiry, DNS and DNSBL monitors, each switchable
- Hook-driven queue plus daily reconcile
- Non-destructive: only monitors the addon created are ever deleted
- Read-only client-area monitoring page
- White-label brand name

### Extended description (optional Marketplace body text, no links)

Uptimeify for WHMCS is a thin, API-only addon: all monitoring runs in Uptimeify, nothing is sold or billed in WHMCS.

- Each WHMCS client maps to one Uptimeify customer, matched by client ID, email or name, or created with your default
  package. Existing customers keep their package.
- Monitors per active hosting (website), per registered domain (domain expiry, optional DNS) and per server IP and
  client (blacklist); VPS and dedicated services without a domain count for the server IP.
- Suspend pauses, unsuspend resumes, terminate pauses or deletes (your choice). A changed hosting domain updates the
  website monitor in place and keeps its history.
- Configuration changes never delete: monitors that drop out because of a filter, a type switch or an ignored target
  are only detached and keep running unchanged; WHMCS end events no longer reach a detached monitor. Monitors the
  addon did not create are paused or detached, never deleted.
- A pause set on the admin dashboard persists through syncs, cron jobs and unsuspends until an admin activates the
  monitor again.
- Admin dashboard with per-target status, "Reconcile now", a read-only "Refresh status", customer filter
  (blacklist or whitelist) and a home widget.
- The installation is bound to one Uptimeify organization; a token of another organization is refused unless you
  switch explicitly.
- Client area page and product details block, read-only, shown only to the account owner and users with the
  products permission.
- English and German, white-label brand name, token stored encrypted.

Requirements: WHMCS 8.13 or 9.0, PHP 8.2 or 8.3, the WHMCS cron every 5 minutes, an Uptimeify account with an
organization-wide API token.

## Category

Verified on 2026-09-14:

- The WHMCS Marketplace (marketplace.whmcs.com) lists these categories: Accounting & Finance, Analytics & Reports,
  Billing & Invoicing, Developer Tools, Domain Registrars, Email & Notifications, Localisation, Migration Tools,
  Order Management, Payment Gateways, Performance & Security, Productivity, Provisioning Modules, Support Tools,
  Templates & Themes, **Utilities**, Web Hosting, Other.
- The `whmcs.json` documentation (developers.whmcs.com, Advanced → JSON File) documents `category` but lists no
  allowed values; its examples use `notifications`, and WHMCS' sample addon uses `addon`.

Decision: keep `whmcs.json` `category` = `utilities` (a documented Marketplace category, lower case like the
documented examples) and choose **Utilities** in the submission form. Alternative for the owner at submission time:
**Performance & Security**, where the only other monitoring product seen (a monitoring resale offer) is listed. If the
owner picks it, the `whmcs.json` value stays `utilities` unless WHMCS documents a value for that category.

## Screenshots

Taken in the sandbox at smoke step 38 (`docs/sandbox-screenshots/`), each opened and checked before upload. At least
the first three (spec §13: dashboard, client area, widget).

| # | View | Shows | Smoke step where the state is set up |
| --- | --- | --- | --- |
| 1 | Admin dashboard | Targets with client, customer, type, state incl. "paused (admin)", buttons "Reconcile now" and "Refresh status" | 9, 30, 33 |
| 2 | Client area Monitoring page | Tiles, monitor table with uptime, open incidents, domain expiry | 16 |
| 3 | Admin home widget | "All monitors are up" / "N monitors down, M open incidents" | 19 |
| 4 | Product details block | Website monitor status and uptime on a hosting | 17 |
| 5 | Customers tab | Mapping, filter mode, Always / Never / Default, detach note | 26 |
| 6 | Settings tab | Default package, monitor-type switches with detach note, termination behaviour | 5, 26 |

Use the brand name "Uptimeify" (not a test brand from step 18) and sandbox data without real customer names or email
addresses.

## Submission checklist (owner)

- [ ] v0.1.0 released: tag pushed, `release.yml` green, `uptimeify-whmcs-0.1.0.zip` attached to the GitHub Release.
- [ ] Sandbox smoke test passed and logged (`docker/sandbox/smoke.md` result log); no open release blocker.
- [ ] Screenshots 1–3 (better 1–6) taken, opened and checked; no raw language keys, no escaped entities.
- [ ] `whmcs.json` version equals the release; tagline, long description and features copied verbatim from this file.
- [ ] Description, tagline and features contain no links or domain names (WHMCS rule; `PackagingTest`).
- [ ] Category chosen (Utilities, or Performance & Security, see above).
- [ ] Logo `logo.png` 200 × 200 uploaded.
- [ ] Support URL (GitHub issues) and documentation URL (README) set in the Marketplace fields; price Free.
- [ ] Compatibility WHMCS 8.13 and 9.0 selected only for versions the smoke test covered.
- [ ] Submitted from the Uptimeify WHMCS Marketplace account; record the listing URL and review outcome in the release notes.
