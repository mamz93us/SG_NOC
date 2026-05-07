# SG_NOC — Metrics stack

VictoriaMetrics + Grafana on the NOC. Receives Prometheus `remote_write`
batches from every branch's Telegraf and renders dashboards.

## Why this stack

- **VictoriaMetrics** — single Go binary, drop-in Prometheus-compatible
  TSDB. ~10× compression, sub-byte storage per data point. ~2 GB/year for
  9 branches × ~50 metrics × 1-min poll. Way smaller than your logs.
- **Grafana** — industry-standard dashboards and alert UI. Runs alongside.
- No Prometheus, no Alertmanager — VictoriaMetrics replaces both.

## Resource budget on the NOC VM

| Service          | RAM     | Disk        |
|------------------|---------|-------------|
| VictoriaMetrics  | ~500 MB | ~2 GB / year (auto-compressed) |
| Grafana          | ~200 MB | small       |
| **Total**        | **~700 MB** | **~2 GB**  |

Comfortably co-exists with Graylog + Laravel on a single 8-16 GB NOC VM.

## Install

```bash
cd /opt/sg-noc/deployment/metrics
cp .env.example .env
nano .env                 # fill VM_AUTH_PASSWORD, GRAFANA_ADMIN_PASSWORD
docker compose up -d
docker compose ps         # both containers should report "healthy"
```

## nginx (HTTPS for Grafana)

```nginx
server {
    listen 80;
    server_name metrics.samirgroup.net;
    location /.well-known/acme-challenge/ { root /var/www/letsencrypt; }
    location / { return 301 https://$host$request_uri; }
}

server {
    listen 443 ssl http2;
    server_name metrics.samirgroup.net;
    ssl_certificate     /etc/letsencrypt/live/metrics.samirgroup.net/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/metrics.samirgroup.net/privkey.pem;
    client_max_body_size 5M;

    # Grafana web UI
    location / {
        proxy_pass http://127.0.0.1:3000;
        proxy_set_header Host              $host;
        proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_http_version 1.1;
        proxy_set_header Upgrade           $http_upgrade;       # for live tail
        proxy_set_header Connection        "upgrade";
    }

    # Branch Telegraf push endpoint. ONLY allow from IPsec CIDR; basic auth
    # already enforced by VictoriaMetrics itself but defence-in-depth.
    location /api/v1/write {
        allow 10.0.0.0/8;            # ← change to your real IPsec CIDR
        deny  all;
        proxy_pass http://127.0.0.1:8428;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        client_max_body_size 32M;
    }
}
```

```bash
sudo certbot certonly --nginx -d metrics.samirgroup.net
sudo nginx -t && sudo systemctl reload nginx
```

## Branch Telegraf points to:
```
https://metrics.samirgroup.net/api/v1/write
  basic_auth: $VM_AUTH_USER : $VM_AUTH_PASSWORD
```

(see `deployment/branch-vm/telegraf/templates/_remote_write.toml` —
Phase 2 will populate this).

## Backup

`vm_data` and `grafana_data` Docker volumes — back them up however you
back up the rest of `/var/lib/docker/volumes/`. Grafana dashboards live
read-only at `deployment/metrics/grafana/dashboards/` (in this repo) so
they're already in git.

## Day-to-day

```bash
docker compose logs -f grafana             # tail Grafana logs
docker compose logs -f victoriametrics     # tail VM logs
docker compose ps                          # health
docker compose pull && docker compose up -d  # upgrade
```

## Capacity check

```bash
# Disk used by VictoriaMetrics
docker exec victoriametrics du -sh /storage

# Active series count (a "series" is one combination of metric + labels)
docker exec victoriametrics wget -qO- \
    "http://${VM_AUTH_USER}:${VM_AUTH_PASSWORD}@localhost:8428/api/v1/series/count"

# Datapoint ingest rate (per second)
docker exec victoriametrics wget -qO- \
    "http://${VM_AUTH_USER}:${VM_AUTH_PASSWORD}@localhost:8428/api/v1/query?query=rate(vm_rows_inserted_total%5B1m%5D)"
```

## Sanity test from the host

```bash
. .env
curl -u "$VM_AUTH_USER:$VM_AUTH_PASSWORD" http://127.0.0.1:8428/api/v1/labels
# → {"status":"success","data":["__name__","instance","job",...]}
```

## What's next (Phase 2)

`deployment/branch-vm/install.sh` will install Telegraf at every branch
and have it remote-write to `https://metrics.samirgroup.net/api/v1/write`
using the credentials above. Phase 3 ships pre-built Grafana dashboards
under `deployment/metrics/grafana/dashboards/` (one per device class:
Sophos, switch, AP, UCM).
