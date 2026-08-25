# Phone Firmware Server

The NOC hosts Grandstream firmware over HTTP. You set a firmware path **once** in the UCM Zero
Config global policy; from then on every phone checks that path, compares the version baked into
the image against its own, and flashes itself when they differ.

Nav: **Phones → Firmware Server** (`/admin/phones/firmware`) and **Firmware Status**
(`/admin/phones/firmware/status`).

## How a phone actually upgrades

1. The phone is provisioned with a *Firmware Upgrade Server Path* (a bare host, no `http://`).
2. On boot, and again on its Automatic Upgrade interval, it does a plain HTTP `GET` for **one
   fixed filename** — `grp2601fw.bin`, `gxp1780fw.bin`, and so on. The name is the vendor's and
   the phone will ask for nothing else.
3. It reads the version out of the image header. Same version → it does nothing. Different →
   it downloads, flashes and reboots.

Two consequences shape the whole design:

- **One flat directory serves the entire fleet.** Every model asks for a distinct filename, so
  there are no per-model folders and nothing to route.
- **Never rename a vendor `.bin`.** A renamed image is a permanent, silent 404 on the phone. The
  upload path preserves vendor filenames verbatim and refuses anything that is not a `.bin`.

## Publishing firmware

Go to **Phones → Firmware Server** and either:

- **Upload a package** — the vendor `.zip` or a single `.bin`. Every `.bin` inside a zip becomes
  its own library entry (a family package like `grp260x_1.0.13.59.zip` yields several); release
  notes and checksum files are discarded.
- **Fetch from a URL** — the NOC downloads it server-side on the next scheduler tick. **Prefer
  this for full packages.** Grandstream zips run 60–150 MB, which is exactly the size that dies
  against nginx's `client_max_body_size` or PHP's `post_max_size` on a browser upload, with a
  confusing 413 as the only clue.

Then hit **Publish**. That copies the image to the flat serve root, retires any earlier image for
the same models, and writes the version onto the matching `device_models.latest_firmware` rows so
the existing ITAM **Firmware Tracker** (`/admin/devices/firmware`) starts flagging phones that are
behind.

**Covers** is a comma-separated list of the models an image applies to, with `*` allowed as a
suffix wildcard — `GRP2601*, GRP2602` matches `GRP2601`, `GRP2601W` and `GRP2602`. One Grandstream
image usually covers a whole family and only the vendor knows the grouping, so it is an explicit
editable field rather than something guessed from the filename. Left blank, an image covers only
its own model.

## Pointing the phones at it

UCM → **Zero Config → Global Policy → Upgrade**:

| Field | Value |
|---|---|
| Firmware Source | `URL Download` |
| Upgrade Via | `HTTP` |
| Firmware Upgrade Server Path | `172.16.8.11` (internal) or `noc.samirgroup.net/fw` (public) |
| Always Check for New Firmware | on |
| Automatic Upgrade | on, with an interval |

The two paths are shown for copy-paste at the top of the Firmware Server page and are configurable
via `config/phone_firmware.php` (`PHONE_FIRMWARE_INTERNAL_URL` / `PHONE_FIRMWARE_PUBLIC_URL`).

Prefer the internal path — it stays inside the branch tunnels and involves no TLS. Use the public
one only for a branch that cannot reach the NOC internally.

## Server setup

```sh
sudo FIRMWARE_LISTEN=172.16.8.11 bash deployment/firmware/setup.sh
```

That writes two things and reloads nginx:

- `/etc/nginx/sites-available/phone-firmware` — a static vhost on port 80 bound to the internal IP.
- `/etc/nginx/sites-dynamic/phone-firmware.conf` — a `location ^~ /fw/` alias picked up by the
  `include` the main NOC vhost already carries for the browser portal, so the public fallback
  needs no hand-editing of a vhost that is not in version control.

Both serve `storage/app/public/firmware/` directly. The `library/` subdirectory (every image ever
added, published or not) is denied; only what has been published sits at the root.

The script finishes with a canary fetch and fails loudly if port 80 answers with a redirect.

**`FIRMWARE_LISTEN` matters.** Binding to a specific IP makes this the default server for that
address, so a phone pointed at the bare IP matches whatever `Host` header it sends. Without it the
vhost answers by hostname only and IP-configured phones fall through to the default server.

