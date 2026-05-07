#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────
# SG_NOC Branch VM updater — pulls the latest code from git, reapplies
# configs, runs schema migrations, restarts services. Safe to run from
# Ansible across all 9 branches simultaneously.
#
#   sudo bash /opt/sg-noc/deployment/branch-vm/update.sh
# ─────────────────────────────────────────────────────────────────────────
set -euo pipefail

REPO_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
SRC_DIR="$REPO_DIR/deployment/branch-vm"
ENV_FILE="/etc/sg-noc-branch.env"

red()  { printf '\033[31m%s\033[0m\n' "$*" >&2; }
green(){ printf '\033[32m%s\033[0m\n' "$*"; }
note() { printf '\033[36m▸ %s\033[0m\n' "$*"; }

[[ $EUID -eq 0 ]] || { red "Run with sudo."; exit 1; }
[[ -f $ENV_FILE ]] || { red "$ENV_FILE missing — run install.sh first."; exit 1; }

set -a; . "$ENV_FILE"; set +a

# 1. Pull latest code
note "git pull in $REPO_DIR"
git -C "$REPO_DIR" fetch --quiet origin
LOCAL=$(git -C "$REPO_DIR"  rev-parse HEAD)
REMOTE=$(git -C "$REPO_DIR" rev-parse @{u})
if [[ "$LOCAL" == "$REMOTE" ]]; then
    note "Already up-to-date — nothing to do."
    exit 0
fi
git -C "$REPO_DIR" pull --ff-only --quiet
green "Pulled $(git -C "$REPO_DIR" log --oneline "$LOCAL..HEAD" | wc -l) new commit(s)."

# 2. Re-render rsyslog config (BRANCH_ID may have changed)
note "Reapplying rsyslog config"
sed "s|__BRANCH_ID__|$BRANCH_ID|g" \
    "$SRC_DIR/rsyslog/10-branch-collector.conf" \
    > /etc/rsyslog.d/10-branch-collector.conf
rsyslogd -N1 -f /etc/rsyslog.d/10-branch-collector.conf
systemctl restart rsyslog

# 3. Apply any new schema migrations (uses IF NOT EXISTS internally)
note "Reapplying MariaDB schema (idempotent)"
mysql --protocol=socket -u root "$DB_NAME" < "$SRC_DIR/mariadb/schema.sql"

# 4. Refresh ingester + API code
note "Updating ingester"
install -m 0755 "$SRC_DIR/ingester/ingester.php" /opt/sg-noc-branch/ingester.php

note "Updating query API"
rsync -a --delete "$SRC_DIR/api/public/" /opt/sg-noc-branch/api/public/
rsync -a --delete "$SRC_DIR/api/lib/"    /opt/sg-noc-branch/api/lib/
chown -R www-data:www-data /opt/sg-noc-branch/api

# 5. Refresh systemd units in case they changed
note "Refreshing systemd units"
install -m 0644 "$SRC_DIR/systemd/sg-noc-ingester.service" /etc/systemd/system/
install -m 0644 "$SRC_DIR/systemd/sg-noc-partition-rotate.service" /etc/systemd/system/
install -m 0644 "$SRC_DIR/systemd/sg-noc-partition-rotate.timer"   /etc/systemd/system/
systemctl daemon-reload

# 6. Re-render nginx site (NOC_ALLOWED_CIDR may have changed)
note "Updating nginx site"
sed "s|__NOC_ALLOWED_CIDR__|$NOC_ALLOWED_CIDR|g" \
    "$SRC_DIR/api/nginx-site.conf" \
    > /etc/nginx/sites-available/sg-noc-api
nginx -t
systemctl reload nginx

# 7. Restart ingester (keeps queue position via state file)
note "Restarting ingester"
systemctl restart sg-noc-ingester

# 8. Telegraf — refresh templates + sync scripts + run sync
if [[ -d "$SRC_DIR/telegraf" ]]; then
    note "Refreshing Telegraf templates + sync scripts"
    install -d -o root -g root -m 0755 /opt/sg-noc-branch/telegraf/templates
    cp -a "$SRC_DIR/telegraf/templates/." /opt/sg-noc-branch/telegraf/templates/
    chmod 0644 /opt/sg-noc-branch/telegraf/templates/*

    install -m 0755 "$SRC_DIR/telegraf/snmp-sync.php"     /opt/sg-noc-branch/snmp-sync.php
    install -m 0755 "$SRC_DIR/telegraf/nmap-discover.php" /opt/sg-noc-branch/nmap-discover.php

    install -m 0644 "$SRC_DIR/systemd/sg-noc-snmp-sync.service"     /etc/systemd/system/
    install -m 0644 "$SRC_DIR/systemd/sg-noc-snmp-sync.timer"       /etc/systemd/system/
    install -m 0644 "$SRC_DIR/systemd/sg-noc-nmap-discover.service" /etc/systemd/system/
    install -m 0644 "$SRC_DIR/systemd/sg-noc-nmap-discover.timer"   /etc/systemd/system/
    systemctl daemon-reload

    # Re-render the global output config in case credentials changed in /etc/sg-noc-branch.env
    if [[ -n "${NOC_METRICS_URL:-}" && -n "${NOC_METRICS_USER:-}" \
           && -n "${NOC_METRICS_PASSWORD:-}" ]]; then
        sed -e "s|__BRANCH_ID__|$BRANCH_ID|g" \
            -e "s|__NOC_METRICS_URL__|${NOC_METRICS_URL//|/\\|}|g" \
            -e "s|__NOC_METRICS_USER__|$NOC_METRICS_USER|g" \
            -e "s|__NOC_METRICS_PASSWORD__|$NOC_METRICS_PASSWORD|g" \
            "$SRC_DIR/telegraf/templates/00-output.conf.tpl" \
            > /etc/telegraf/telegraf.d/00-output.conf
        chmod 0640 /etc/telegraf/telegraf.d/00-output.conf
    fi

    # Pull latest device list + reload telegraf if config changed
    /usr/bin/php /opt/sg-noc-branch/snmp-sync.php || true

    systemctl enable --now telegraf 2>/dev/null || true
    systemctl enable --now sg-noc-snmp-sync.timer     2>/dev/null || true
    systemctl enable --now sg-noc-nmap-discover.timer 2>/dev/null || true
fi

# 8. Health check
sleep 2
if curl -fsS http://127.0.0.1:8514/api/health > /dev/null; then
    green "✔ Update applied; API healthy."
else
    red "API health check failed — check journalctl -u sg-noc-ingester and nginx logs."
    exit 2
fi
