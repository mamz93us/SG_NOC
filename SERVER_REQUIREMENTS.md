# SG_NOC — Server Requirements & Rebuild Inventory

> Authoritative list of **everything that must be installed and configured** on the
> production VM for the NOC app **and all side services** to work. Built from a full
> code sweep (binaries the app shells out to, PHP extensions, daemons, `deployment/`
> services, env keys, ports, sudoers). Keep this current — it's the disaster-recovery
> source of truth (the box was rebuilt from scratch once already).
>
> Target OS: **Ubuntu 24.04 LTS** · PHP **8.3** · MySQL/MariaDB · app at
> `/home/azureuser/phonebook2`, DB `phonebook2`, host `noc.samirgroup.net`.
>
> Most of this is automated by **`deployment/rebuild-side-services.sh`** (CUPS, telnet
> proxy, browser portal, SFTPGo, strongSwan, supervisor/VQ collector). This file is the
> reference behind that script + the manual bits it can't do.

---

## 0. One-shot base install

```bash
sudo apt update && sudo apt install -y \
  nginx mariadb-server \
  php8.3-fpm php8.3-cli php8.3-mysql php8.3-curl php8.3-gd php8.3-zip \
  php8.3-mbstring php8.3-intl php8.3-bcmath php8.3-soap php8.3-snmp \
  php8.3-xml php8.3-sockets \
  git curl unzip ca-certificates acl \
  snmp nmap fping iputils-ping sshpass openssl \
  strongswan strongswan-swanctl charon-systemd libcharon-extra-plugins \
  cups cups-daemon \
  freeradius freeradius-mysql \
  supervisor \
  certbot python3-certbot-nginx
# Docker (browser portal) — install from get.docker.com (the rebuild script does this):
curl -fsSL https://get.docker.com | sh
# Node 20 + PM2 (telnet proxy):
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash - && sudo apt install -y nodejs
sudo npm install -g pm2
# Composer:
curl -sS https://getcomposer.org/installer | php && sudo mv composer.phar /usr/local/bin/composer
```

Verify PHP extensions are loaded:
```bash
php -m | grep -iE '^(openssl|gd|zip|sockets|curl|mbstring|json|fileinfo|bcmath|intl|soap|snmp|pdo_mysql|sodium)$'
```

---

## 1. PHP extensions (why each is needed)

| Extension | apt package | Required by |
|---|---|---|
| `pdo_mysql`, `mysqli` | `php8.3-mysql` | DB (queue/cache/session are all DB-driven) |
| `mbstring` | `php8.3-mbstring` | Laravel core |
| `curl` | `php8.3-curl` | `Http` facade, AWS SDK, ACME client, all API integrations |
| `openssl` | bundled in `php8.3-cli` | encryption, TOTP, ACME RSA/x509, PKCS#12 export |
| `gd` | `php8.3-gd` | `chillerlan/php-qrcode` (2FA QR), `maatwebsite/excel` |
| `zip` | `php8.3-zip` | `maatwebsite/excel`, bundle/export |
| `intl` | `php8.3-intl` | localization / formatting |
| `bcmath` | `php8.3-bcmath` | Laravel arithmetic |
| `soap` | `php8.3-soap` | UCM / IP-PBX SOAP integration (`IppbxApiService`) |
| `snmp` | `php8.3-snmp` | `Snmp\SnmpClient` (falls back to CLI `snmpget`/`snmpwalk` if absent — install for perf) |
| `sockets` | `php8.3-sockets` | `vq:collect` UDP listener (`VqCollectorDaemon`), `workerman/workerman` |
| `fileinfo` | bundled | upload MIME detection |
| `sodium` | bundled | Laravel encryption / hashing |
| `xml`, `dom` | `php8.3-xml` | Excel, SOAP, RSS/feed parsing |

---

## 2. External binaries the app shells out to

The app/services run these via `Process`/`exec`. **Bold = needs sudo** (wired through a
wrapper script + `/etc/sudoers.d/*`, never raw).

