# Graylog Open — Production Setup

This guide stands up Graylog Open as the primary log store for SG_NOC,
keeping the existing rsyslog → MySQL pipeline running in parallel until
you've verified Graylog covers your needs. After cutover, MySQL only
holds alert state; Graylog holds the messages.

## Architecture (Level 1 — link out + webhook back)

```
                    ┌──────────────┐
9 firewalls ──┐     │              │
9 UCMs       ─┼─►  rsyslog ──┬──► MySQL (existing, can be turned off later)
500 phones    ┘     │ on VPS │
                    │        └──► Graylog :1514  ──► OpenSearch (logs)
                    │                              └► MongoDB     (config)
                    │
                    │ web UI on :9000 (proxied via nginx)
                    │ alert webhook → Laravel /api/graylog/webhook
                    ▼
              ┌─────────────────┐
              │  Laravel NOC    │
              │  noc.samirgroup │
              │  navbar "Logs"  │── link out → Graylog
              └─────────────────┘
```

## Resource sizing (10 M messages/day target)

| Component  | RAM    | Disk          | Notes                                        |
|------------|--------|---------------|----------------------------------------------|
| Graylog    | 2 GB   | 5 GB          | App; JVM heap 1 GB                           |
| OpenSearch | 5–6 GB | ~150 GB/month | JVM heap 2 GB; rest is OS page cache         |
| MongoDB    | 0.5 GB | 1 GB          | Config metadata only                         |
| **Total**  | **~8 GB** | **160 GB/30d** | One Standard_B2ms or larger VPS handles this |

If your current SG-NOC VPS has < 16 GB total, provision a separate VM for
Graylog and forward over the existing IPsec mesh — don't crowd the NOC
app.

## 1. Prerequisites on the VPS

```bash
# Docker + compose plugin (skip if already installed)
sudo apt-get install -y docker.io docker-compose-plugin
sudo usermod -aG docker azureuser   # log out / in afterwards

# Verify
docker --version && docker compose version

# Increase max_map_count for OpenSearch
echo 'vm.max_map_count=262144' | sudo tee /etc/sysctl.d/99-graylog.conf
sudo sysctl --system
```

## 2. Configure secrets

```bash
cd /home/azureuser/phonebook2/deployment/graylog

cp .env.example .env

# Generate password secret (96 random chars)
PWS=$(openssl rand -base64 72 | tr -d '\n=' | head -c 96)

# Pick an admin password and SHA-256 it
read -rsp "Admin password for Graylog: " ADMINPW && echo
ADMINSHA=$(echo -n "$ADMINPW" | sha256sum | awk '{print $1}')

# Write into .env (overwrites placeholders)
sed -i "s|^GRAYLOG_PASSWORD_SECRET=.*|GRAYLOG_PASSWORD_SECRET=$PWS|"        .env
sed -i "s|^GRAYLOG_ROOT_PASSWORD_SHA2=.*|GRAYLOG_ROOT_PASSWORD_SHA2=$ADMINSHA|" .env
sed -i "s|^GRAYLOG_HTTP_EXTERNAL_URI=.*|GRAYLOG_HTTP_EXTERNAL_URI=https://logs.samirgroup.net/|" .env

unset ADMINPW PWS ADMINSHA
chmod 600 .env
```

## 3. Bring the stack up

```bash
cd /home/azureuser/phonebook2/deployment/graylog
docker compose up -d

# Watch startup (Graylog takes ~90s to become healthy first time)
docker compose ps
docker compose logs -f graylog | grep -i "server up\|listening\|http server"
```

When you see `Server up and running …` you can hit `http://127.0.0.1:9000`
from the VPS. Don't expose port 9000 publicly — nginx will proxy.

## 4. nginx reverse proxy + Let's Encrypt

Create `/etc/nginx/sites-available/logs.samirgroup.net`:

```nginx
server {
    listen 80;
    server_name logs.samirgroup.net;
    location /.well-known/acme-challenge/ { root /var/www/letsencrypt; }
    location / { return 301 https://$host$request_uri; }
}

server {
    listen 443 ssl http2;
    server_name logs.samirgroup.net;

    ssl_certificate     /etc/letsencrypt/live/logs.samirgroup.net/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/logs.samirgroup.net/privkey.pem;

    # Graylog generates large query payloads; lift the default cap.
    client_max_body_size 10M;

    location / {
        proxy_pass http://127.0.0.1:9000;
        proxy_http_version 1.1;
        proxy_set_header Host              $host;
        proxy_set_header X-Forwarded-Host  $host;
        proxy_set_header X-Forwarded-Server $host;
        proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Graylog-Server-URL https://$host/;
        proxy_read_timeout 60s;
    }
}
```

Then:

```bash
sudo ln -s /etc/nginx/sites-available/logs.samirgroup.net /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo certbot certonly --nginx -d logs.samirgroup.net
sudo systemctl reload nginx
```

Point the DNS A record `logs.samirgroup.net` at your VPS public IP first.

Browse to `https://logs.samirgroup.net` → log in as `admin` with the
password you set.

## 5. Wire rsyslog to forward to Graylog

```bash
sudo cp deployment/rsyslog/60-graylog-forwarder.conf /etc/rsyslog.d/

# Refresh 50-sg-noc-syslog.conf so its sgNocRemote ruleset now also
# calls graylogForward (already done in the repo — just copy):
sudo cp deployment/rsyslog/50-sg-noc-syslog.conf /etc/rsyslog.d/

sudo rsyslogd -N1 && sudo systemctl restart rsyslog
```

Both MySQL writes AND Graylog forwarding are now active. Once you're
happy with Graylog, comment out the `call sgNocSyslogAction` line in
`50-sg-noc-syslog.conf` to stop writing to MySQL.

## 6. Configure the Graylog Syslog input

In the Graylog UI:

1. **System → Inputs**
2. Select *Syslog UDP* → **Launch new input**
3. Settings:
   - Title: `SG-NOC syslog`
   - Bind address: `0.0.0.0`
   - Port: `1514`
   - Allow override date: ☑
   - Store full message: ☑
4. Save. Repeat for *Syslog TCP* on port 1514 if you want both.

Within ~10s the input shows green and message count starts climbing.

## 7. Build extractors / pipeline rules

Two ways — quick (extractors) and proper (pipelines). Start with
extractors, migrate to pipelines if you outgrow them.

### Sophos KV extractor (built-in)

1. **System → Inputs** → on your Syslog input click **Manage extractors**
2. **Get started** → paste any Sophos message → **Load message**
3. **Select extractor type**: *Key = Value Pairs*
4. *Source field*: `message`
5. *Configuration*:
   - Split by: ` ` (space)
   - K/V split: `=`
   - Trim spaces in values: ☑
   - Trim quote chars: `"` (double-quote)
6. **Save extractor** → name it `Sophos KV`

Re-check the input after a few seconds — the next batch of Sophos
messages will have `device_name`, `log_type`, `log_component`,
`fw_rule_id`, `src_ip`, `dst_ip`, `src_port`, `dst_port`, etc. as
indexed fields.

### Asterisk regex extractor

1. **Manage extractors** → **Add extractor for field**: `message`
2. Type: *Regular expression*
3. Pattern (matches the asterisk-core shape):
   ```
   \[(?<host_id>[A-Fa-f0-9:]+)\]\s+(?<program>\w+)\[(?<pid>\d+)\]:\s+(?<asterisk_severity>[A-Z]+)\[(?<task_id>\d+)\](?:\[C-(?<call_id>[A-Fa-f0-9]+)\])?:\s+(?<file>[^:\s]+):(?<line>\d+)\s+in\s+(?<function>\w+):\s*(?<text>.*)
   ```
4. Target: copy each named group into a new field
5. Condition: *Only attempt if field matches regex* `asterisk\[`
6. Save as `Asterisk core`

Repeat with the GS_AVS pattern (`GS_AVS:.*\[ ([A-Z]+) \].*\(([^:]+):(\d+)\):`) for Grandstream subsystems if you want them broken out.

### Streams (route by source)

**Streams → Create Stream**:

