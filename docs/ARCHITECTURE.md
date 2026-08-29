# SG_NOC Architecture

> Design patterns and system-level decisions. For "where is X" see `docs/CODEBASE_MAP.md` and `docs/MODULES.md`. For "how does data move" see `docs/DATA_FLOW.md`. For routes see `docs/API_MAP.md`.

## Scheduler-as-worker

Production runs no dedicated `queue:work` process — cPanel/shared-hosting constraints preclude a long-running worker. `routes/console.php` registers ~30 scheduled tasks (1/2/5-min, hourly, daily, weekly) via `Schedule::command()`/`Schedule::call()`, kept alive by `deployment/supervisor/switch-poll.conf` running `php artisan schedule:run` continuously. Several scheduled tasks call a Job's `->handle()` directly instead of dispatching to the DB queue. **Anything that must run reliably should be a scheduled command, not a queued job.**

## Permission model

Custom-built, not a package (no spatie/laravel-permission). `RolePermission`/`UserPermission` models back `permission:view-x`/`manage-x`/`export-x` route gates, enforced by `EnsurePermission`/`EnsureRole` middleware (aliased `permission`/`role` in `bootstrap/app.php`). Admin routes are gated per-action, not just per-role — check the specific permission string, not just whether the user has admin access.

## Host-based isolation

`bootstrap/app.php` appends globally to the `web` middleware group, running *before* auth: `EnforceMarketingHostIsolation` (em.samirgroup.net), `EnforceVcardHostIsolation` (vcard.samirgroup.net), `EnforceHrPortalHostIsolation` (hr.samirgroup.net). Each 404s cross-domain requests rather than falling through to the wrong login screen. `RequireTwoFactor` and `SecurityHeaders` are also global (HR portal is the one exception — SSO-only, no 2FA). `trustProxies(at: '*')` — production sits behind HTTPS-terminating reverse proxy.

## CSRF exceptions for machine-to-machine endpoints

A fixed list of routes (Graylog webhook, `branch-config/*`, `branch-agents/*`, `voice-mesh/*`, wallpaper checkin, SNS email events, HR API) is CSRF-excepted in `bootstrap/app.php` and instead authenticates via shared-secret header or the `hr.api_key`/`internal.ip` middleware. Full list and mechanisms: `docs/API_MAP.md`. **Never re-add CSRF to these routes to fix a 419** — fix the shared-secret check instead.

## Config-not-env for integration credentials

Several third-party credentials (Sophos, GDMS, WhatsApp, marketing SES) live in a DB-backed `Setting` model, read at call time — editable from the admin UI without a redeploy. `.env` still holds infra-level config (DB, base mail transport, app key).

## Alert/event pipeline

`NocEvent` is the central incident model. Host ping, SNMP thresholds, syslog rule matches, tunnel probes, and voice mesh all raise/resolve `NocEvent` rows. `NotificationRule::resolveRecipients()` (role / multi-user pivot / legacy single recipient) feeds `NotificationService::notifyViaRules`, which fans out over email/in-app/WhatsApp — falling back to "all admins" when no rule matches an event type. See `docs/DATA_FLOW.md` §3.

Exception: **tunnel outages bypass this pipeline.** Events start late (`ALERT_AFTER_FAILURES`) and the flap window merges separate incidents — both correct for paging, wrong for SLA billing. Outages instead write directly to a separate, never-pruned `TunnelOutage` log used for ISP SLA credit claims.

## Time-series telemetry

Raw metrics land in `sensor_metrics`, rolled up hourly by `RollupMetricsJob` into `metric_rollups`, retention-pruned daily by `PruneVqData`. **Query the rollup tables for historical/dashboard data — the raw table is large and not indexed for range scans.**

## Workflow engine as the HR write-path

HR portal forms (onboarding, offboarding, employee-update) never mutate `Employee` or Azure directly. They create a `WorkflowRequest`, which a `WorkflowEngine` either runs through a generic post-approval job or hands off to a type-specific side-channel (Jobs under `app/Jobs/{AD,Azure,Hr,Ucm,Itsm,Offboarding}/`), with retry support on individual steps. Onboarding is two-stage: Azure account/licenses/groups are provisioned right after IT approval; extension/manager-groups/tickets wait for a separate manager form.

