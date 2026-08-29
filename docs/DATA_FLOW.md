# SG_NOC Data Flow

> How data moves through the system, end to end. For the design principles behind these flows see `docs/ARCHITECTURE.md`. For module file locations see `docs/MODULES.md`.

## 1. Inbound telemetry (SNMP/syslog)

Two paths:
- **NOC-pulled**: scheduled `CollectSnmpMetricsJob` / `DiscoverSnmpDeviceJob` poll devices directly over the branch VPN.
- **Branch-agent-collected**: the Go `branch-agent` polls SNMP and tails syslog locally into daily-rolling SQLite on the branch VM. Logs are **not** synced centrally — the NOC queries them on demand via `BranchLogClient`.

Graylog ingests syslog centrally and POSTs matches to `/api/graylog/webhook`, which chains `ParseSyslogPayloadsJob` → `TagSyslogSourcesJob` → `MatchSyslogAlertsJob` → `NocEvent`.

## 2. Metrics rollup pipeline

`sensor_metrics` (raw) → `RollupMetricsJob` (hourly) → `metric_rollups` / `SensorMetricHourly` / `SensorMetricDaily` → `PruneVqData` (daily retention enforcement). Dashboards and history views query the rollup tables, never raw.

## 3. Event/alert pipeline

Subsystem checks (ping, SNMP threshold, syslog rule match, tunnel probe, voice mesh) create/resolve `NocEvent` rows → `NotificationRule::resolveRecipients()` → `NotificationService::notifyViaRules` fans out over email / in-app / WhatsApp (Meta Cloud API, audited to `whatsapp_logs`) → falls back to "all admins" if no rule matches the event type. WhatsApp requires a Meta-approved template; free-form text outside a 24h reply window fails with error 131047.

Tunnel outages bypass this and write directly to `TunnelOutage` (see `docs/ARCHITECTURE.md`).

## 4. Workflow engine — every request type

Every `WorkflowRequest` gets an approval chain from `WorkflowTemplate.approval_chain` (matched by `type_slug`), falling back to a single `it_manager` step. Two *separate* execution mechanisms exist after approval, and which one actually runs depends on the type:

- **Generic path**: `WorkflowEngine::executeWorkflow()` → `ExecuteWorkflowJob::handle()`, whose `match ($workflow->type)` has automation for exactly **3** types (`create_user`, `delete_user`, `profile_update_phone`) plus a log-only case (`license_purchase`). Every other type falls to `default => "has no automated execution handler"` — and the job still marks the workflow `completed` regardless.
- **Side-channel path**: onboarding and offboarding bypass (parts of) the generic path entirely, driven instead by a public token-based manager form that calls the relevant service directly.

### 4.1 Onboarding (`create_user`) — two-stage, gated behind IT approval
`OnboardingRequestService::create()` (`Portal\HrOnboardingController` / `Api\HrOnboardingController`) creates the `WorkflowRequest` and an `OnboardingManagerToken`, but does **not** email the manager yet. After IT approval, `WorkflowEngine::executeWorkflow()` detects `shouldWaitForManagerForm()` and:
1. Runs `UserProvisioningService::provisionCoreIdentity()` synchronously — duplicate-name check, UPN build, Azure AD user creation, license assignment, branch/department/gender group auto-assignment, `Employee` row creation, Azure profile sync. Every step is idempotent.
2. Emails the manager-setup-form link, sets `status = awaiting_manager_form`.

Manager submits `/onboarding/form/{token}` (public, no auth — `Public\OnboardingFormController`): laptop status, whether an extension is needed, internet tier, floor, distribution-group picks, business-app requests, comments. This resumes `UserProvisioningService::completeProvisioning()`: floor-specific groups, business-app account requests + admin-team emails, manager-selected groups, internet-access group, UCM extension (floor→branch→global range priority, "-25/already exists" treated as non-fatal), printer deploy email/Intune note, external ticketing API call (laptop+phone tickets), IT summary email (with credentials), employee welcome email, HR/manager details email (no credentials).

### 4.2 Offboarding (`employee_offboarding`) — manager-form-driven, NOT gated behind IT approval
`OffboardingRequestService::create()` (`Portal\HrOffboardingController` / `Api\HrOffboardingController`) creates the `WorkflowRequest`, an `OffboardingWorkflow` row (`status=manager_input_pending`), an `OffboardingToken`, and — unlike onboarding — queues `SendOffboardingManagerRequestJob` **immediately**, independent of whether IT has approved the request yet.

Manager submits `/offboarding/respond?token=` (`Public\OffboardingFormController`, DB-locked against double-submit): email action (delete/forward+duration), laptop action (backup/delete), asset action (transfer/return), retrieval checklist.
- **Reject** → `WorkflowRequest.status=rejected`, `OffboardingWorkflow.status=cancelled`, HR feedback email.
- **Approve** → the controller sets `WorkflowRequest.status=executing` **directly** (not via `WorkflowEngine::executeWorkflow()`) and calls `OffboardingProcessor::beginProcessing()`, which dispatches: add-to-offboarding-group (CA sign-in lockdown), AvePoint mailbox + OneDrive export jobs, laptop-backup task or Intune device removal, remove-from-all-other-groups (20s delayed), UCM extension delete, mailbox forwarding rule (if requested), asset-move jobs, retrieval tasks, and computes `delete_after`.