| Stream name      | Rule                                              |
|------------------|---------------------------------------------------|
| Sophos firewalls | `source` matches regex `(JED|RYD|...)-FW`         |
|                  | OR `gl2_source_input_ip` in the firewall subnet   |
| UCM servers      | `program` = `asterisk`                            |
| Phones           | `source` matches regex `^GXP|^GRP|^WP`            |

Saving these pre-filters makes searches and dashboards instant.

## 8. Build the alert pipeline (Event Definitions)

For each rule:

**Alerts → Event Definitions → Create Event Definition** (Aggregation
type for rate-based, Filter type for single-event). Examples:

- **Sophos: 5+ denies in 5 min from one host**
  - Filter type: *Filter & Aggregation*
  - Search query: `log_subtype:Denied`
  - Streams: Sophos firewalls
  - Aggregation: count() grouped by `src_ip` `host`
  - Condition: `count >= 5`
  - Time range: 5 min

- **UCM SECURITY events**
  - Filter type: *Filter*
  - Search query: `asterisk_severity:SECURITY`
  - Streams: UCM
  - Time range: 1 min

- **Phone offline (no register in 10 min)**
  - more involved; defer until traffic patterns are clear

For each, attach an **HTTP Notification** that posts to:
`https://noc.samirgroup.net/api/graylog/webhook`
with header `X-Graylog-Secret: <your secret>`.

Set the secret on both sides:

```bash
# Pick one
read -rsp "Graylog webhook secret: " GLSEC && echo

# Add to Laravel .env (and reload)
echo "GRAYLOG_URL=https://logs.samirgroup.net" >> /home/azureuser/phonebook2/.env
echo "GRAYLOG_WEBHOOK_SECRET=$GLSEC"            >> /home/azureuser/phonebook2/.env

# Reload Laravel config + clear views so the navbar 'Logs' link appears
cd /home/azureuser/phonebook2
php artisan config:clear
php artisan view:clear

unset GLSEC
```

Then in Graylog:
- **Alerts → Notifications → Create**
- Type: HTTP Notification
- URL: `https://noc.samirgroup.net/api/graylog/webhook`
- Custom headers: `X-Graylog-Secret: <same secret>`
- Method: POST
- Test it once — you should see a `NocEvent` row appear in your alerts feed.

## 9. Sanity tests

```bash
# 1. Confirm Graylog is receiving messages
# In Graylog UI: Search → time range "Last 5 minutes" → query "*"
# You should see rsyslog-forwarded messages.

# 2. Confirm Sophos extractor is populating fields
# Search: source_type:sophos AND log_subtype:Denied
# (after first dashboard pass)

# 3. Confirm webhook reaches Laravel
curl -X POST https://noc.samirgroup.net/api/graylog/webhook \
  -H "Content-Type: application/json" \
  -H "X-Graylog-Secret: <your secret>" \
  -d '{
    "event_definition_title":"Test alert",
    "event_definition_id":"test-id-001",
    "event": {
      "id":"01-test","priority":3,"alert":true,
      "message":"Synthetic test event",
      "timestamp":"2026-04-29T12:00:00.000Z",
      "key":"test-host","group_by_fields":{"host":"test-host"}
    }
  }'
# Should return {"ok":true,"noc_event_id":N,"mode":"created"}
# Check NOC → Alerts feed.
```

## 10. Cutover (when you're confident)

After ~1 week of dual-write, when Graylog has all of the data you'd want:

```bash
# Stop writing to MySQL
sudo sed -i 's|^    call sgNocSyslogAction|    # call sgNocSyslogAction (cut over to Graylog)|' \
    /etc/rsyslog.d/50-sg-noc-syslog.conf
sudo systemctl restart rsyslog

# Free up space — drop the syslog_messages table eventually
# (keep alert rules + NocEvent rows; those are still used)
# sudo mysql phonebook2 -e "DROP TABLE syslog_messages;"
```

The old `/admin/syslog`, `/admin/syslog/sophos`, `/admin/syslog/ucm`
pages remain functional for browsing the residual MySQL data, or you
can hide their navbar entries and drive everything through Graylog.

## Operational notes