**The generic post-approval job (`ExecuteWorkflowJob`) only automates 3 of the ~12 request types found in the code** (`create_user`, `delete_user`, `profile_update_phone`) plus a log-only case for `license_purchase` — every other type is approval-tracking scaffolding unless a type-specific service intervenes directly (as onboarding and offboarding do). Two request types look automated from their own code comments/API responses but verifiably are not: `employee_update`'s approved diff is never written back to `Employee`/Azure, and `POST /api/hr/group-assignment` returns "assigned successfully" without ever calling Graph to assign anything. Offboarding runs two independent, unreconciled state machines (`WorkflowRequest.status` vs `OffboardingWorkflow.status`) because its manager form bypasses the engine's execution path entirely. Full per-type breakdown, including which have no automated handler at all: `docs/DATA_FLOW.md` §4.

Known live defects in this engine (as of last audit): graph builder dead (fillable issue), a `(string) value` fatal path, `hr == it_manager` approver bug, and the offboarding/email queues have no worker consuming them. Verify before relying on any of these paths.

## Branch topology model

No local strongSwan on the NOC VM since the NOC2 migration — the Azure VPN gateway terminates branch tunnels centrally. The app therefore *watches* (`TunnelWatchdog` + `BranchTunnel`/`TunnelProbe`/`TunnelHealthCheck`/`TunnelOutage`) rather than controls tunnels; the old `VpnHubController`/`VpnControlService` control plane was removed. A per-branch Go agent (`branch-agent/`) handles local SNMP/syslog collection and DDNS instead of the NOC reaching into branch LANs directly — its logs stay on the branch VM and are queried on demand, never bulk-uploaded.

## Firmware distribution split from the app

Phone firmware bytes are served by **nginx directly** on port 80 (`deployment/firmware/setup.sh`), never proxied through PHP-FPM — old phone firmware can't handle TLS or follow redirects, so this vhost must never sit behind an HTTPS redirect. The Laravel app only manages the firmware library and reads self-reported versions from the `SW` field phones send in their `/phonebook.xml` User-Agent header; that, not GDMS polling, is the source of truth for the firmware status board.

## Multi-tenancy / domain routing summary

One Laravel app, five host/path-isolated surfaces: NOC admin (`noc.samirgroup.net/admin/*`), employee portal (`/portal/*`), HR portal (`hr.samirgroup.net`), email marketing (`em.samirgroup.net`), vCard (`vcard.samirgroup.net`), plus a thin ticket-forwarding proxy (`it.samirgroup.net`). See `docs/MODULES.md` for what each surface covers and `docs/API_MAP.md` for their route groups.

## Legacy heritage: the phonebook is still live

The repo descends from a phone directory app, not the NOC platform it grew into (see root `CLAUDE.md`). `GET /phonebook.xml` and `/contacts*` are still production routes, still polled by physical desk phones for their speed-dial directory. `/phonebook.xml` is declared with `withoutMiddleware(['web'])` — no session, no CSRF, nothing but the route itself — because old phone firmware can't participate in a normal Laravel request. Treat this the same as the firmware-server rule below: don't wrap it in stack middleware that assumes a browser client. See `docs/DATA_FLOW.md` §6.

## Side-services that bypass Laravel entirely

Several vendored side-services talk to the app's MySQL database or filesystem directly rather than going through HTTP:
- **FreeRADIUS** (`deployment/freeradius/`) authenticates MAC-auth-bypass requests straight against MySQL (`authorize_reply_query`); the Laravel app never sits in the auth path. `RadiusVlanPolicyResolver` only *mirrors* that SQL for an admin preview panel and tests — it is not the runtime auth logic.
- **rsyslog** (`deployment/rsyslog/`) writes UDP/514 syslog directly into `syslog_messages`.
- **nginx** serves phone firmware bytes and the Node `telnet-proxy` bridges WebSocket↔Telnet/SSH — both described above/elsewhere but worth grouping: **when a request touches these paths, PHP-FPM is never in the loop**, so a Laravel-side fix cannot patch a problem in one of them.

## Certificate automation (ACME/DNS-01)

`RenewExpiringCertsCommand` (`dns:renew-expiring-certs`, scheduled) renews `SslCertificate` rows within 14 days of expiry via `Services\Dns\AcmeService` (DNS-01 challenge through `Services\Dns\GoDaddyService`), using the `afosto/yaac` package. This subsystem exists because the GoDaddy wildcard certificate's original private key was lost — it now backs both general subdomain certs and Sophos firewall certs. See `docs/DATA_FLOW.md` §12.