`OffboardingScheduler` (daily, `RunOffboardingScheduler`) is the actual lifecycle daemon from there: auto-disables the Azure user on `expected_last_day`, sends manager reminders during the grace period, escalates to IT after grace expires, removes forwarding at `forward_until`, and does the final Azure user delete once `delete_after` has passed **and** all backups report complete.

**Gap found**: the IT-approval chain on this `WorkflowRequest` runs independently of everything above. If it completes, `executeWorkflow()` dispatches `ExecuteWorkflowJob`, which has no case for `employee_offboarding` and falls to the default handler — marking `WorkflowRequest.status=completed` regardless of whether the manager has even responded yet. No code path was found reconciling `WorkflowRequest.status` (engine-owned) with `OffboardingWorkflow.status` (processor/scheduler-owned) — they are two independent state machines tracking one termination.

### 4.3 Employee data change (`employee_update`) — approval-only, diff never applied
`EmployeeUpdateRequestService::create()` (`Portal\HrEmployeeUpdateController` / `Api\HrEmployeeUpdateController`) diffs submitted fields (name, job title, department, branch, manager, phones, office location, Oracle emp no — email/UPN deliberately excluded) against the current `Employee` row via `buildDiff()`, blocks a second in-flight request for the same employee, and raises a `WorkflowRequest` with the diff in `payload.changes`.

**Gap found**: `ExecuteWorkflowJob`'s type match has no case for `employee_update` — it falls to the default "no automated execution handler" log line and is still marked `completed`. No code path was found that writes `buildDiff()`'s changes back to the `Employee` row or pushes them to Azure, despite the service's own docstring claiming "the apply step... happens on approval in the workflow engine."

### 4.4 Group assignment (`group_assignment`) — records only, never assigns
`POST /api/hr/group-assignment` (`Api\HrGroupAssignmentController`) resolves group names to Azure group IDs via Graph, then loops over them — but the loop body only appends to a local `$assigned[]` array; **no `GraphService::addUserToGroup()` call exists in this method.** It creates a `WorkflowRequest(group_assignment)` purely as an audit record and returns `"N group(s) assigned successfully"` in the JSON response.

**Gap found**: the endpoint's success response is misleading — it reports groups as assigned when nothing was written to Azure. `ExecuteWorkflowJob` has no case for `group_assignment` either, so even manually completing the resulting `WorkflowRequest` applies nothing.

### 4.5 Self-service phone update (`profile_update_phone`) — fully wired
Employee submits a phone-number change at `/portal` (`Portal\MyProfileController`) → `WorkflowRequest` created → IT approves → `ExecuteWorkflowJob::applyPhoneUpdate()` writes the new number to the employee's linked `Contact` (creating one if none exists), so `ContactObserver` fires and the change lands in `ActivityLog`.

### 4.6 System-triggered types (no HR/manager form)
- **`license_purchase`** — `LicenseMonitorService` raises one when a license SKU's available seats fall to/below its critical threshold (rate-limited via `canAlert()`). `ExecuteWorkflowJob` just logs "procurement team to proceed manually" — it exists purely as an approval-tracked notification, not automation.
- **Event-triggered types** (`employee.created`, `host.down`, or any event an admin wires up) — `WorkflowTriggerListener` matches the event to an active `WorkflowTemplate.trigger_event` and raises a `WorkflowRequest` of that template's `type_slug`, running the **graph engine** (Drawflow nodes: approval/action/condition/notification/wait, via `WorkflowEngine::executeCurrentNode()`) instead of the legacy linear chain. This is the only path where an admin can build new automated workflows without touching PHP — but see the "graph builder dead" defect noted in `docs/ARCHITECTURE.md`.
- **Admin-only types with no automated creator or handler**: `license_change`, `asset_assign`, `asset_return`, `extension_create`, `extension_delete`, `other` are valid `type` values in `Admin\WorkflowController`'s manual-create form, but none has a creating service or an `ExecuteWorkflowJob` case — they exist purely as ad-hoc approval records unless routed through a `WorkflowTemplate` graph.

### Execution handler coverage (verified against `ExecuteWorkflowJob::handle()`)

| Type | Raised by | Post-approval automation |
|---|---|---|
| `create_user` | OnboardingRequestService | Full — two-stage (§4.1) |
| `delete_user` | manual only | Full — `deprovisionUser()` |
| `profile_update_phone` | Portal\MyProfileController | Full — writes to `Contact` |
| `license_purchase` | LicenseMonitorService | Log-only, human procurement |
| `employee_offboarding` | OffboardingRequestService | **None via this path** — real processing bypasses it (§4.2) |
| `employee_update` | EmployeeUpdateRequestService | **None — gap (§4.3)** |
| `group_assignment` | Api\HrGroupAssignmentController | **None — gap (§4.4)** |
| `license_change` / `asset_assign` / `asset_return` / `extension_create` / `extension_delete` / `other` | manual only | None |
| any `WorkflowTemplate.type_slug` | WorkflowTriggerListener or manual graph run | Whatever the graph's action nodes dispatch |

