# Syslog Server — VPS Setup

This is a one-time procedure on the production VPS. Edit-locally → commit
→ push → `git pull` on the VPS, then run the steps below over SSH (only
the rsyslog/systemd parts need root).

## Architecture

```
[devices] --udp/tcp 514--> [rsyslog]  --ommysql-->  [MySQL: syslog_messages]
                              ^                                  ^
                              |                                  |
                       template+ruleset                Laravel UI / jobs:
                       (50-sg-noc-syslog.conf,         - TagSyslogSourcesJob
                        90-...secret.conf)              - MatchSyslogAlertsJob
                                                        - PruneOldSyslogJob
```

The web app never opens UDP/TCP 514. rsyslog is the only listener; it
inserts directly into MySQL using a least-privilege user.

## 1. Run the Laravel migrations

```bash
cd /home/azureuser/phonebook2   # or wherever the app lives
php artisan migrate
```

This creates `syslog_messages`, `syslog_alert_rules`, and adds the
`view-syslog` / `manage-syslog` permissions to existing roles.

## 2. Create the rsyslog MySQL user

Edit `deployment/rsyslog/setup.sql` and pick a strong password (replace
`REPLACE_ME`). Then load it as a MySQL admin:

```bash
mysql -u root -p < deployment/rsyslog/setup.sql
```

The user gets `INSERT` only on `syslog_messages` — nothing else.

## 3. Install the rsyslog ommysql plugin

On Debian/Ubuntu:

```bash
sudo apt-get install -y rsyslog-mysql
```

(Press *No* when the package installer offers to dbconfig-common a
schema — we already created the table via Laravel.)

## 4. Drop the rsyslog config in place

```bash
sudo cp deployment/rsyslog/50-sg-noc-syslog.conf /etc/rsyslog.d/

sudo cp deployment/rsyslog/90-sg-noc-syslog-secret.conf.example \
        /etc/rsyslog.d/90-sg-noc-syslog-secret.conf
sudo chown root:root /etc/rsyslog.d/90-sg-noc-syslog-secret.conf
sudo chmod 600       /etc/rsyslog.d/90-sg-noc-syslog-secret.conf

# Edit the secret file and set the password to match setup.sql.
sudo nano /etc/rsyslog.d/90-sg-noc-syslog-secret.conf
```

Validate the config and restart:

```bash
sudo rsyslogd -N1
sudo systemctl restart rsyslog
sudo systemctl status  rsyslog --no-pager
```

You should see the daemon binding UDP/TCP on port 514.

## 5. Open the firewall

The VPS already has tunnels to each office; restrict 514 to those
subnets (don't expose to the internet — UDP 514 has no auth):

```bash
# Allow each office subnet (adjust per your IPsec child subnets)
sudo ufw allow from 10.10.0.0/16 to any port 514 proto udp
sudo ufw allow from 10.10.0.0/16 to any port 514 proto tcp
# repeat for each office subnet…

# Or, more concisely if all offices share a /8:
# sudo ufw allow from 10.0.0.0/8 to any port 514 proto udp
# sudo ufw allow from 10.0.0.0/8 to any port 514 proto tcp
```

## 6. Smoke test

From the VPS itself:

```bash
logger -n 127.0.0.1 -P 514 -d --rfc3164 -t syslog-smoke "hello from VPS"
```

Then check the table:

```bash
mysql -u root -p sg_noc -e \
  "SELECT id, received_at, host, severity, program, message
   FROM syslog_messages ORDER BY id DESC LIMIT 5;"
```

You should see the row. Open `https://<noc-host>/admin/syslog` — it
shows up in the table and in Live Tail.

## 7. Configure devices to forward

Each device's syslog destination = the VPS's tunnel-side IP, port 514.

| Device          | Where to set it                                     |
|-----------------|------------------------------------------------------|
| Sophos firewall | System → System Services → Log Settings → Syslog    |
| Cisco / IOS     | `logging host <vps-ip>` + `logging trap informational` |
| Grandstream UCM | System Settings → Syslog → External Syslog Server   |
| Network printers| Web UI → Network → Syslog (varies per vendor)       |
| The VPS itself  | Add `*.* @127.0.0.1:514` to `/etc/rsyslog.conf` (or use `omfwd` to keep it self-contained) |

## 8. Verify the tagger and alert matcher

The scheduler runs `TagSyslogSourcesJob` and `MatchSyslogAlertsJob`
every minute. To force a run before waiting:

```
NOC → Syslog → Alert Rules → "Run now" button
```

(or hit `POST /admin/syslog/run-processors` if you've scripted it).

## Settings (optional)

`Setting::syslog_retention_days` controls how long rows live before
`PruneOldSyslogJob` deletes them. Default is 30. Add it to the Settings
form under Admin → Settings if you want a UI knob; otherwise just
update the row directly:

```sql
UPDATE settings SET syslog_retention_days = 60 LIMIT 1;
```

## Troubleshooting

* **No rows arriving** — `tail -f /var/log/syslog` on the VPS while
  pinging the device. If you see `ommysql: SQL error 1064` the column
  list in `50-sg-noc-syslog.conf` and the migration drifted; recheck
  both. If you see `Connection refused (1045)` the password in
  `90-sg-noc-syslog-secret.conf` doesn't match what `setup.sql` set.
* **Rows arrive but `source_type` stays NULL** — the sender's IP isn't
  in any inventory table. Add it to `monitored_hosts` (or the relevant
  device table) and click *Run now* on the rules page.
* **rsyslog won't restart** — `sudo rsyslogd -N1` prints the parse
  error and line number.
* **Disk pressure on `/var/spool/rsyslog`** — `queue.maxdiskspace="500m"`
  in the secret file caps the disk-assisted queue used when MySQL is
  unavailable. Bump if you need a longer outage window.