- **Index rotation**: Graylog rotates indices daily by default. Configure
  retention under *System → Indices → "Default index set"* — set to
  delete after 30 days (or whatever your compliance window is).
- **Backups**: only MongoDB needs nightly backup (Graylog config). The
  log indices in OpenSearch can be re-ingested from rsyslog if needed.
- **Monitoring**: Graylog's own metrics live at *System → Overview*.
  Watch journal utilization and process-buffer load — both should sit
  below 50% in normal operation.
- **Upgrades**: bump the image tag in `docker-compose.yml`,
  `docker compose pull && docker compose up -d`. Read the Graylog
  release notes — major version bumps occasionally need an OpenSearch
  index reindex. See § 11 for the full upgrade procedure.

## 11. Upgrading Graylog (major version bumps, e.g. 6.x → 7.0)

Graylog migrates Mongo schemas on first boot of a new major; that
migration is irreversible. Always snapshot Mongo first so you have a
clean rollback path.

```bash
cd /home/azureuser/phonebook2/deployment/graylog

# 1. Snapshot Mongo (config + dashboards + alerts).
mkdir -p backups
docker exec graylog-mongo mongodump --archive --gzip > \
    backups/mongo-$(date +%F-%H%M).archive.gz
ls -lh backups/   # confirm a non-empty file landed

# 2. Note the OpenSearch + Mongo container versions you're running now,
#    in case you need to roll back.
docker compose ps
docker inspect graylog-opensearch --format '{{.Config.Image}}'
docker inspect graylog-mongo      --format '{{.Config.Image}}'
```

Do the upgrade itself with the repo as source of truth:

```bash
cd /home/azureuser/phonebook2
git pull                                    # picks up the new image tag

cd deployment/graylog
docker compose pull graylog                 # downloads the new image
docker compose up -d graylog                # recreates only the Graylog container

# Watch first-boot migrations — can take a few minutes.
docker compose logs -f graylog | grep -iE "migration|cluster|started|error"
# Ctrl-C once you see "Server up and running"
```

Verify post-upgrade:

1. UI loads at https://logs.samirgroup.net (you may need to log in again)
2. **System → Overview** shows the new version
3. **System → Inputs** — the Syslog UDP input is still green
4. Top-right `X in/s` rate is non-zero
5. **System → Indices** — your default index set is still listed and
   accepting writes (latest index has a recent `created_at`)

### Rollback (if the upgrade fails or the UI is stuck)

```bash
cd /home/azureuser/phonebook2/deployment/graylog

# 1. Stop Graylog (leave Mongo + OpenSearch running so we can restore).
docker compose stop graylog

# 2. Restore the Mongo snapshot (overwrites the migrated schema).
gunzip -c backups/mongo-<TIMESTAMP>.archive.gz | \
    docker exec -i graylog-mongo mongorestore --archive --drop

# 3. Pin docker-compose.yml back to the previous image tag, e.g.
#    image: graylog/graylog:6.1
sed -i 's|graylog/graylog:.*|graylog/graylog:6.1|' docker-compose.yml

# 4. Start the old version back up.
docker compose up -d graylog
```

OpenSearch indices themselves don't change format on a Graylog major,
so the messages already ingested under the old version remain readable
after rollback.

### Bumping MongoDB alongside Graylog

Graylog majors regularly raise the MongoDB minimum (Graylog 7.0 requires
Mongo 7.0). MongoDB only supports **one major version at a time** — so
6.0 → 7.0 is fine, but 4.4 → 7.0 needs intermediate hops (4.4 → 5.0 →
6.0 → 7.0), each step booting fully and the FCV bumped before moving
on.

For a single-major step, the data volume is forward-compatible — just
change the image tag and recreate:

```bash
cd /home/azureuser/phonebook2/deployment/graylog
docker compose pull mongo
docker compose up -d mongo
docker compose up -d graylog        # restart Graylog so its preflight passes

# Once Graylog is healthy, raise the Mongo feature-compatibility version
# so the new server can use 7.0-only features (queries, indexes, etc).
docker exec graylog-mongo mongosh --eval \
    'db.adminCommand({setFeatureCompatibilityVersion: "7.0", confirm: true})'
```
