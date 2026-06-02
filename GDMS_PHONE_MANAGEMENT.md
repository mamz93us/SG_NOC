# GDMS Phone Management

One place in the NOC to manage Grandstream IP phones against **GDMS** (Grandstream Device Management System cloud) — instead of bouncing between the GDMS web UI, the UCM web UI, and the ITAM pages.

Nav: **Phones** (`/admin/phones`). Related: **PBX Status** (`/admin/gdms/ucm`), **Config Templates** (`/admin/gdms/templates`).

## What it does

- **Inventory** — every phone (GDMS device list ⨯ ITAM `devices` ⨯ `phone_accounts` SIP data ⨯ `contacts` ⨯ `employees`), with a status badge (`ready` / `no_asset` / `no_account` / `no_employee` / `assigned` / `wrong_employee`). Built by `app/Services/PhoneInventoryService.php` (shared with the older Phone Auto-Assign screen).
- **Provisioning** — claim a phone into GDMS by **MAC + serial**, auto-create the ITAM asset, optionally assign an employee.
- **Detail + actions** — per-phone SIP accounts, **reboot**, **factory reset** (super-admin only), **assign/change SIP account**, **push config**. Every action is logged to `gdms_tasks`.
- **Config templates** — list/edit GDMS config templates (P-values) and assign them to devices by MAC.
- **PBX status + Wave** — the existing UCM status page keeps its live SNMP memory/disk, plus GDMS cloud online-state per UCM and the **GDMS SIP servers** that Wave registers against.

## Account flow (NOC → UCM → GDMS → phone)

1. Create the extension on the UCM from the NOC (existing **Extensions** page / `IppbxApiService::createExtension`).
2. The UCM auto-syncs the SIP account to GDMS via **RemoteConnect** (this is why `UcmServer.cloud_domain` is set).
3. On the phone's detail page, **Assign SIP Account**: pick the UCM + extension + account slot. The NOC reads the extension's secret from the UCM (`getExtensionWave`) and binds it on the phone via GDMS.

## ⚠️ Run the probe before using write actions

The public GDMS API reference is a JS SPA that can't be scraped, so a few **write** endpoints are best-guess by symmetry with the confirmed read endpoints. They are marked `⚠️ PROBE-PENDING` in `app/Services/GdmsService.php` (`EP_*` constants) and `config/services.php` (`task_factory_reset`).

```sh
php artisan gdms:probe                          # read-only: confirms paths + response shapes
php artisan gdms:probe --mac=EC:74:D7:80:04:74  # also dumps device/detail (memory/storage fields)
```

Confirmed: `oauth/token`, `device/list`, `device/detail`, `sip/account/list`, `device/add`, `task/add` (REBOOT = taskType 1), `org/list`, `site/list`.
Confirm-before-use: `factory_reset` taskType, `sip/account/assign`, `sip/server/list`, `template/*`, `device/config/set`. Update the `EP_*` paths / `TASK_*` values for any `[ERR]` line the probe reports. **Do not click Factory Reset in production until its taskType is confirmed.**

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
