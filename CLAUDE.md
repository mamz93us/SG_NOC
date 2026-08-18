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

**Middleware (`bootstrap/app.php`).** Aliases: `role`, `permission`, `2fa`, `hr.api_key`, `internal.ip`. The `web` group globally appends `RequireTwoFactor` and `SecurityHeaders`. CSRF is excepted for a short list of machine-to-machine endpoints (`two-factor-challenge`, `api/graylog/webhook`, `api/backup/upload-hook`, `api/branch-config/*`, `api/branch-agents/*`, `api/voice-mesh/*`, `api/wallpapers/checkin`, `api/sns/email-events`, `email/unsubscribe/*`) — read the list in `bootstrap/app.php` rather than trusting this sentence. Reverse-proxy headers are trusted (`trustProxies(at: '*')`) — production is behind HTTPS termination.

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
- **Voice mesh (synthetic calls)** — proves a *call* completes, where the tunnel watchdog only proves a *packet* crosses. The calling half is a Python/pjsua service under systemd on the NOC host (`deployment/voice-mesh/`); the app half is `Services\Voice\VoiceMeshMonitor` (ingest, roll-up, alerting), `Api\VoiceMeshController` (`/api/voice-mesh/config` and `/report`, gated by `internal.ip` **and** a shared secret because the config endpoint returns plaintext SIP passwords), `Admin\VoiceMeshController`, and the matrix at `/admin/network/voice-mesh`. Models: `VoiceMeshNode` (a branch, its UCM and credentials), `VoiceMeshPair` (current state per leg), `VoiceMeshResult`/`VoiceMeshRun` (history). Alerts roll **up to the node** first — a mesh of N nodes has N×(N−1) legs, so per-leg events would mean a dozen emails for one dead UCM. See [VOICE_MESH_SETUP.md](VOICE_MESH_SETUP.md).
- **Notification routing & WhatsApp** — one page, `/admin/notifications/rules`, governs who is paged for every event type (`NotificationService::notifyViaRules` is the single funnel; with no rule for an event it falls back to broadcasting to all admins). A rule targets **a role or several users** — `notification_rule_recipients` is the pivot, and `NotificationRule::resolveRecipients()` is the one place that resolves role / multi-user / the legacy single `recipient_user_id`. Channels are email, in-app and **WhatsApp** (Meta Cloud API, credentials in Settings not `.env`; `Services\Notifications\WhatsAppService` + `SendNotificationWhatsAppJob`, audited in `whatsapp_logs` at `/admin/notifications/whatsapp-log`). WhatsApp needs an **approved template** — Meta refuses free-form text outside a 24-hour reply window, so an unattended alert sent as plain text always fails with error 131047. See [WHATSAPP_SETUP.md](WHATSAPP_SETUP.md).
- **Branch tunnel watchdog** — `TunnelWatchdog` service + `tunnel-health:watch` command (every minute), `TunnelHealthController`, page at `/admin/network/tunnel-health`. Models: `BranchTunnel` (gateway firewall), `TunnelProbe` (one per carried subnet), `TunnelHealthCheck` (history). Replaced the strongSwan **VPN Hub**, which could start/stop tunnels the NOC no longer owns — see the gotcha below.

**Time-series telemetry pipeline.** Raw rows land in `sensor_metrics`; `RollupMetricsJob` aggregates to `metric_rollups` hourly; `PruneVqData` enforces retention daily. When querying historical data, hit the rollup tables — the raw table is huge.

**Webhook ingest.** External systems authenticate by shared-secret header rather than CSRF. The CSRF-excepted routes are: Graylog (`api/graylog/webhook`), branch configs (`api/branch-config/*`), and HR endpoints (gated by the `hr.api_key` middleware). Don't add CSRF back to those routes.

## Side services

The Laravel app does not run alone — these run alongside it in production:

- **`branch-agent/`** — single Go binary (`sg-branch-agent`) that runs on each branch VM: syslog collection into daily-rolling SQLite, SNMP polling + discovery, and a DDNS WAN-IP reporter, with a local web UI (browser setup wizard, no file editing). Enrolls to the NOC via a one-time code (`/api/branch-agents/*`), then heartbeats. The NOC searches its logs on demand (drop-in for `BranchLogClient`); logs are **not** uploaded. Installed with a one-liner that the NOC hosts (`/branch-agent/install.sh`). Supersedes the older `deployment/branch-vm/`. NOC side: `BranchAgent` model, `Admin\BranchAgentController` (Branch Agents page), `Services\BranchAgent\BranchDdnsService` (GoDaddy + VPN tunnel), `branch-agents:check-stale` scheduler. See [BRANCH_AGENT_SETUP.md](BRANCH_AGENT_SETUP.md).
- **`telnet-proxy/`** — Node.js WebSocket↔Telnet/SSH bridge (default port 8765, PM2 via `ecosystem.config.js`). Validates session tokens against the Laravel app over `INTERNAL_SECRET`.
- **`deployment/metrics/`** — VictoriaMetrics + Grafana (Docker Compose). Receives Prometheus `remote_write` from branch Telegraf collectors. Public link is set via `GRAFANA_URL` in `.env`.
- **`deployment/graylog/`** — Graylog Open + OpenSearch + MongoDB (Docker Compose). See [SYSLOG_GRAYLOG_SETUP.md](SYSLOG_GRAYLOG_SETUP.md).
- **`deployment/freeradius/`** — FreeRADIUS for MAC-auth (MAB) with VLAN policy from MySQL. See [RADIUS_SETUP.md](RADIUS_SETUP.md).
- **`deployment/rsyslog/`** — rsyslog receives UDP/514 and writes directly to MySQL `syslog_messages`. See [SYSLOG_SETUP.md](SYSLOG_SETUP.md).
- **`deployment/smtp-relay/`** — native Postfix smarthost so legacy Ricoh MP C3001/C3003 MFPs can scan-to-email: printers submit plain SMTP to the NOC internal IP on port 25, Postfix rewrites the sender to the SES-verified `scanner@samirgroup.com` and relays to Amazon SES over TLS/587. Reuses the app's existing AWS creds — the SES **SMTP** password is *derived* from `AWS_SECRET_ACCESS_KEY` (not the raw key) by `ses-smtp-password.sh`. `mynetworks` is the open-relay guard. Every relayed message is audited: Postfix `header_checks` log the Subject + attachment names, and the scheduled `smtp-relay:ingest-log` command tails `/var/log/mail.log` into `smtp_relay_messages`/`smtp_relay_attachments` for the **/admin/smtp-relay** page (`view-smtp-relay`) — who sent what, size, attachments, and whether SES accepted it. The app user must be in the `adm` group to read the maillog (setup.sh adds it). Per-attachment byte sizes are a planned Phase-B milter, not built yet. See [SMTP_RELAY_SETUP.md](SMTP_RELAY_SETUP.md).
- **`deployment/voice-mesh/`** — Python/pjsua prober for the voice mesh, under its own systemd timer on the NOC host. Pulls the branch list (with SIP credentials) from `/api/voice-mesh/config` and POSTs one combined report per sweep. The timer wakes every 5 minutes and the prober gates itself on the NOC-configured interval, so the interval is changeable from the admin UI. `pjsua` is **not** packaged for Ubuntu — `install.sh` builds it from pjproject. `selftest.py` checks everything but the calls themselves. See [VOICE_MESH_SETUP.md](VOICE_MESH_SETUP.md).
- **`deployment/branch-vm/`** — Ansible playbooks for branch VM provisioning.
- **`deployment/browser-portal/`** — nginx snippet template + Chromium/Neko supervisor.
- **`deployment/supervisor/`** — `switch-poll.conf` keeps `php artisan schedule:run` alive.
- **`deployment/sftp/`** — chrooted, SFTP-only inbox network devices push backups into (`setup-sftp.sh` + sshd `Match` snippet). The scheduled `sftp-backups:sweep` command streams each stable file to Azure Blob (the `azure_backups` disk) and deletes the local copy; `sftp-backups:prune` enforces Azure retention. Tracked in `sftp_backups`. See [deployment/sftp/README.md](deployment/sftp/README.md).

VPN: branch tunnels terminate on the **Azure VPN gateway**, not on the NOC VM — since the NOC2 migration there is no local strongSwan, so `swanctl --list-sas` is empty and there are no `10.x` routes in `ip route`. The app therefore *watches* tunnels rather than controlling them (see Branch tunnel watchdog above); the old `VpnHubController` / `VpnControlService` / `sg-vpn-control.sh` control plane has been removed. The `VpnTunnel` model survives only as a passive record that `MonitoredHost.vpn_id`, `BranchAgent.vpn_id`, `VpnLog` and `TopologyService` still reference. Historical strongSwan notes: [INFRA_SETUP.md](INFRA_SETUP.md).

## Deploy workflow

Production deploys are **`git pull` on the VPS**, not direct edits over SSH. Workflow:

1. Edit locally.
2. Commit and push.
3. SSH in and `git pull` (then `composer install`, `php artisan migrate`, `npm run build` if needed).

SSH is for diagnostics and system-level configs (rsyslog, nginx, supervisor, strongSwan), not for editing application code. The host is `noc.samirgroup.net`, the app lives at `/home/azureuser/phonebook2`, the DB is `phonebook2`.

## Operational gotchas

- **Root-level `check_*.php`, `test_*.php`, `fix_cai_*.php`, `clean_cai.php`, `clear_jobs.php`, `list_tunnels_v2.php`** are **ad-hoc operational scripts**, not part of the app build or CI. Don't refactor them as if they were app code, and don't add new features by adding more of them — extend the relevant controller/command/job instead.
- **strongSwan tunnels: never add a `0.0.0.0/0` child SA to an existing IKE conn.** Sophos widens narrow children during rekey; doing this to an existing conn will hijack all VPS outbound traffic. New wide selectors need their own IKE conn. (Applies to branch-side Sophos config; the NOC itself no longer runs strongSwan.)
- **A reachable branch firewall does NOT mean the tunnel is carrying every subnet.** After the NOC2 migration, JED's tunnel was rebuilt covering only `10.1.0.0/24`: the firewall at `10.1.0.1` answered ICMP for a month while `10.1.8.0/24` — and the UCM on it — was completely unreachable, and every dashboard showed JED green. This is why the watchdog probes one target *per carried subnet* and has a **degraded** state. Never "fix" a monitor by reducing it to a single gateway ping, and when a device is unreachable from the NOC but fine on the branch LAN, check the tunnel's traffic selector before suspecting the device.
- **Every UCM currently refuses SIP from the NOC.** The 2026-08-11 watchdog sweep found all seven silent on UDP/5060 from `172.16.8.11` while answering ICMP unreachable on every other port — SIP is bound and declining us, which is why `TunnelProbeSeeder` ships the SIP probe paused. Until each UCM's ACL permits the NOC, the voice mesh registers nowhere and its whole board is red. Don't read that as an outage.
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
- Operational docs at the repo root: [INFRA_SETUP.md](INFRA_SETUP.md), [WHATSAPP_SETUP.md](WHATSAPP_SETUP.md), [RADIUS_SETUP.md](RADIUS_SETUP.md), [SYSLOG_SETUP.md](SYSLOG_SETUP.md), [SYSLOG_GRAYLOG_SETUP.md](SYSLOG_GRAYLOG_SETUP.md). The `README.md` in this repo is the stock Laravel template — ignore it.
