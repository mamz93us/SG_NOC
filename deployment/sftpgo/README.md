# SFTPGo — device backup ingestion (SFTP + FTPS)

SFTPGo is the **ingestion layer** for the NOC backup system. Every network device
(Sophos firewalls, Grandstream UCM, switches) and the WHM/cPanel server pushes its
config/account backup here over **SFTP or FTPS**, each authenticating with a **unique
per-device account** the NOC creates and rotates from **Admin → Device Backups**.

```
  ┌───────────────┐  SFTP :2022 / FTPS :2121   ┌──────────────────────────┐
  │ Sophos / UCM  │ ─────────────────────────► │  SFTPGo on the NOC VM    │
  │ switches /WHM │   unique account per device│  /srv/backups/<username>/│
  └───────────────┘                            └────────────┬─────────────┘
                                                upload event │ webhook (X-Backup-Secret)
                                                             ▼
                          ┌──────────────────────────────────────────────┐
                          │ NOC (Laravel)                                  │
                          │  • POST /api/backup/upload-hook → "received"   │
                          │  • sftp-backups:sweep → Azure Blob, sha-verify │
                          │  • backups:check-overdue → NocEvent + alerts   │
                          └──────────────────────────────────────────────┘
```

The NOC is the **control plane** (accounts, monitoring, dashboard); SFTPGo is the
**data plane**. The NOC manages SFTPGo *users* via its REST API and consumes the upload
webhook — it does not touch SFTPGo's database (which is a dedicated `sftpgo` DB, **not**
`phonebook2`). Uploads land locally; the existing `sftp-backups:sweep` archives them to
Azure. This **replaces** the OS-user chroot in [`deployment/sftp/`](../sftp/README.md) — see §9.

---

## 1. Install the SFTPGo binary

Pick one (both leave a `sftpgo` binary on `PATH`):

**A — official apt repo (recommended, gives a systemd unit too):**
```sh
# Follow https://github.com/drakkan/sftpgo → "Install" for the current repo command, then:
sudo apt-get update && sudo apt-get install -y sftpgo
```

**B — manual binary:**
```sh
VER=v2.6.6   # check the latest release; keep it pinned
cd /tmp
curl -fsSLO "https://github.com/drakkan/sftpgo/releases/download/${VER}/sftpgo_${VER}_linux_x86_64.tar.xz"
curl -fsSLO "https://github.com/drakkan/sftpgo/releases/download/${VER}/sftpgo_${VER}_checksums.txt"
sha256sum --check --ignore-missing "sftpgo_${VER}_checksums.txt"   # MUST print OK
tar -xf "sftpgo_${VER}_linux_x86_64.tar.xz" sftpgo
sudo install -m755 sftpgo /usr/local/bin/sftpgo
```

> If apt installs its own `sftpgo.service`, `install.sh` detects it and adds a drop-in
> rather than overwriting it.

## 2. Create the data database

```sh
sudo nano deployment/sftpgo/setup.sql     # set a strong password in place of REPLACE_ME
sudo mysql < deployment/sftpgo/setup.sql
```
This creates a dedicated `sftpgo` database and a least-privilege `sftpgo'@'localhost`
user — **no access to `phonebook2`**.

## 3. Run the OS setup

```sh
sudo bash deployment/sftpgo/install.sh
```
Creates the `sftpgo` service user, `/srv/backups` (+ an ACL so the sweeper user
`azureuser` can read/delete uploads), `/etc/sftpgo`, installs `sftpgo.json`, seeds
`/etc/sftpgo/sftpgo.env`, and wires up systemd.

## 4. Set the secrets

```sh
sudo nano /etc/sftpgo/sftpgo.env
#   SFTPGO_DATA_PROVIDER__PASSWORD   = the password from setup.sql
#   SFTPGO_DEFAULT_ADMIN_PASSWORD    = a strong bootstrap admin password
```

## 5. FTPS certificate

SFTPGo runs as the unprivileged `sftpgo` user and can't read `/etc/letsencrypt/live`
(root-only private keys — the same gotcha as CUPS). Copy the cert into a place it can
read, and refresh it on renewal with a deploy-hook:

```sh
sudo install -d -o sftpgo -g sftpgo -m750 /etc/sftpgo/certs
sudo install -m644 /etc/letsencrypt/live/noc.samirgroup.net/fullchain.pem /etc/sftpgo/certs/fullchain.pem
sudo install -m640 -g sftpgo /etc/letsencrypt/live/noc.samirgroup.net/privkey.pem /etc/sftpgo/certs/privkey.pem

# Renew-hook: reload certs + restart SFTPGo after each LE renewal.
sudo tee /etc/letsencrypt/renewal-hooks/deploy/sftpgo.sh >/dev/null <<'HOOK'
#!/usr/bin/env bash
install -m644 /etc/letsencrypt/live/noc.samirgroup.net/fullchain.pem /etc/sftpgo/certs/fullchain.pem
install -m640 -g sftpgo /etc/letsencrypt/live/noc.samirgroup.net/privkey.pem /etc/sftpgo/certs/privkey.pem
systemctl restart sftpgo
HOOK
sudo chmod 755 /etc/letsencrypt/renewal-hooks/deploy/sftpgo.sh
```