| Binary | apt package | Used by | sudo |
|---|---|---|---|
| `ping` | `iputils-ping` | `PingService`, `SlaMonitorService` | no |
| `fping` | `fping` | `IpScannerController` (parallel subnet scan) | no |
| `nmap` | `nmap` | `NetworkDiscoveryService` / IP scanner | no |
| `snmpget` / `snmpwalk` | `snmp` | `Snmp\SnmpClient`, discovery (CLI fallback) | no |
| **`swanctl`** | `strongswan-swanctl` | `VpnControlService` → `/usr/local/bin/sg-vpn-control` (exact path is hardcoded as `$wrapperPath`) | **yes** |
| **`lpadmin` / `cupsenable` / `cupsdisable` / `cupsaccept` / `cancel` / `lp`** | `cups` | `CupsService` | **yes** |
| `lpstat` | `cups` | `CupsService` (read-only status) | no |
| **`docker`** | docker-ce / docker.io | `BrowserPortal\DockerClient` (Neko containers) | via `docker` group |
| **`systemctl` / `journalctl`** | systemd | CUPS status, VPN logs, radius wrapper | **yes** (scoped) |
| **`sg-radius-control`** (wrapper) | `deployment/freeradius/` | `RadiusNasController` reload/restart FreeRADIUS | **yes** |
| `openssl` / `certbot` | `openssl`, `certbot` | `Dns\AcmeService`, cert export, LE renewal | mixed |
| `sshpass`, `ssh` | `sshpass`, `openssh-client` | device console / scripted SSH (telnet-proxy uses `ssh2` Node lib) | no |

---

## 3. Long-running daemons (must be supervised — prod has NO `queue:work`)

| Daemon | Command | Port | Kept alive by | Notes |
|---|---|---|---|---|
| **Scheduler-as-worker** | `php artisan schedule:run` (loop) | — | supervisor `deployment/supervisor/switch-poll.conf` | THE production scheduler. ~30 scheduled tasks in `routes/console.php`. Do **not** also add a `schedule:run` cron — that double-fires every task. |
| **VQ collector** | `php artisan vq:collect --port=5099` | **UDP 5099** | supervisor `deployment/supervisor/vq-collector.conf` | Listens for **SIP NOTIFY vq-rtcpxr** voice-quality reports from Grandstream phones (NOT SNMP trap). Needs UDP 5099 reachable from branch phones. |
| **Telnet/SSH proxy** | `node server.js` (`telnet-proxy/`) | **TCP 8765** (127.0.0.1) | PM2 `sg-noc-telnet` | WebSocket↔Telnet/SSH bridge. Validates tokens against the app via `INTERNAL_SECRET` = `.env TELNET_INTERNAL_SECRET`. nginx proxies `/ws/telnet` → 8765. Node deps: `ws`, `ssh2`. |

Supervisor: install units to `/etc/supervisor/conf.d/`, then
`supervisorctl reread && supervisorctl update`.

---

## 4. Side services (`deployment/`)

### SFTPGo — device backup ingestion (`deployment/sftpgo/`)
- **Install:** Go binary (GitHub releases) → `/usr/local/bin/sftpgo`; `install.sh` creates the
  `sftpgo` user, `/srv/backups` (`750`, +ACL `azureuser:rwX` so the sweeper can read/delete),
  `/etc/sftpgo`, systemd unit.
- **Own MySQL DB `sftpgo`** (NOT `phonebook2`) — `setup.sql` creates it + least-priv user.
- **Ports:** SFTP **2022/tcp**, FTPS **2121/tcp** + passive **50000–50100/tcp**, admin REST
  **127.0.0.1:8090** (loopback only).
- **Integration:** NOC manages users via REST (`SftpgoApiService`); an Event-Manager upload
  rule POSTs to `/api/backup/upload-hook` with header `X-Backup-Secret`. The existing
  `sftp-backups:sweep` archives `/srv/backups` → Azure Blob (`azure_backups` disk).
- LE deploy-hook must `systemctl restart sftpgo` on cert renewal (FTPS PEMs).

### FreeRADIUS — MAC-auth / NAS (`deployment/freeradius/`)
- `sudo apt install freeradius freeradius-mysql`. Config under `/etc/freeradius/3.0/`.
- Reads NAS clients + MAB policy from **`phonebook2`** via `rlm_sql`.
- Wrapper `/usr/local/bin/sg-radius-control` + `/etc/sudoers.d/sg-radius` for app-driven reload.
- **Ports:** **1812/udp** (auth), **1813/udp** (accounting).

### Browser portal (Neko) — `deployment/browser-portal/`
- `bootstrap-vps.sh`: Docker network `browser-net` (172.30.0.0/16), kill-switch script +
  `browser-portal-killswitch.service` (iptables egress ACL), `/etc/browser-portal/allowed-subnets.conf`.
- Needs **Docker**. Neko image auto-pulls on first session. Per-session nginx snippet include
  (see `DEPLOY-STEP5.md`). VPN egress per office rides the strongSwan tunnels.

### Graylog — log aggregation (`deployment/graylog/`, Docker Compose)
- `docker compose up -d`: MongoDB + OpenSearch + Graylog. Web **127.0.0.1:9000** (nginx-proxied).
- Syslog/GELF inputs; ingests via webhook to app `/api/graylog/webhook`.
- `.env` (not in git): `GRAYLOG_PASSWORD_SECRET`, `GRAYLOG_ROOT_PASSWORD_SHA2`.

