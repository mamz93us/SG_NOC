# SG_NOC Branch VM

Per-office log collector + query API. Each branch runs its own VM that
collects syslog from local devices (firewall, switches, APs, UCM, phones),
stores them in a local MariaDB with date-partitioned tables, and exposes an
HTTP API the central NOC queries.

```
┌── JED branch VM ────────────────────────────────────┐
│  rsyslog :514  ──► /var/spool/sg-noc-ingest/*.jsonl │
│                          │                          │
│                          ▼                          │
│  PHP ingester (systemd) ──► MariaDB syslog_messages │
│                                       ▲             │
│  nginx :8514  ──► PHP-FPM ──► API ────┘             │
└────────────────┬────────────────────────────────────┘
                 │  HTTPS over IPsec
                 ▼
┌── NOC Laravel (noc.samirgroup.net) ─────────────────┐
│  /admin/logs/branches  ──► BranchLogClient ──► …    │
│      (multi-branch fan-out, merged results)         │
└─────────────────────────────────────────────────────┘
```

## What this gives you

- **All logs stay at the branch** — no WAN bulk transfer
- **Single UI at NOC** — search any branch from the existing Laravel admin
- **60-day default retention per branch** — adjustable, partition-based (instant rollover)
- **Indexed structured search** — query by timestamp, source, severity, Sophos fields
- **Bearer-token auth** between NOC and each branch
- **Fleet update** — git pull on every branch via Ansible, or per-VM `update.sh`

## Quick install (single fresh Ubuntu 24.04 server)

```bash
# 1. Get a fresh Ubuntu 24.04.4 server, SSH in as a sudoer
ssh azureuser@jed-noc.local

# 2. Clone this repo to /opt/sg-noc
sudo mkdir -p /opt && sudo chown azureuser:azureuser /opt
git clone https://github.com/mamz93us/SG_NOC.git /opt/sg-noc

# 3. Configure this branch's identity + secrets
sudo cp /opt/sg-noc/deployment/branch-vm/.env.example /etc/sg-noc-branch.env
sudo nano /etc/sg-noc-branch.env
# Set BRANCH_ID (e.g. jed), DB_PASSWORD (random), API_TOKEN (random),
# RETENTION_DAYS (default 60), and TIMEZONE.

# 4. Run the installer
cd /opt/sg-noc/deployment/branch-vm
sudo bash install.sh
```

The installer is idempotent — re-running it won't break anything.

## Updating one VM

```bash
cd /opt/sg-noc
sudo bash deployment/branch-vm/update.sh
```

Pulls latest from git, reapplies configs, runs any new schema migrations,
restarts services.

## Updating all 9 VMs at once (Ansible)

```bash
# On your laptop or the NOC VM
cd /opt/sg-noc/deployment/branch-vm/ansible
cp inventory.yml.example inventory.yml   # edit with each branch's hostname/IP
ansible-playbook -i inventory.yml update-all.yml
```

See `ansible/README.md` for inventory and SSH key setup.

## Files in this directory

```
deployment/branch-vm/
├── README.md                  ← you are here
├── install.sh                 ← run once on fresh Ubuntu 24.04
├── update.sh                  ← run on each git pull
├── .env.example               ← template for /etc/sg-noc-branch.env
├── rsyslog/
│   └── 10-branch-collector.conf
├── mariadb/
│   ├── schema.sql             ← initial schema + partitions
│   └── partition-rotate.sh    ← daily cron: create tomorrow, drop old
├── ingester/
│   └── ingester.php           ← reads JSONL, inserts to MariaDB
├── api/
│   ├── public/index.php       ← search + aggregate endpoints
│   ├── lib/                   ← shared library files
│   └── nginx-site.conf        ← reverse proxy + rate limiting
├── systemd/                   ← service unit files
│   ├── sg-noc-ingester.service
│   ├── sg-noc-partition-rotate.service
│   └── sg-noc-partition-rotate.timer
└── ansible/
    ├── inventory.yml.example
    ├── update-all.yml
    └── install-branch.yml     ← bootstrap a fresh VM remotely
```

## Operational notes

- **Logs**: ingester logs go to `journalctl -u sg-noc-ingester`. nginx
  access logs are at `/var/log/nginx/sg-noc-access.log`.
- **Backups**: MariaDB stores under `/var/lib/mysql`. The `mariadb-backup`
  package is installed; configure your offsite backup of choice.
- **Disk pressure**: partition rotation drops the oldest partition each
  night, capping disk use. Tune `RETENTION_DAYS` in `/etc/sg-noc-branch.env`
  if you need more or less.
- **Firewall**: `install.sh` opens UDP/TCP 514 (syslog from devices) and
  TCP 8514 (NOC query API). The API is **only reachable from the NOC's
  IPsec-tunnel-side IP** by default — see `api/nginx-site.conf`.

## NOC side wiring

Once one branch is up:

1. Run the migration once on the NOC:
   ```bash
   php artisan migrate
   ```
2. Open https://noc.samirgroup.net → **NOC dropdown → Branch Log Collectors**
3. Click **Add branch**, fill in:
   - Branch code (e.g. `jed` — must match `BRANCH_ID` on the VM)
   - Display name
   - Host (tunnel-side IP of the branch VM)
   - Port (8514 default)
   - API token — paste the `API_TOKEN` printed by `install.sh` on the VM
4. Save → click **Test** on the list page. Should turn green ("healthy").
5. Visit **Branch Logs** in the navbar — your pilot branch is now
   queryable from the search UI.

No `.env` editing or `config/branches.php` changes are needed any more —
all branch endpoints live in the `branch_log_collectors` table, managed
through the UI.