**Bottom line**: most workflow types are approval-tracking scaffolding, not automation. Only 3 of the ~12 types found in the code actually do something after approval via the generic engine path; `employee_update` and `group_assignment` look automated from their docstrings/API responses but are not — verify before relying on either in production.

## 5. Backup pipeline

Devices/WHM push files over SFTP into per-device SFTPGo accounts → scheduled `sftp-backups:sweep` streams stable files to Azure Blob (`azure_backups` disk) and deletes the local copy → tracked in `sftp_backups` table → `sftp-backups:prune` enforces retention.

Parallel flows: AvePoint (mailbox/OneDrive export → Azure) and offboarding backups — both served back to the requester through signed/tokenized download controllers (`OffboardingDownloadController`, `AvepointDownloadController`), never a direct Azure link.

## 6. Phone directory + firmware self-report (`/phonebook.xml`)

Dual-purpose legacy endpoint (the app's original function — see `docs/CODEBASE_MAP.md`): physical UCM desk phones poll `GET /phonebook.xml` (no session/CSRF — `withoutMiddleware(['web'])`) both to fetch their speed-dial directory **and**, as a side effect, to self-report their running firmware version in the `SW` field of their User-Agent, recorded by `PhonebookController` — this drives the firmware status board, not GDMS polling. Human-readable equivalents at `/contacts` and `/contacts/print(-compact)`.

Firmware bytes themselves are served directly by nginx (never touches PHP) — see `deployment/firmware/`.

## 7. External proxy traffic

- `it.samirgroup.net`: visit logged (`TicketVisitRecorder`) → 302 redirect to the external Oracle ticketing system. Stats at `/admin/ticket-stats`.
- `noc-agw` (separate FastAPI process): reverse-proxies `arcmate.samirgroup.net` to a legacy on-prem IIS app with IP-ACL + audit logging — entirely outside the Laravel request cycle.

## 8. Voice mesh synthetic calls

Python/pjsua prober (systemd timer on the NOC host, wakes every 5 min, self-gates on the configured interval) pulls branch SIP credentials from `/api/voice-mesh/config` → places test calls between branch UCMs → POSTs one combined report per sweep to `/api/voice-mesh/report` → `VoiceMeshMonitor` ingests, rolls up per-leg results, and alerts **at the node level** (not per-leg — a mesh of N nodes has N×(N−1) legs, so per-leg alerting would flood on one dead UCM).

## 9. Access analytics

`LogAccessVisit` middleware + the Microsoft SSO login hook (`MicrosoftController`) write to `access_visits` on every login and periodic heartbeat, covering NOC/EM/Portal. Surfaced at `/admin/access-stats` (gated by `view-activity-logs`).

## 10. SMTP relay audit trail (Ricoh scan-to-email)

Legacy MFPs submit plain SMTP to the NOC's internal Postfix smarthost (port 25) → Postfix rewrites the sender to the SES-verified address and relays over TLS to Amazon SES, logging Subject + attachment names via `header_checks` to `/var/log/mail.log` → scheduled `smtp-relay:ingest-log` (`IngestSmtpRelayLog`) tails that log into `smtp_relay_messages`/`smtp_relay_attachments` → surfaced at `/admin/smtp-relay` (gated `view-smtp-relay`). Entirely outside the Laravel request cycle until the log-tail step.

## 11. RADIUS MAC-authentication (NAC)

FreeRADIUS (`deployment/freeradius/`) authenticates switch/AP MAC-auth-bypass requests directly against MySQL (`authorize_reply_query` in `mods-available/sql`) — the Laravel app never sits in the auth request path. `RadiusVlanPolicyResolver` mirrors that SQL's VLAN-resolution precedence (per-MAC override → most-specific branch/adapter/device-type policy → catch-all → none) purely for the admin "Preview" panel and tests, to keep the PHP and SQL logic in sync. `SyncRadiusMacs` keeps `DeviceMac`/`RadiusMacOverride`/`RadiusBranchVlanPolicy` populated from ITAM device data.

## 12. Certificate renewal (ACME/DNS-01)

Scheduled `dns:renew-expiring-certs` (`RenewExpiringCertsCommand`) finds `SslCertificate` rows expiring within 14 days with `auto_renew=true` → `Services\Dns\AcmeService` completes a DNS-01 challenge via `Services\Dns\GoDaddyService` → renewed cert exported via `Services\Dns\CertificateExportService`. Backs both general subdomain certs and Sophos firewall certs (the GoDaddy wildcard's original private key was lost — this subsystem replaced it).

## 13. Recruitment CV export (Teamtailor)

Scheduled `teamtailor:process-cv-exports` drains pending `TeamtailorCvExport` rows → `Teamtailor\TeamtailorCvExportService` pulls every applicant's résumé from the Teamtailor API, zips it, and uploads to the `teamtailor-resumes` Azure Blob disk. Each run defensively lifts the CLI time limit since one export can pull hundreds of remote files.
