# SG_NOC Route / API Map

> All HTTP routes — including every machine-to-machine API endpoint — live in `routes/web.php` (~2,700 lines). There is no `routes/api.php`. `routes/auth.php` (Breeze) is required at the bottom. `routes/console.php` is the scheduler, not HTTP — see `docs/DATA_FLOW.md`.

## 1. Route groups overview

| Group | Path pattern | Middleware |
|---|---|---|
| vcard subdomain | `Route::domain(VCard::domain())->name('vcard.')` | `EnforceVcardHostIsolation`; login/logout guest; `/` (mine) `auth`; 2FA skipped on this host |
| Public card routes | `/card/{token}`, `/card/{token}/vcard` | none (public, token-secured); `/wallet` needs `auth` |
| **Public phonebook/directory** | `/phonebook.xml`, `/contacts`, `/contacts/print`, `/contacts/print-compact` | none — `/phonebook.xml` explicitly `withoutMiddleware(['web'])` (no session/CSRF at all — polled by physical desk phones); this is the app's original purpose, predating the NOC scope (see `docs/CODEBASE_MAP.md`) |
| Public documentation | `/documentation`, `/documentation/{filename}` | none — only docs an admin marked public (`DocumentationController@publicIndex`/`publicShow`) |
| Public branch-agent installer | `/branch-agent/install.sh`, `/branch-agent/sg-branch-agent`, `/branch-agent/sg-branch-agent.sha256` | none — read-only installer/binary/checksum (`BranchAgentDownloadController`), `throttle:60,1`; lets the one-line install command work on a bare VM |
| Microsoft SSO | `/auth/microsoft`, `/auth/microsoft/callback` | none (guest entry to Socialite flow) |
| Browser Portal | `Route::prefix('portal')->name('portal.')` | login/logout guest; hub/profile/assets `auth`+`throttle:60,1`; `/browser*` also `permission:view-browser-portal` |
| HR portal subdomain | `Route::domain(HrPortal::domain())->name('portal.hr.')` | `EnforceHrPortalHostIsolation`; login guest; workspace `permission:manage-hr-portal`; onboarding/offboarding/employee-update each gated by their own `submit-hr-*` permission; 2FA skipped |
| Marketing subdomain (guest) | `Route::domain(Marketing::domain())` no prefix | login/logout/no-access guest/auth-only; public contest forms token-gated |
| Marketing subdomain (app) | same domain, `name('portal.marketing.')` | `auth`+`permission:view-email-marketing`+`throttle:120,1` — ~40 CRUD routes (lists, subscribers/import, tags, segments, templates, campaigns incl. send/schedule/pause/duplicate/archive/test-send/recall/analytics, courses split `view-courses`/`manage-courses`, World Cup contest builder) |
| IT Ticket Portal | `Route::domain(config('ticket_tracking.host'))` `/`, plus `/go` | none — public; `/go` logs click-through then forwards |
| NOC public root | `/` | none (welcome view) |
| Public email marketing links | `/email/unsubscribe/{token}`, `/opt-in/{token}`, `/template-preview/{template}` | none — signed/token URLs |
| Public certificates | `/certificates/{token}`, `/{token}/file` | none — 64-char token regex |
| Auth (Breeze) | register/login/forgot-password/reset-password/2fa-challenge/verify-email/confirm-password/logout | `guest`/`auth` per convention; login+2FA throttled |
| Profile | `/profile`, `/admin/profile/password` | `auth` |
| Admin app | `Route::middleware(['auth'])->prefix('admin')->name('admin.')` | root `/` redirects portal-only users to `/portal`; everything below nests here |

### Inside `/admin/*` (permission-gated sub-areas — not enumerated route-by-route; each block is standard resource-style CRUD unless noted)

