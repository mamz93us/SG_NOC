# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project overview

SG_NOC is the Samir Group NOC / IT-management platform built on **Laravel 12** (PHP 8.2+). The repo descends from a phonebook app, but the live scope is much larger: ITAM (devices, accessories, warranties), network monitoring (SNMP, syslog, SLA, IPAM, DHCP), telephony (UCM extensions, trunks, active calls), printer management (CUPS), identity sync (Azure/Entra, Intune, AD, RADIUS), Sophos firewall integration, browser-portal VPN egress (per-office), and a workflow automation engine. Production lives at `noc.samirgroup.net` against MySQL DB `phonebook2`. The repo also vendors several side services (Node telnet/SSH proxy, VictoriaMetrics+Grafana, Graylog, FreeRADIUS, rsyslog) under `deployment/` and `telnet-proxy/`.

## Common commands

Local development (run from repo root):

```sh
composer setup                 # one-shot bootstrap: install, .env, key, migrate, npm install, npm run build
composer dev                   # concurrently: php artisan serve + queue:listen + pail + vite
php artisan serve              # PHP server only
npm run dev                    # Vite dev server
npm run build                  # Vite production build → public/build/
```

Database:

```sh
php artisan migrate
php artisan migrate:fresh --seed
```

Tests (Pest 3 over PHPUnit; SQLite `:memory:` per `phpunit.xml`):

```sh
composer test                                       # config:clear + artisan test
./vendor/bin/pest                                   # all tests
./vendor/bin/pest tests/Feature/Api/HrApiTest.php   # single file
./vendor/bin/pest --filter=HrApi                    # single test by name
```

Lint:

```sh
./vendor/bin/pint              # Laravel Pint formatter
```

Operational (mostly used in production, but valid locally):

```sh
php artisan schedule:run       # production runs this every minute via supervisor
php artisan queue:work         # dev-only — production does NOT run a dedicated worker
```

## High-level architecture

The pieces below need multiple files read together to make sense — start here before diving in.

**Routing model.** Only `routes/web.php` and `routes/console.php` are wired in `bootstrap/app.php`. There is no `routes/api.php`. All HTTP API endpoints (`/api/graylog/webhook`, `/api/branch-config/*`, HR webhooks, etc.) live inside `web.php`. Health probe is at `/up`.

**Middleware (`bootstrap/app.php`).** Aliases: `role`, `permission`, `2fa`, `hr.api_key`, `internal.ip`. The `web` group globally appends `RequireTwoFactor` and `SecurityHeaders`. CSRF is excepted for exactly three endpoints: `two-factor-challenge`, `api/graylog/webhook`, `api/branch-config/*`. Reverse-proxy headers are trusted (`trustProxies(at: '*')`) — production is behind HTTPS termination.

**Auth model.** Microsoft SSO via `laravel/socialite` + `socialiteproviders/microsoft`. The `/portal/*` route group is employee-facing and isolated: guests there are redirected to `route('portal.login')`, everyone else falls back to `route('login')`. Admin pages enforce **fine-grained permissions** (`permission:view-*`, `manage-*`, `export-*`) — not just roles. When adding admin endpoints, gate them with the same scheme.

**Scheduler-as-worker.** Production does not run `queue:work`. Instead, `routes/console.php` registers ~30 scheduled tasks at 1-, 5-, 60-min, daily, and weekly intervals, and `deployment/supervisor/` keeps `php artisan schedule:run` alive. Anything that *must* execute reliably should be a scheduled command, not a queued job. Existing jobs (e.g. `CollectSnmpMetricsJob`, `MatchSyslogAlertsJob`, identity sync jobs) are dispatched from inside scheduled commands rather than relied on through a long-running worker.

**Subsystem map** — where to look first when touching a feature:

- **Browser-portal (per-office VPN egress)** — `app/Http/Controllers/Admin/BrowserPortal/`, `app/Services/BrowserPortal/` (`SessionManager`, `DockerClient`, `NginxSnippetWriter`). Spins up Neko containers and writes per-session nginx snippets.
- **SNMP monitoring** — `CollectSnmpMetricsJob`, `DiscoverSnmpDeviceJob`, `app/Services/Snmp/`. Models: `MonitoredHost`, `SensorMetric`, `MetricRollup`.
- **Syslog/Graylog** — `ParseSyslogPayloadsJob` → `TagSyslogSourcesJob` → `MatchSyslogAlertsJob` → `NocEvent`. Graylog ingests via webhook to `/api/graylog/webhook`.
- **Identity sync** — `SyncIdentity` / `SyncAzureDevices` / `SyncRadiusMacs` commands plus `IdentitySyncService` and `GraphService`.
- **Sophos** — `SyncSophosCommand`, `SophosApiService`, `SophosFirewall*` models.
- **Workflow engine** — `Workflow`, `WorkflowTemplate`, `WorkflowTrigger`, `WorkflowAction` models + matching controllers; supports retries and templated actions.
- **Telephony** — UCM SOAP integration via `SyncUcmExtensionsJob`, `SyncUcmActiveCallsJob`. Models in `app/Models/` (`Extension`, `Trunk`, `UcmServer`, `Phone`, `ActiveCall`).
- **CUPS printers** — `CupsRefreshStatus` command, `CupsPrinter` / `CupsPrintJob` models.

**Time-series telemetry pipeline.** Raw rows land in `sensor_metrics`; `RollupMetricsJob` aggregates to `metric_rollups` hourly; `PruneVqData` enforces retention daily. When querying historical data, hit the rollup tables — the raw table is huge.