## Watching who took it

**Firmware Status** (`/admin/phones/firmware/status`) compares what each phone is running against
what is published.

The running version comes from the phones themselves. Every Grandstream announces its firmware in
the User-Agent it sends when it fetches `/phonebook.xml`:

```
Grandstream Model HW GRP2616 SW 1.0.13.59 DevId ec74d7800474
```

`PhonebookController` records the `SW` field into `phone_request_logs.firmware`. That is fresher
than GDMS and needs no cloud round trip. `phones:sync-firmware-versions` (daily 05:45) folds it
back into `devices.firmware_version`, falling back to GDMS for phones that have not checked in.

A phone that has **never** checked in cannot reach the NOC over HTTP at all. That is a tunnel
problem, not a firmware-server problem — see the gotchas.

`phone_request_logs` is also the honest pre-flight before pointing a branch at the internal path:
if its phones already appear there, they can reach the NOC.

## Gotchas

- **Never put this vhost behind an HTTP→HTTPS redirect.** Older Grandstream firmware fails modern
  TLS handshakes and does not reliably follow 302s. A redirect means every phone silently stops
  upgrading and the only symptom is a status board that stays red. If you add TLS here later, run
  certbot with `--no-redirect` and leave port 80 answering.
- **GDMS also provisions these phones.** Per [GDMS_PHONE_MANAGEMENT.md](GDMS_PHONE_MANAGEMENT.md),
  device config is template-driven and template editing is a GDMS-console operation. If a GDMS
  template also sets a firmware path, whichever source provisions last wins. Pick one owner — UCM
  Zero Config — and leave the field empty in the GDMS template.
- **A reachable branch firewall does not mean the tunnel carries the NOC subnet.** Before switching
  a branch to the internal path, check its phones actually appear in `phone_request_logs`. This is
  the same class of failure the tunnel watchdog exists to catch.
- **Upload limits are two ceilings, not one.** nginx defaults `client_max_body_size` to **1 MB** and
  answers anything larger with a bare `413 Request Entity Too Large` before PHP runs at all; PHP
  then applies its own `upload_max_filesize` / `post_max_size`. Raising one without the other
  changes nothing. Both are now set from the repo — nginx by `deployment/firmware/setup.sh`
  (`UPLOAD_MAX_BODY`, default 512m, written into the `sites-dynamic` snippet, which sits inside the
  server block and so applies vhost-wide) and PHP by `public/.user.ini`. **A 413 after a deploy
  almost always means `setup.sh` has not been re-run.** If it persists, check whether the NOC vhost
  sets its own `client_max_body_size` *after* the include — the last one in the block wins, and
  setup.sh warns when it sees one.
- The upload box on the firmware page states the live ceiling and refuses an oversized file in the
  browser, pointing at the URL fetch instead — that path is server-side and has no such limit.
- **The `/fw/` path only exists after `setup.sh` has run.** It is an nginx alias, not a Laravel
  route; firmware bytes never pass through PHP.

## Deploy notes

Standard workflow (edit locally → commit → push → `git pull` on the VPS), then:

```sh
php artisan migrate
sudo FIRMWARE_LISTEN=172.16.8.11 bash deployment/firmware/setup.sh
php artisan config:clear
```

Permissions: `view-phone-firmware` (super_admin, admin, viewer), `manage-phone-firmware`
(super_admin, admin).

Scheduler: `firmware:fetch-remote` every minute, `phones:sync-firmware-versions` daily at 05:45.

## Files

- Service: `app/Services/Phone/FirmwarePublisher.php` (zip unpacking, traversal guard, publish).
- Controller: `app/Http/Controllers/Admin/PhoneFirmwareController.php`.
- Model: `app/Models/PhoneFirmware.php` (note the pinned `$table` — "firmware" is uncountable, so
  Eloquent would otherwise infer `phone_firmware`).
- Commands: `app/Console/Commands/FetchRemoteFirmware.php`, `SyncPhoneFirmwareVersions.php`.
- Views: `resources/views/admin/phones/firmware/{index,status}.blade.php`.
- Config: `config/phone_firmware.php`, plus the `firmware` disk in `config/filesystems.php`.
- Deployment: `deployment/firmware/setup.sh`.
- Tests: `tests/Unit/PhoneFirmwareTest.php`.