- Branches/contacts — `view/manage-branches`, `view/manage/export-contacts`
- Activity & phone logs — `view-activity-logs`, `view/sync-phone-logs`
- Extensions/trunks/phone firmware/phones — `view/manage-extensions`, `view-trunks`, `view/manage-phone-firmware`, `view/manage-phones`
- Settings — large `manage-settings` block (global config, mail, SES, integrations)
- Access Gateway (NOC-AGW) — `manage-agw-allowlist`, `manage-agw-settings`, `view-agw-audit`
- Server status — `view/manage-server-status`
- Users & permissions — `manage-users`, `manage-permissions`
- ITAM (general) — `view/manage-assets` (×2 blocks), `view/manage-backups`, `view/manage-downloads`, bulk import `manage-devices`+`throttle:60,1`
- Credentials vault — `view/manage-credentials`
- Printers/CUPS — `view/manage-printers` across CRUD + usage (`view-printer-usage`) + alerts (`manage-printer-alerts`) + print manager (`view/manage-print-manager`)
- Identity (`prefix('identity')`) — `view-identity`, `manage-identity`, `manage-identity-settings`, group-mappings sub-prefix
- Network (`prefix('network')`) — largest module: core `view-network`/`view-network-events`/`manage-network-settings`, plus own view/manage split per sub-prefix: `voice-mesh`, `tunnel-health`, `diagnostics`, `monitoring`, `workers`, `scanner`, `isp`/`isp-report`/`isp-providers`, `ip-reservations`, `sla`, `port-map`, `topology`, `dhcp`, `ipam`, `sophos`, `sophos-vpn` (`manage-noc`), `fortigate`, `sophos-central`, `access-points`, `dns`
- Telecom landlines (`prefix('telecom/landlines')`) — `view/manage-extensions`
- Notifications (`prefix('notifications')`) — rule management, no extra permission beyond admin `auth`
- Telnet (`prefix('telnet')`) — `view-noc`
- Email marketing admin (NOC side) — `manage-email-marketing` (+`manage-email-marketing-settings`) — SES creds, suppressions, senders
- Browser Portal admin — `manage-browser-portal`
- NOC dashboard/events (`prefix('noc')`) — `view/manage-noc`; incidents `view/manage-incidents`
- Syslog (`prefix('syslog')`) — `view/manage-syslog` (two `manage-syslog` blocks: alert rules + ingestion config)
- Branch logs (`prefix('logs/branches')`) — `view-syslog`
- Branch agents (`prefix('branch-agents')`) — `view/manage-branch-agents`
- Workflow engine — `view/manage/approve-workflows`; AvePoint `view/manage-avepoint`; offboarding `view/manage-offboarding`; employees `view/manage-employees`
- Email templates & internet access levels — under `manage-settings`
- ITAM detail (`prefix('itam')`) — core `view/manage-itam` + `reports`; `transfer` (`manage-itam`); `scrap` (`request-scrap`/`approve-scrap`); `suppliers`/`purchase-orders` (`view/manage-itam`); `licenses` (`view/manage-licenses`); `accessories` (`view/manage-accessories`); `azure` (`view/manage-itam`)
- RADIUS (`prefix('radius')`) — `manage-radius`
- Identity group mappings (`prefix('identity/group-mappings')`) — `manage-identity`
- Intune groups (`prefix('intune-groups')`) — `manage-printers` (reused)
- Wallpapers — `view/manage-wallpapers`
- Admin links (`prefix('admin-links')`) — `view/manage-admin-links`
- Voice quality (`prefix('voice-quality')`) — `view-voice-quality`
- Candidates/jobs (`prefix('candidates')`/`prefix('jobs')`) — `view-candidates`/`reject-candidates`
- Switch QoS (`prefix('switch-qos')`) — `view-voice-quality`
- Forms builder (`prefix('forms')`) — `manage-forms`
- Documentation (`prefix('documentation')`) — `view/manage-documentation`
- Signatures (`prefix('signatures')`) — `manage-signatures`
- `/admin/api-docs` — `ApiDocsController` — inherits enclosing permission

Separately outside `/admin` prefix but permission-gated: `/admin/ticket-stats*` (`manage-settings`), `/admin/access-stats*` (`view-activity-logs`), `/admin/smtp-relay*` (`view-smtp-relay`).

## 2. All `/api/*` endpoints (machine-to-machine — enumerated individually)

| Method | Path | Controller | Auth mechanism |
|---|---|---|---|
| GET | `api/ticket-stats` | `TicketStatsController@data` | session `auth`+`permission:manage-settings` |
| GET | `api/access-stats` | `AccessStatsController@data` | session `auth`+`permission:view-activity-logs` |
| POST | `api/sns/email-events` | `SnsEmailEventsController@handle` | AWS SNS signature verified in controller; CSRF-excepted |
| POST | `/api/internal/vq-report` | `VoiceQualityController@receive` | `internal.ip` only |
| POST | `/api/graylog/webhook` | `GraylogWebhookController` (invokable) | `X-Graylog-Secret` header; CSRF-excepted; `throttle:60,1` |
| POST | `/api/backup/upload-hook` | `BackupUploadWebhookController` (invokable) | `X-Backup-Secret` header (SFTPGo); CSRF-excepted; `throttle:120,1` |
| GET | `api/branch-config/snmp-devices` | `BranchConfigController@snmpDevices` | Bearer = `branch_log_collectors.api_token`; CSRF-excepted; `throttle:120,1` |
| POST | `api/branch-config/discovered-devices` | `BranchConfigController@postDiscovered` | same Bearer scheme; CSRF-excepted |
| GET | `api/voice-mesh/config` | `VoiceMeshController@config` | `internal.ip` **and** `X-Voice-Mesh-Secret` (returns plaintext SIP creds); CSRF-excepted; `throttle:120,1` |
| POST | `api/voice-mesh/report` | `VoiceMeshController@report` | same double-gate |
| POST | `api/branch-agents/enroll` | `BranchAgentController@enroll` | one-time enrollment code; CSRF-excepted; `throttle:120,1` |
| POST | `api/branch-agents/heartbeat` | `BranchAgentController@heartbeat` | Bearer = `branch_agents.token`; CSRF-excepted |
| POST | `api/branch-agents/ddns` | `BranchAgentController@ddns` | Bearer token; CSRF-excepted |
| GET | `api/branch-agents/config` | `BranchAgentController@config` | Bearer token; CSRF-excepted |
| GET | `/api/wallpapers/manifest` | `WallpaperDeploymentController@manifest` | none — public (Intune-consumed); `throttle:120,1` |
| GET | `/api/wallpapers/script.ps1` | `WallpaperDeploymentController@script` | none — public; `throttle:120,1` |
| POST | `/api/wallpapers/checkin` | `WallpaperDeploymentController@checkin` | none — public; CSRF-excepted; `throttle:120,1` |
| POST | `api/hr/onboarding` | `HrOnboardingController@store` | `hr.api_key` |
| GET | `api/hr/onboarding/check-availability` | `HrOnboardingController@checkAvailability` | `hr.api_key` |
| POST | `api/hr/offboarding` | `HrOffboardingController@store` | `hr.api_key` |
| POST | `api/hr/employee-update` | `Api\HrEmployeeUpdateController@store` | `hr.api_key` |
| GET | `api/hr/reference-data` | `Api\HrLookupController@referenceData` | `hr.api_key` |
| GET | `api/hr/employees` | `Api\HrLookupController@employees` | `hr.api_key` |
| GET | `api/hr/requests/{workflow}` | `Api\HrLookupController@requestStatus` | `hr.api_key` |
| POST | `api/hr/group-assignment` | `HrGroupAssignmentController@store` | `hr.api_key` |
| GET | `api/hr/device-lookup` | `DeviceLookupController@lookup` | `hr.api_key` |