**Webhook ingest.** External systems authenticate by shared-secret header rather than CSRF. The CSRF-excepted routes are: Graylog (`api/graylog/webhook`), branch configs (`api/branch-config/*`), and HR endpoints (gated by the `hr.api_key` middleware). Don't add CSRF back to those routes.

## Side services

The Laravel app does not run alone — these run alongside it in production:

- **`telnet-proxy/`** — Node.js WebSocket↔Telnet/SSH bridge (default port 8765, PM2 via `ecosystem.config.js`). Validates session tokens against the Laravel app over `INTERNAL_SECRET`.
- **`deployment/metrics/`** — VictoriaMetrics + Grafana (Docker Compose). Receives Prometheus `remote_write` from branch Telegraf collectors. Public link is set via `GRAFANA_URL` in `.env`.
- **`deployment/graylog/`** — Graylog Open + OpenSearch + MongoDB (Docker Compose). See [SYSLOG_GRAYLOG_SETUP.md](SYSLOG_GRAYLOG_SETUP.md).
- **`deployment/freeradius/`** — FreeRADIUS for MAC-auth (MAB) with VLAN policy from MySQL. See [RADIUS_SETUP.md](RADIUS_SETUP.md).
- **`deployment/rsyslog/`** — rsyslog receives UDP/514 and writes directly to MySQL `syslog_messages`. See [SYSLOG_SETUP.md](SYSLOG_SETUP.md).
- **`deployment/branch-vm/`** — Ansible playbooks for branch VM provisioning.
- **`deployment/browser-portal/`** — nginx snippet template + Chromium/Neko supervisor.
- **`deployment/supervisor/`** — `switch-poll.conf` keeps `php artisan schedule:run` alive.
- **`deployment/sftp/`** — chrooted, SFTP-only inbox network devices push backups into (`setup-sftp.sh` + sshd `Match` snippet). The scheduled `sftp-backups:sweep` command streams each stable file to Azure Blob (the `azure_backups` disk) and deletes the local copy; `sftp-backups:prune` enforces Azure retention. Tracked in `sftp_backups`. See [deployment/sftp/README.md](deployment/sftp/README.md).

VPN: strongSwan IPsec configs (`JED.conf`, `RYD.conf`, etc.) plus the `sg-vpn-control.sh` wrapper. See [INFRA_SETUP.md](INFRA_SETUP.md).

## Deploy workflow

Production deploys are **`git pull` on the VPS**, not direct edits over SSH. Workflow:

1. Edit locally.
2. Commit and push.
3. SSH in and `git pull` (then `composer install`, `php artisan migrate`, `npm run build` if needed).

SSH is for diagnostics and system-level configs (rsyslog, nginx, supervisor, strongSwan), not for editing application code. The host is `noc.samirgroup.net`, the app lives at `/home/azureuser/phonebook2`, the DB is `phonebook2`.

## Operational gotchas

- **Root-level `check_*.php`, `test_*.php`, `fix_cai_*.php`, `clean_cai.php`, `clear_jobs.php`, `list_tunnels_v2.php`** are **ad-hoc operational scripts**, not part of the app build or CI. Don't refactor them as if they were app code, and don't add new features by adding more of them — extend the relevant controller/command/job instead.
- **strongSwan tunnels: never add a `0.0.0.0/0` child SA to an existing IKE conn.** Sophos widens narrow children during rekey; doing this to an existing conn will hijack all VPS outbound traffic. New wide selectors need their own IKE conn.
- **CUPS over TLS** — CUPS reads cert files keyed off the **machine hostname**, not `ServerName`. The Let's Encrypt cert is bridged in by symlink. Don't rename the host or remove those symlinks without re-pointing CUPS.
- **Database is the queue, the cache, and the session store.** Truncating `cache`, `sessions`, or `jobs` mid-flight wipes live state — don't do it casually in production.
- **CSRF-excepted routes** depend on a shared-secret header check inside the controller (or `hr.api_key` middleware). If you change the request shape, keep the auth check in place; do not "re-enable CSRF" on those paths to fix a 419.
- **No `routes/api.php`.** Adding API endpoints means adding them to `routes/web.php` (typically under an `/api/...` prefix) and registering any new middleware aliases in `bootstrap/app.php`.

## Testing

Pest 3 with the Laravel plugin; SQLite `:memory:` configured in `phpunit.xml`. Existing tests are mostly Laravel Breeze auth stubs in `tests/Feature/Auth/`; the only meaningful custom test is `tests/Feature/Api/HrApiTest.php`. **Coverage is sparse** — most subsystems (SNMP, syslog, identity sync, browser portal, workflows) have no tests. Don't assume regressions will be caught by the existing suite; verify behavior manually against a real environment when changing those areas.

## Where to find things

- Routes: [routes/web.php](routes/web.php) (HTTP, including all API endpoints), [routes/console.php](routes/console.php) (scheduler).
- Bootstrap / middleware: [bootstrap/app.php](bootstrap/app.php).
- Controllers grouped by subsystem under `app/Http/Controllers/Admin/{BrowserPortal,Identity,Network,Phone,Printers,...}/`.
- Custom config: `config/{branches,radius,admin_tools,acme,telnet,vpn}.php`.
- Custom commands: `app/Console/Commands/Sync*.php`, `Switch*.php`, `Cups*.php`, etc.
- Operational docs at the repo root: [INFRA_SETUP.md](INFRA_SETUP.md), [RADIUS_SETUP.md](RADIUS_SETUP.md), [SYSLOG_SETUP.md](SYSLOG_SETUP.md), [SYSLOG_GRAYLOG_SETUP.md](SYSLOG_GRAYLOG_SETUP.md). The `README.md` in this repo is the stock Laravel template — ignore it.
