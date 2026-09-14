# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versions follow [SemVer](https://semver.org/).

## [Unreleased]

### Added
- Addon setup with an organization-wide API token (stored encrypted), default package, monitor-type switches, check interval, termination behaviour and brand name.
- Mirror of WHMCS clients to Uptimeify customers (match by `whmcs_client_id`, email, name, else create) with website, domain-expiry, DNS and blacklist-IP monitors.
- Hook-driven queue worker on the 5-minute cron and a daily full reconcile with adoption, quota handling per customer and rate-limit backoff.
- Admin area with dashboard, customers, log and settings tabs, plus a status widget on the admin home page.
- Read-only client-area monitoring page and product details block; English and German; release ZIP, tag release workflow and marketplace metadata.
- Origin tracking (`created` / `adopted`) per monitor and a record of detached monitors, so a monitor keeps its origin and admin pause when it is taken over again.
- Persistent admin pause: "Pause" on the dashboard survives syncs, cron jobs and unsuspends until "Activate".
- In-place URL update of a created website when the hosting domain changes (same monitor, history kept).
- Blacklist IP monitors are paused while all of the client's hostings on the server are suspended; domainless VPS and dedicated services count for the server IP.
- Organization binding with an explicit, confirmed "Switch organization" that clears only local data (and a default package the new organization does not have).
- Read-only "Refresh status" dashboard action; bounded incident window with an "open incidents may be incomplete" note.
- Client area, navigation item and product block limited to the account owner, "Login as Owner" and users with the products permission.
- `ServiceEdit` and `DomainEdit` hooks queue a sync, so admin edits of a service or domain arrive with the next queue run.

### Changed
- Configuration changes never delete: a customer filter, a type switched off, an ignored target or a disabled server detaches the monitor and leaves it untouched in Uptimeify; "Ignore" on the dashboard detaches instead of pausing.
- WHMCS end events delete only monitors the addon created; adopted websites are paused, other adopted monitors detached.
- A deleted WHMCS client's customer mapping and cached status are removed; the Uptimeify customer is never deleted.
- `invalidUrl` is retried with limits (job backoff, once per daily reconcile), because a domain that does not resolve yet is refused with the same code as a private address.
