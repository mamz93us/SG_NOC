# GDMS Phone Management

One place in the NOC to manage Grandstream IP phones against **GDMS** (Grandstream Device Management System cloud) — instead of bouncing between the GDMS web UI, the UCM web UI, and the ITAM pages.

Nav: **Phones** (`/admin/phones`). Related: **PBX Status** (`/admin/gdms/ucm`), **Config Templates** (`/admin/gdms/templates`).

## What it does

- **Inventory** — every phone (GDMS device list ⨯ ITAM `devices` ⨯ `phone_accounts` SIP data ⨯ `contacts` ⨯ `employees`), with a status badge (`ready` / `no_asset` / `no_account` / `no_employee` / `assigned` / `wrong_employee`). Built by `app/Services/PhoneInventoryService.php` (shared with the older Phone Auto-Assign screen).
- **Provisioning** — claim a phone into GDMS by **MAC + serial**, auto-create the ITAM asset, optionally assign an employee.
- **Detail + actions** — per-phone live SIP accounts and **Reboot** (logged to `gdms_tasks`).
- **Config templates** — **read-only** list of GDMS "group" templates (`/admin/gdms/templates`, refreshed by `gdms:sync-templates`).
- **PBX status + Wave** — the existing UCM status page keeps its live SNMP memory/disk, plus GDMS cloud online-state per UCM and the **GDMS SIP servers** that Wave registers against.

### Not exposed by the GDMS OpenAPI (do these in the GDMS web console)

Confirmed via `gdms:probe` against the live org: GDMS exposes **list/read** (devices, sites, orgs, SIP accounts, SIP servers, templates), **device claim** (`device/add`), **reboot** (`task/add`), and SIP-account **create/edit**. It does **not** expose:

- Template **edit / assign** — only `template/group/list` exists; editing/assigning templates is a console operation.
- Per-device **config push** — no `device/config*` route; device config is template-driven.
- An explicit **account → device** binding endpoint — only `sip/account/add|edit` (params undocumented).
- A confirmed **factory-reset** taskType.

So those actions show a "manage in the GDMS console" note in the UI rather than a button.

## Account flow (NOC → UCM → GDMS → phone)

1. Create the extension on the UCM from the NOC (existing **Extensions** page / `IppbxApiService::createExtension`).
2. The UCM auto-syncs the SIP account to GDMS via **RemoteConnect** (this is why `UcmServer.cloud_domain` is set).
3. In GDMS, assign that SIP account to the phone's account slot (GDMS web console — the API has no account→device binding). GDMS then provisions the phone automatically.

## Endpoint discovery (`gdms:probe`)

The public GDMS API reference is a JS SPA that can't be scraped, so unconfirmed endpoints were discovered with the read-only probe:

```sh
php artisan gdms:probe                          # tries candidate paths, reports which exist
php artisan gdms:probe --mac=EC:74:D7:80:04:74  # also dumps device/detail (memory/storage fields)
```

Confirmed on the live org: `oauth/token`, `device/list`, `device/detail`, `sip/account/list`, `sip/server/list`, `template/group/list`, `device/add`, `task/add` (REBOOT = taskType 1), `org/list`, `site/list`.

Endpoints that exist but are unused (no UI) are config-overridable — set `GDMS_EP_*` in `.env` (see `config/services.php` → `gdms.endpoints`) if a path ever changes, no code edit needed.

## Deploy notes

Standard workflow (edit locally → commit → push → `git pull` on the VPS), then:

```sh
php artisan migrate            # gdms_tasks, gdms_templates, view/manage/reset-phones perms, gdms_site/project on settings
php artisan gdms:probe         # confirm the PROBE-PENDING endpoints against the live org
npm run build                  # only if assets changed (none here)
```

Settings → GDMS can hold the default site/project (`gdms_site_id`, `gdms_project_id`); or set `GDMS_SITE_ID` / `GDMS_PROJECT_ID` / `GDMS_TASK_FACTORY_RESET` in `.env`.

Permissions: `view-phones` (super_admin, admin, viewer), `manage-phones` (super_admin, admin), `reset-phones` (super_admin only).

Scheduler: `gdms:sync-templates` runs daily (05:30) to refresh the template cache.

## Files

- Service: `app/Services/GdmsService.php` (write layer + `signedRequest`), `app/Services/PhoneInventoryService.php`.
- Controllers: `app/Http/Controllers/Admin/PhoneManagementController.php`, `GdmsTemplateController.php`, `GdmsController.php` (PBX status).
- Commands: `app/Console/Commands/GdmsProbe.php`, `SyncGdmsTemplates.php`.
- Models: `app/Models/GdmsTask.php`, `GdmsTemplate.php`.
- Views: `resources/views/admin/phones/*`, `resources/views/admin/gdms/templates/*`, `resources/views/admin/gdms/ucm.blade.php`.
- Routes: `routes/web.php` (`admin.phones.*`, `admin.gdms.templates.*`).