Note: `api/hr/*` is CSRF-excepted via inline `withoutMiddleware(VerifyCsrfToken::class)` on the route group, **not** via `bootstrap/app.php`'s except-list (which covers Graylog, backup upload-hook, branch-config, branch-agents, voice-mesh, wallpapers/checkin, and `email/unsubscribe/*`).

### 2b. Other machine/public infrastructure endpoints (outside `/api/*`)

| Method | Path | Controller | Auth mechanism |
|---|---|---|---|
| GET | `/phonebook.xml` | `PhonebookController@generate` | none — `withoutMiddleware(['web'])`; polled by physical UCM desk phones, also carries their self-reported firmware `SW` version (see `docs/MODULES.md` §Phone firmware server) |
| GET | `/internal/telnet-token/{token}` | `Internal\TelnetTokenController@show` | `internal.ip` + `X-Telnet-Secret` header checked in controller; called only by the Node.js `telnet-proxy/` side service |
| GET | `/branch-agent/install.sh`, `/sg-branch-agent`, `/sg-branch-agent.sha256` | `BranchAgentDownloadController` | none — public, `throttle:60,1` |

## 3. Middleware aliases (`bootstrap/app.php`)

| Alias | Class | Purpose |
|---|---|---|
| `role` | `EnsureRole` | Blocks unless user has one of the listed roles |
| `permission` | `EnsurePermission` | Blocks unless user has one of the listed permissions (comma = OR); redirects marketing/HR-host users to their own no-access page |
| `2fa` | `RequireTwoFactor` | Forces verified TOTP; global on `web`, bypassed per-host by isolation middleware below |
| `hr.api_key` | `HrApiKeyMiddleware` | Shared API key header for token-authenticated `api/hr/*` (no session) |
| `internal.ip` | `InternalIpOnly` | Restricts to NOC's own internal/loopback network (voice-mesh, VQ report, telnet-token) |

Also global on `web` (not aliased): `SecurityHeaders`, `LogAccessVisit` (dedup'd presence heartbeat, no-op for guests), `EnforceMarketingHostIsolation` / `EnforceVcardHostIsolation` / `EnforceHrPortalHostIsolation` (each 404s every route outside that subdomain's own group, runs *before* auth via `prependToPriorityList`).

## 4. Notable non-admin route groups

- **Browser Portal** (`/portal`) — per-office VPN egress via Neko containers; hub/profile/assets any authenticated user, `/portal/browser*` needs `permission:view-browser-portal`.
- **HR Portal** (`hr.samirgroup.net`) — isolated subdomain, SSO-only, no 2FA; onboarding/offboarding/employee-update forms only ever raise `WorkflowRequest`s.
- **Email Marketing Portal** (`em.samirgroup.net`) — isolated subdomain app (campaigns/lists/segments/templates/courses/contests); admin-side SES/suppression controls stay under NOC `/admin`.
- **vCard Portal** (`vcard.samirgroup.net`) — isolated subdomain, employee digital business cards; SSO-only, no 2FA.
- **IT Ticket Portal** (`it.samirgroup.net`) — unlogged landing page + `/go` click-through logger forwarding to the external Oracle ticketing app (`config/ticket_tracking.php`).
- **Public Phonebook** (host-agnostic) — `/phonebook.xml` (desk-phone directory + firmware self-report) and `/contacts*` (human-readable directory + print layouts); the app's original function before it grew into the NOC.