### Metrics — VictoriaMetrics + Grafana (`deployment/metrics/`, Docker Compose)
- VictoriaMetrics **127.0.0.1:8428** (+ tunnel IP for branch `remote_write`), Grafana
  **127.0.0.1:3000** (nginx-proxied). `.env`: `GRAFANA_ADMIN_PASSWORD`, `VM_TUNNEL_IP`.

### rsyslog — `deployment/rsyslog/`
- UDP/TCP **514** → writes directly to MySQL `syslog_messages` (and/or forwards to Graylog).

### strongSwan — branch VPN hub (repo root + `deployment/`)
- Connection configs are committed at repo root: **`JED.conf`, `RYD.conf`** (swanctl format) →
  `/etc/swanctl/conf.d/` (`chmod 600`), then `swanctl --load-all`.
- **Run ONLY `charon-systemd` (`strongswan.service`).** The `strongswan` metapackage also pulls
  the legacy `strongswan-starter` daemon — if both run they fight over UDP 500/4500 and the loser
  logs `no socket implementation registered`, leaving tunnels stuck CONNECTING (config in one
  charon, sockets in the other). `systemctl disable --now strongswan-starter` + `ipsec stop`.
- ⚠️ **Only JED + RYD are in git.** The other ~7 branch tunnels lived on the wiped disk —
  recreate each from `JED.conf` (change `remote_addrs`, IDs, `remote_ts`, `secret`).
- Control wrapper `sg-vpn-control.sh` → `/usr/local/sbin/` + `/etc/sudoers.d/sg-vpn-control`
  (php-fpm user). App buttons call `status|up|down|reload|logs`.
- 🔒 **PSK is committed inside JED.conf/RYD.conf — rotate it and keep the live secret only on
  the box.** And **NEVER add a `0.0.0.0/0` child SA to an existing IKE conn** (Sophos widens
  narrow children on rekey → hijacks all VPS egress).

### Branch VM (`deployment/branch-vm/`)
- Ansible playbooks + Telegraf/SNMP/nmap collectors provisioned **on each branch VM**, not the
  NOC. Pushes metrics (`remote_write`) and logs to the NOC. See its README.

---

## 5. Ports / firewall

**Ingress (open in Azure NSG + host):**

| Port | Proto | Service |
|---|---|---|
| 22 | tcp | SSH |
| 80, 443 | tcp | nginx (HTTP→HTTPS, app, `/ws/telnet`, Graylog/Grafana proxies) |
| 514 | udp/tcp | rsyslog |
| 1812, 1813 | udp | FreeRADIUS auth / accounting |
| 2022 | tcp | SFTPGo SFTP |
| 2121 + 50000–50100 | tcp | SFTPGo FTPS control + passive |
| **5099** | **udp** | **VQ collector (phone vq-rtcpxr)** |

**Loopback only (via nginx or SSH tunnel — do NOT expose):** 8765 (telnet proxy), 8090
(SFTPGo REST), 9000 (Graylog), 3000 (Grafana), 8428 (VictoriaMetrics), 9200/27017 (Graylog
internal). **Egress:** 443 (LE/AWS/Azure/APIs), 25/465/587 (SMTP/SES), 53, 123, 161 (SNMP poll),
plus ESP/IKE (UDP 500/4500) for the VPN tunnels.

---

## 6. Sudoers / systemd / filesystem

**Sudoers (`/etc/sudoers.d/`, NOPASSWD, scoped, `visudo -c` validated):**
- `sg-vpn-control` → php-fpm user may run `/usr/local/bin/sg-vpn-control *` (wrapper path is hardcoded in `VpnControlService` — NOT `/usr/local/sbin`, NOT `.sh`)
- `sg-radius` → app user may run `sg-radius-control reload-clients|restart`
- `sg-cups` → app user may run the `lpadmin`/`cups*`/`lp`/`cancel` set (create if CUPS is app-managed)
- app user in the **`docker`** group (browser portal)

**Systemd units to enable:** `nginx`, `php8.3-fpm`, `mariadb`, `strongswan`, `cups`,
`freeradius`, `sftpgo`, `docker`, `browser-portal-killswitch`, `supervisor`.

