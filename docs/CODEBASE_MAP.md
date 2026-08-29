# SG_NOC Codebase Map

> Where things live. For architectural patterns see `docs/ARCHITECTURE.md`. For per-subsystem file inventory see `docs/MODULES.md`. For request/data flow see `docs/DATA_FLOW.md` and `docs/API_MAP.md`.

## Project structure

Laravel 12 / PHP 8.2+ monolith at the repo root, with several vendored side-services in sibling directories.

```
SG_NOC/
├── app/                    # Console, Events, Exceptions, Exports, Http, Jobs, Listeners, Mail,
│                            # Models (204), Notifications, Observers, Polling, Providers, Services (105),
│                            # Support, Traits, View — see docs/MODULES.md for the 50-module breakdown
├── bootstrap/              # app.php (middleware wiring), providers.php
├── config/                 # stock Laravel config + ~22 custom config files (acme, branches, branch_agents,
│                            # branch_health, download_center, email_marketing, hr_portal, phone_firmware,
│                            # printer_alerts, radius, server_status, sftp_backup, smtp_relay, teamtailor,
│                            # telnet, ticket_tracking, vcard, voice_mesh, vpn, worldcup, admin_tools, db_backup)
├── database/
│   ├── migrations/          # ~400+ migrations, timestamp-ordered, additive (no squashing)
│   ├── seeders/, factories/
│   └── mibs/                 # vendored FORTINET MIB files (large, rarely relevant)
├── routes/                 # web.php (all HTTP incl. API), console.php (scheduler), auth.php
├── resources/views/admin/  # one directory per admin subsystem, mirrors app/Http/Controllers/Admin
├── tests/                  # Pest 3, Feature/ + Unit/, sparse coverage — see root CLAUDE.md
├── public/                 # index.php, images (flags, worldcup, brand), .htaccess
├── branch-agent/           # Go binary source (sg-branch-agent) — separate Go module
├── telnet-proxy/           # Node.js WebSocket↔Telnet/SSH bridge (server.js, PM2 ecosystem)
├── noc-agw/                # Python FastAPI reverse-proxy gateway — separate app, own tests/requirements
├── deployment/              # 15 subdirectories, one per side-service or ops concern, + rebuild-side-services.sh
│   ├── branch-agent/ branch-vm/ browser-portal/ firmware/ freeradius/ hr-portal/
│   ├── rsyslog/ sftp/ sftpgo/ signature/ smtp-relay/ supervisor/ vcard/ voice-mesh/
│   └── graylog/ metrics/     # Docker Compose stacks (Graylog+OpenSearch+Mongo; VictoriaMetrics+Grafana)
├── NOC/                    # an Obsidian vault (notes), not application code
├── docs/                    # this documentation set + a few feature-specific docs
├── *.md                    # 15 root-level operational setup docs: BRANCH_AGENT_SETUP, GDMS_PHONE_MANAGEMENT,
│                            # INFRA_SETUP, IT_TICKET_PORTAL, PHONE_FIRMWARE_SERVER, RADIUS_SETUP,
│                            # SERVER_REQUIREMENTS, SIGNATURE_SYSTEM, SMTP_RELAY_SETUP, SYSLOG_(GRAYLOG_)SETUP,
│                            # VOICE_MESH_SETUP, WHATSAPP_SETUP + CLAUDE.md (authoritative) + README.md (stock, ignore)
└── check_*.php, fix_*.php, debug_*.php, clean_cai.php, clear_jobs.php, list_tunnels_v2.php
                             # ad-hoc one-off ops scripts, NOT part of the app build (see root CLAUDE.md)
```

Full subsystem module list (ITAM, SNMP, syslog, telephony, printers, identity, Sophos, browser portal, workflow engine, notifications, backups, etc.) with file paths: `docs/MODULES.md`.

## Entry points