> Prefer SFTP (:2022, single port, no cert hassle). FTPS exists for devices/WHM that
> can't do SFTP. **Never** enable plain FTP without TLS — `tls_mode` is `1` (explicit FTPS).

## 6. Start it + first login

```sh
sudo systemctl restart sftpgo
systemctl status sftpgo            # should be active (running)
sudo ss -ltnp | grep -E '2022|2121|8080'   # sftpd, ftpd, local REST
```
The REST/web admin binds `127.0.0.1:8080` only. Reach it through an SSH tunnel:
```sh
ssh -L 8080:127.0.0.1:8080 azureuser@noc.samirgroup.net
#   then browse http://localhost:8080  → log in with the bootstrap admin → CHANGE the password
```

## 7. Define the upload → NOC webhook (one-time, in the web admin)

This is what makes "is each backup received?" real-time. In the SFTPGo web admin:

1. **Event Manager → Event Actions → Add:**
   - Name: `notify-noc-upload`
   - Type: **HTTP**
   - Endpoint: `https://noc.samirgroup.net/api/backup/upload-hook`
   - Method: `POST`
   - Headers: add `X-Backup-Secret` = *(the secret you'll also paste into Admin → Settings → SFTPGo)*; and `Content-Type` = `application/json`
   - Body:
     ```json
     {"username":"{{.Name}}","path":"{{.VirtualPath}}","name":"{{.ObjectName}}","size":{{.FileSize}},"protocol":"{{.Protocol}}","ip":"{{.IP}}","timestamp":"{{.Timestamp}}","status":"{{.StatusString}}"}
     ```
2. **Event Manager → Event Rules → Add:**
   - Name: `on-upload`
   - Trigger: **Filesystem events**, event **`upload`**
   - Actions: `notify-noc-upload`
   - Save.

Now every completed upload POSTs to the NOC, which stamps the matching backup account
as *received*. (The `sftp-backups:sweep` job remains the source of truth for the Azure
archive + the `sftp_backups` rows.)

## 8. Wire the NOC

**Admin → Settings → SFTPGo:** base URL `http://127.0.0.1:8080`, the admin username +
password (or an API key), the **same** webhook secret as §7, enable SFTP + FTP, set a
default quota → **Test Connection** (expects OK). Then create per-device logins under
**Admin → Device Backups** (each provisions an SFTPGo user via the REST API).

### How a device connects
- **SFTP:** host `noc.samirgroup.net`, **port 2022**, the account's username + password
  (or key), upload to `/` (its chroot). e.g. `sftp -P 2022 sophos-jed-ab12@noc.samirgroup.net`.
- **FTPS (explicit):** **port 2121**, TLS required, passive ports `50000-50100` must be
  open. e.g. `lftp -u user,pass -e 'set ftp:ssl-force true; put cfg.tgz; bye' ftp://noc.samirgroup.net:2121`.
- **Sophos:** *Backup & firmware → Schedule*, mode SFTP, port 2021→**2022**, the account creds.
- **WHM:** *Backup → Additional Destinations → SFTP* (or FTP=FTPS), host/port/creds from the account.

---

## 9. Retiring the old OS-user SFTP (`deployment/sftp/`)

The old chroot inbox is **superseded** by SFTPGo. They can coexist during migration —
SFTPGo's SFTP is on **2022**, the OS `sshd` stays on **22**, so there's no conflict.
Migrate each device to its new account/port, then decommission:

```sh
sudo rm -f /etc/ssh/sshd_config.d/60-sftp-backup.conf
sudo sshd -t && sudo systemctl reload ssh
sudo deluser --remove-home backuppush 2>/dev/null || sudo userdel backuppush
sudo rm -rf /srv/sftp-backups           # old inbox (after confirming everything migrated)
```
The sweeper already reads `/srv/backups` (SFTPGo's root) after the Phase D config change,
so nothing in the app needs the old path.

---

## Firewall / ports

| Port | Service | Exposure |
|---|---|---|
| 2022 | SFTP | open to devices |
| 2121 | FTPS control | open to devices (if FTP used) |
| 50000–50100 | FTPS passive data | open to devices (if FTP used) |
| 8080 | REST / web admin | **localhost only** (SSH-tunnel to reach) |

## Troubleshooting

| Symptom | Fix |
|---|---|
| `systemctl status sftpgo` fails on start | Check `journalctl -u sftpgo -n50`. Usual causes: wrong DB password in `sftpgo.env`, `sftpgo` DB/user not created (run `setup.sql`), or cert files missing in `/etc/sftpgo/certs`. |
| FTPS connects but data transfer hangs | Passive range `50000–50100` not open, or `force_passive_ip` unset behind NAT (set `SFTPGO_FTPD__BINDINGS__0__FORCE_PASSIVE_IP` in `sftpgo.env`). |
| NOC "Test Connection" fails | Confirm `sftpgo` is running and `curl -s http://127.0.0.1:8080/healthz` works on the VM; check the admin creds in Settings. |
| Upload arrives but no webhook | The event rule (§7) isn't defined/enabled, or the `X-Backup-Secret` differs between SFTPGo and Settings (→ NOC returns 401). |
| Sweeper can't read/delete uploads | Re-run `install.sh` (re-applies the `azureuser` ACL on `/srv/backups`) and restart the scheduler/supervisor. |