**Filesystem:**
- `/srv/backups` → `sftpgo:sftpgo 750` + default ACL `u:azureuser:rwX`
- `/etc/swanctl/conf.d/*.conf` → `root:root 600` (PSKs inside). **The VPN Hub UI writes per-tunnel `.conf` files directly as the php-fpm user**, so that user needs write on the dir — grant via ACL: `setfacl -m u:<php-fpm-user>:rwx /etc/swanctl/conf.d` + `setfacl -d -m …` for new files. Without it the UI throws "Failed to save swanctl configuration file."
- `storage/`, `bootstrap/cache/` → app-writable; run `php artisan storage:link`
- CUPS TLS: `/etc/cups/ssl/<hostname>.{crt,key}` symlinked to the LE cert
  (**CUPS keys off the machine hostname, not ServerName** — don't rename the host).

---

## 7. Required `.env` keys (by subsystem)

> Auto-extracted from `config/*.php` — **cross-check exact names** against each config file
> before relying on them. `.env.example` is gitignored, so this list IS the reference.

**Core:** `APP_KEY APP_URL APP_ENV=production APP_DEBUG=false` ·
`DB_CONNECTION=mysql DB_HOST DB_PORT DB_DATABASE=phonebook2 DB_USERNAME DB_PASSWORD` ·
`SESSION_DRIVER=database CACHE_STORE=database QUEUE_CONNECTION=database`

**Mail (AWS SES smart-host):** `MAIL_MAILER MAIL_HOST MAIL_PORT MAIL_USERNAME MAIL_PASSWORD
MAIL_FROM_ADDRESS MAIL_FROM_NAME` · SES API: `AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY
AWS_DEFAULT_REGION`

**Azure Blob (backups):** `AZURE_BLOB_ACCOUNT AZURE_BLOB_KEY AZURE_BLOB_CONTAINER` (the
`azure_backups` disk in `config/filesystems.php`)

**SFTP backup sweep (`config/sftp_backup.php`):** `SFTP_BACKUP_INBOX=/srv/backups
SFTP_BACKUP_DISK=azure_backups SFTP_BACKUP_RETENTION_DAYS` · SFTPGo webhook:
`SFTPGO_WEBHOOK_SECRET` (also set in SFTPGo's upload action)

**Telnet proxy (`config/telnet.php`):** `TELNET_INTERNAL_SECRET` (**must match** the PM2 env),
`TELNET_WS_URL`, `TELNET_TOKEN_TTL`

**VPN (`config/vpn.php`):** `VPN_LOCAL_ID` / local subnet (the wrapper path is fixed)

**RADIUS (`config/radius.php`):** `RADIUS_CONTROL_SCRIPT=/usr/local/bin/sg-radius-control`

**ACME (`config/acme.php`):** `ACME_EMAIL ACME_STAGING`

**Branch collectors (`config/branches.php`):** `BRANCH_LOGS_TIMEOUT BRANCH_LOGS_VERIFY_TLS`

**Integrations (`config/services.php`):**
- Microsoft SSO/Graph: `sso_client_id/secret/tenant` + Graph creds (stored in `Setting`, not env)
- GDMS (UCM): `GDMS_BASE_URL GDMS_CLIENT_ID GDMS_CLIENT_SECRET GDMS_ORG_ID` — password is the
  **double-hash `sha256(md5(plaintext))`** stored in `settings.gdms_password_hash`
- Graylog: `GRAYLOG_URL GRAYLOG_WEBHOOK_SECRET`
- Grafana: `GRAFANA_URL`
- HR webhook: `HR_API_KEY`
- (others present: Slack, Teamtailor, Sophos, Meraki — set the ones you use)

---

## 8. Deploy / recovery order

1. Base install (§0), MySQL DB + user, clone to `/home/azureuser/phonebook2`.
2. `composer install --no-dev --optimize-autoloader`, build assets (`npm ci && npm run build`).
3. Write `.env` (§7), `php artisan key:generate`, `php artisan migrate --force`, `storage:link`.
4. nginx vhost + `certbot` (LE cert for `noc.samirgroup.net`).
5. **`sudo bash deployment/rebuild-side-services.sh`** — CUPS+TLS, telnet proxy (PM2),
   browser portal, SFTPGo (if binary present), strongSwan (loads JED/RYD), supervisor
   (scheduler + vq-collector). Then action its printed **MANUAL FOLLOW-UPS**.
6. Manual: nginx `/ws/telnet` location block; recreate the 7 missing VPN tunnels; open UDP 5099;
   Graylog + metrics `docker compose up -d`; FreeRADIUS config; re-enter integration secrets in
   Admin → Settings.
7. Verify: `systemctl status nginx php8.3-fpm mariadb strongswan cups freeradius sftpgo` ·
   `pm2 status` · `supervisorctl status` · `swanctl --list-sas` · `docker network ls` ·
   `ss -lntup | grep -E ':(443|8765|5099|2022|1812)'`.

---

_Last swept: 2026-06-07. Update whenever a new binary/daemon/service/env key is introduced._