- **HTTP**: `public/index.php` → `bootstrap/app.php`. All HTTP routes, including every `/api/*` endpoint, are declared in `routes/web.php` — **there is no `routes/api.php`**. `routes/auth.php` holds Breeze auth routes. Health probe at `/up`. Full route map: `docs/API_MAP.md`.
- **Legacy phonebook entry point**: `GET /phonebook.xml` (`PhonebookController`, `withoutMiddleware(['web'])` — no session/CSRF at all) plus `/contacts*`. This repo started as a phone directory app before growing into the NOC (see project overview in root `CLAUDE.md`); these routes are still live and still polled by physical desk phones.
- **Console/scheduler**: `routes/console.php` — ~30 scheduled tasks at 1/2/5-min, hourly, daily, weekly cadences; the real "worker" in production (no dedicated `queue:work`). See `docs/ARCHITECTURE.md` §Scheduler-as-worker.
- **Artisan commands**: `app/Console/Commands/*.php` (75 commands) — sync commands (`Sync*`), switch/SNMP tooling (`Switch*`), CUPS (`Cups*`), pruning/backfill utilities, `EmailMarketing/` subfolder.
- **Side-service entry points** (each independently deployed, not started by Laravel):
  - `branch-agent/cmd/sg-branch-agent/main.go` — Go binary, runs on each branch VM.
  - `telnet-proxy/server.js` — Node WebSocket↔Telnet/SSH bridge (PM2, port 8765).
  - `noc-agw/gateway/main.py` — FastAPI reverse proxy for `arcmate.samirgroup.net`.
  - `deployment/voice-mesh/voice_mesh/` — Python/pjsua synthetic-call prober, systemd timer.
  - `deployment/branch-vm/` — legacy PHP ingester + Ansible playbooks (superseded by `branch-agent/`).
  - `deployment/smtp-relay/` — Postfix smarthost config (not app code).

## Important dependencies

From `composer.json` (`php ^8.2`, `laravel/framework ^12.0`):
- `laravel/socialite` + `socialiteproviders/microsoft` — Microsoft SSO, the sole admin/portal auth path.
- Permissions are **custom-built** (`RolePermission`/`UserPermission` models + `EnsurePermission`/`EnsureRole` middleware) — not spatie/laravel-permission or similar.
- `aws/aws-sdk-php` + `aws/aws-php-sns-message-validator` — SES mail, SNS bounce/complaint webhook verification.
- `league/flysystem-azure-blob-storage` + `microsoft/azure-storage-blob` — the multiple `azure_*` filesystem disks (offboarding, avepoint, certificates, teamtailor-resumes, backups).
- `maatwebsite/excel` — exports across many admin lists.
- `chillerlan/php-qrcode` — employee card / print-setup QR codes.
- `pragmarx/google2fa` — the mandatory 2FA flow (`RequireTwoFactor` middleware).
- `afosto/yaac` — ACME/DNS-01 client backing internal cert issuance (`RenewExpiringCertsCommand`, Sophos firewall certs).
- `workerman/workerman` — present but scheduler-as-worker architecture suggests limited/legacy use; confirm actual call sites before relying on it.
- Dev/test: `pestphp/pest ^3.8` + `pestphp/pest-plugin-laravel`, `laravel/pail`, `laravel/pint`.

From `package.json`: Vite 7 + `laravel-vite-plugin`, Tailwind 3 (`@tailwindcss/forms`), Alpine.js — Blade+Alpine+Tailwind, no SPA framework. `docx` is a devDependency (generated document exports, e.g. `SG_NOC_System_Documentation.docx` at repo root).

Side-service dependencies: `telnet-proxy/package.json` → `ws` + `ssh2` only. `branch-agent/go.mod` is a standalone Go module. `noc-agw/requirements.txt` is a separate Python/FastAPI stack.

## Known repo quirks

- Repomix's security scanner has flagged `tests/Unit/BranchHealth/NocDashboardContractTest.php` as suspicious during packing — worth a manual look if secrets may have leaked into a test fixture.
- The repo root carries ad-hoc `check_*.php`/`fix_*.php`/`debug_*.php` operational scripts (see structure above) — never mistake these for part of the application build.
