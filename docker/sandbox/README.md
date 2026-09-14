# WHMCS sandbox

> **Status: OPEN — nicht in der Sandbox bewiesen, Lizenz fehlt.**
> The stack below has never been started: there was no WHMCS development licence and no WHMCS release archive
> when it was written. `docker compose -f docker/docker-compose.sandbox.yml config` parses; the image
> `docker/whmcs.Dockerfile` has not been built. Unit tests being green is not an acceptance. The acceptance script is
> [`smoke.md`](smoke.md).

A local WHMCS 9 installation with the addon bind-mounted, talking to the local Uptimeify sandbox, never to production.

| Service | Image | Purpose |
| --- | --- | --- |
| `db` | `mariadb:10.11.19` | WHMCS database (strict mode off, `utf8_unicode_ci`), data in `docker/data/db/` (gitignored) |
| `php` | `docker/whmcs.Dockerfile` (`php:8.3.33-fpm-bookworm` + ionCube Loader + WHMCS extensions) | PHP-FPM, WHMCS from `docker/whmcs/`, the repo at `modules/addons/uptimeify` (read only) |
| `web` | `nginx:1.30.4-alpine` | `http://localhost:8088`, bound to 127.0.0.1 only |
| `mailpit` | `axllent/mailpit:v1.31.1` | catches all WHMCS mail, UI at `http://localhost:8025` |

## Prerequisites

1. **WHMCS development licence.** Open a ticket with WHMCS Customer Service (members area) asking for a development
   licence. WHMCS docs (Set Up a Test Environment, checked 2026-09-14): one free development licence per active
   licence bought directly from WHMCS, up to three for Business licences; licences bought through a reseller need a
   paid development licence. Without any licence, ask WHMCS for a trial (the "30-day trial" is from the plan and was
   not verified). The dev licence requires the installation to be private (error or login prompt for outsiders): the
   compose file publishes ports on 127.0.0.1 only.
2. **WHMCS release archive** (WHMCS 9.0, from the members area). Unpack it so that `docker/whmcs/index.php`,
   `docker/whmcs/admin/` and `docker/whmcs/crons/` exist. `docker/whmcs/` is gitignored.
   Then `mkdir -p docker/whmcs/modules/addons/uptimeify`: the `web` service mounts the addon read only inside the
   read-only WHMCS mount, so Docker cannot create that mountpoint there. The `php` service (read-write mount,
   started first) would create it, but creating it up front avoids a failed `web` start.
3. **Licence key file.** `cp .env.sandbox.example .env.sandbox` and put the key in (`.env.sandbox` is gitignored,
   never commit it). WHMCS itself reads the key from `configuration.php` (`$license`), which the installer writes in
   "First run" step 3, exactly as on any WHMCS installation; the sandbox deliberately keeps it that way. The env file
   only keeps the key next to the sandbox for the installer and a reinstall; nothing reads it at runtime. `docker compose ... config` prints the values from `.env.sandbox`: do not
   paste that output anywhere once the file exists.
4. **Uptimeify API for testing:** a reachable Uptimeify API instance for testing (e.g. an Uptimeify staging/sandbox
   environment; never production). An instance on the host at `http://localhost:3001` is reachable from the container
   as `http://host.docker.internal:3001`. Create an organization-wide `wsm_` token there. Never use a production token
   or `https://uptimeify.io` here.

## Licence and IP

WHMCS validates the licence against the installation (IP, domain, directory). Compose normally assigns new container
IPs after `make sandbox-down`; this compose file pins the network to `172.30.88.0/24`, the `php` service to
`172.30.88.10` and the `web` service to `172.30.88.11`, so both stay stable. On web requests WHMCS sees the nginx
container as `SERVER_ADDR` (`fastcgi_params`), i.e. `172.30.88.11`; the cron (`make sandbox-cron`) runs inside `php`
with `172.30.88.10`. Which of these (or the host's public IP) the licence check actually uses is unverified, and so
is how a dev licence reacts to a private IP: record it on the first run. If the subnet collides with a local network,
change the three values and have the dev licence reissued. If WHMCS reports an invalid licence after a restart, reissue
the licence (members area or support ticket) and reload the admin page.

## First run

1. `make sandbox-up` (builds the PHP image on the first run, then starts db, php, web, mailpit).
2. On Linux hosts make the WHMCS tree writable for PHP-FPM (`www-data`, uid 33):
   `sudo chown -R 33:33 docker/whmcs`. Docker Desktop on macOS does not need this.
3. Open `http://localhost:8088/install/install.php` and run the installer:
   licence key from `.env.sandbox`; database host `db`, database `whmcs`, user `whmcs`, password `whmcs`;
   create the admin user.
4. Delete the installer, as WHMCS demands: `rm -rf docker/whmcs/install`.
5. Admin area: `http://localhost:8088/admin/`.
6. **Configuration → System Settings → General Settings → Mail:** SMTP, host `mailpit`, port `1025`, no
   authentication, no SSL. Mails show up at `http://localhost:8025`.
7. **Configuration → System Settings → Addon Modules → Uptimeify → Activate**, then **Configure → Access Control**:
   tick your admin role, Save.
8. **Cron.** The stack runs no cron daemon. WHMCS expects `crons/cron.php` every 5 minutes; simulate each run with
   `make sandbox-cron` (runs `php -q crons/cron.php all --force` as `www-data`, so `templates_c` stays writable).
   The "cron has not run" warning under System Settings → Automation Settings is expected.
9. Continue with [`smoke.md`](smoke.md).

## Everyday commands

| Command | What it does |
| --- | --- |
| `make sandbox-up` | build and start the stack |
| `make sandbox-down` | stop it (database stays in `docker/data/db/`) |
| `make sandbox-cron` | one WHMCS cron run (AfterCronJob, DailyCronJob, the addon's queue worker and reconcile) |
| `make sandbox-logs` | follow php and nginx logs |
| `docker compose -f docker/docker-compose.sandbox.yml exec db mariadb -uwhmcs -pwhmcs whmcs` | interactive SQL shell |

Start over: `make sandbox-down && rm -rf docker/data/db` (then reinstall WHMCS; `configuration.php` in `docker/whmcs/`
keeps the licence key, or the installer asks for it again). The addon needs a fresh module install whenever its 0.1.0
schema changed before the first release (smoke test, "Fresh install required").

## Notes

- nginx denies `/vendor/`, `/templates_c/`, `/attachments/`, `/downloads/`, `/crons/` and dotfiles (WHMCS protects
  them with `.htaccess`, which nginx ignores). The addon mount is the whole repository, so everything under
  `/modules/addons/uptimeify/` except `logo.png` is denied.
- Module code changes are live (bind mount; the image does not enable opcache). After changing `hooks.php`, re-save
  the addon configuration so WHMCS reloads the hook list (the README claims this; smoke step 39 checks it).
- The container environment variables `WHMCS_DB_HOST` and `UPTIMEIFY_SANDBOX_URL` are notes for the installer and the
  module settings; neither WHMCS nor the addon reads them.
