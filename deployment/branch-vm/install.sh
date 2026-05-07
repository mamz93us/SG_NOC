#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────
# SG_NOC Branch VM installer — Ubuntu 24.04 LTS.
#
# Idempotent. Run as root or via sudo. Reads /etc/sg-noc-branch.env to
# discover BRANCH_ID, secrets, retention. Generates DB_PASSWORD and
# API_TOKEN if they're empty in the env file (saves them back).
#
#   sudo bash /opt/sg-noc/deployment/branch-vm/install.sh
# ─────────────────────────────────────────────────────────────────────────
set -euo pipefail

REPO_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
SRC_DIR="$REPO_DIR/deployment/branch-vm"
ENV_FILE="/etc/sg-noc-branch.env"

red()  { printf '\033[31m%s\033[0m\n' "$*" >&2; }
green(){ printf '\033[32m%s\033[0m\n' "$*"; }
note() { printf '\033[36m▸ %s\033[0m\n' "$*"; }

require_root() {
    [[ $EUID -eq 0 ]] || { red "Run with sudo (need root)."; exit 1; }
}

require_ubuntu_24() {
    . /etc/os-release
    [[ "${ID:-}" == "ubuntu" && "${VERSION_ID:-}" == "24.04" ]] || {
        red "This installer targets Ubuntu 24.04 (you're on $PRETTY_NAME)."
        exit 1
    }
}

ensure_env_file() {
    if [[ ! -f $ENV_FILE ]]; then
        note "First run: copying template to $ENV_FILE"
        cp "$SRC_DIR/.env.example" "$ENV_FILE"
        chmod 600 "$ENV_FILE"
        red "Edit $ENV_FILE (BRANCH_ID, BRANCH_NAME, TIMEZONE...) then re-run."
        exit 1
    fi
}

# Read .env into the current shell, generate any missing secrets, write back.
load_and_complete_env() {
    set -a; . "$ENV_FILE"; set +a

    [[ -n "${BRANCH_ID:-}" ]]   || { red "BRANCH_ID is empty in $ENV_FILE."; exit 1; }
    [[ -n "${TIMEZONE:-}" ]]    || { red "TIMEZONE is empty in $ENV_FILE."; exit 1; }
    [[ -n "${DB_NAME:-}" ]]     || DB_NAME=sg_noc_branch
    [[ -n "${DB_USER:-}" ]]     || DB_USER=sg_noc
    : "${RETENTION_DAYS:=60}"
    : "${NOC_ALLOWED_CIDR:=10.0.0.0/8}"

    if [[ -z "${DB_PASSWORD:-}" ]]; then
        DB_PASSWORD=$(openssl rand -hex 24)
        note "Generated DB_PASSWORD"
        sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=$DB_PASSWORD|" "$ENV_FILE"
    fi
    if [[ -z "${API_TOKEN:-}" ]]; then
        API_TOKEN=$(openssl rand -hex 32)
        note "Generated API_TOKEN — copy it to NOC .env as BRANCH_API_TOKEN_${BRANCH_ID^^}"
        sed -i "s|^API_TOKEN=.*|API_TOKEN=$API_TOKEN|" "$ENV_FILE"
    fi

    export BRANCH_ID BRANCH_NAME TIMEZONE RETENTION_DAYS \
           DB_NAME DB_USER DB_PASSWORD API_TOKEN \
           NOC_ALLOWED_CIDR NOC_GRAYLOG_HOST NOC_GRAYLOG_PORT
}

set_timezone() {
    note "Setting timezone to $TIMEZONE"
    timedatectl set-timezone "$TIMEZONE"
}

apt_install_deps() {
    note "Installing OS packages"
    export DEBIAN_FRONTEND=noninteractive

    # InfluxData repo for Telegraf
    if [[ ! -f /etc/apt/sources.list.d/influxdata.list ]]; then
        note "Adding InfluxData apt repo for Telegraf"
        curl -fsSL https://repos.influxdata.com/influxdata-archive.key |
            gpg --dearmor -o /etc/apt/trusted.gpg.d/influxdata.gpg
        echo 'deb https://repos.influxdata.com/debian stable main' \
            > /etc/apt/sources.list.d/influxdata.list
    fi

    apt-get update -y
    apt-get install -y --no-install-recommends \
        rsyslog \
        mariadb-server mariadb-backup \
        php-cli php-mysql php-mbstring php-curl php-json \
        nginx php-fpm \
        ufw \
        ca-certificates curl gnupg jq openssl \
        cron logrotate \
        telegraf nmap snmp
}

setup_directories() {
    note "Creating runtime directories"
    install -d -o syslog -g adm -m 0755 /var/spool/sg-noc-ingest
    install -d -o syslog -g adm -m 0755 /var/log/branch
    install -d -o root   -g root -m 0755 /var/lib/sg-noc-ingest
    install -d -o root   -g root -m 0755 /var/lib/sg-noc-branch
    install -d -o root   -g root -m 0755 /opt/sg-noc-branch
    install -d -o root   -g root -m 0755 /opt/sg-noc-branch/telegraf
    install -d -o root   -g root -m 0755 /opt/sg-noc-branch/telegraf/templates
}

configure_mariadb() {
    note "Configuring MariaDB"
    systemctl enable --now mariadb

    # Wait until socket is ready
    for _ in {1..30}; do
        mysqladmin ping --silent && break || sleep 1
    done

    # Create DB + user (idempotent)
    mysql --protocol=socket -u root <<-SQL
        CREATE DATABASE IF NOT EXISTS \`$DB_NAME\`
            DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
        CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASSWORD';
        ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASSWORD';
        GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX, REFERENCES
            ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
        FLUSH PRIVILEGES;
SQL

    # Apply schema (idempotent — uses CREATE TABLE IF NOT EXISTS internally)
    mysql --protocol=socket -u root "$DB_NAME" < "$SRC_DIR/mariadb/schema.sql"

    # Pre-create partitions for today + 7 days forward so first-day inserts
    # don't go into pmax (which would lock partition operations later).
    bash "$SRC_DIR/mariadb/partition-rotate.sh" --bootstrap
}

install_rsyslog_config() {
    note "Installing rsyslog config"
    # Render template — substitute BRANCH_ID into the file as a tag the
    # ingester can read back from the JSONL.
    sed "s|__BRANCH_ID__|$BRANCH_ID|g" \
        "$SRC_DIR/rsyslog/10-branch-collector.conf" \
        > /etc/rsyslog.d/10-branch-collector.conf

    # Sanity check syntax
    rsyslogd -N1 -f /etc/rsyslog.d/10-branch-collector.conf

    # Ubuntu 24.04 rsyslog ships with an AppArmor profile that whitelists
    # /var/log/** and /var/spool/rsyslog/** but NOT our custom spool path.
    # Drop a local allow-list rider so the omfile actions can write to
    # /var/spool/sg-noc-ingest/ and /var/log/branch/.
    if [[ -d /etc/apparmor.d/local ]]; then
        cat > /etc/apparmor.d/local/usr.sbin.rsyslogd <<'EOF'
# SG_NOC branch VM — paths the branch collector ruleset writes to.
/var/spool/sg-noc-ingest/ rw,
/var/spool/sg-noc-ingest/** rw,
/var/log/branch/ rw,
/var/log/branch/** rw,
EOF
        # Reload the profile (no-op if AppArmor isn't enforcing)
        if command -v apparmor_parser >/dev/null 2>&1 \
           && [[ -f /etc/apparmor.d/usr.sbin.rsyslogd ]]; then
            apparmor_parser -r /etc/apparmor.d/usr.sbin.rsyslogd 2>/dev/null || true
        fi
    fi

    systemctl restart rsyslog
}

install_ingester_service() {
    note "Installing ingester systemd service"
    install -m 0755 "$SRC_DIR/ingester/ingester.php"        /opt/sg-noc-branch/ingester.php
    install -m 0644 "$SRC_DIR/systemd/sg-noc-ingester.service" /etc/systemd/system/
    systemctl daemon-reload
    systemctl enable --now sg-noc-ingester
}

install_query_api() {
    note "Installing PHP query API"
    install -d /opt/sg-noc-branch/api/public
    install -d /opt/sg-noc-branch/api/lib
    cp -r "$SRC_DIR/api/public/." /opt/sg-noc-branch/api/public/
    cp -r "$SRC_DIR/api/lib/."    /opt/sg-noc-branch/api/lib/
    chown -R www-data:www-data /opt/sg-noc-branch/api

    # nginx site (rendered with NOC_ALLOWED_CIDR substituted)
    sed "s|__NOC_ALLOWED_CIDR__|$NOC_ALLOWED_CIDR|g" \
        "$SRC_DIR/api/nginx-site.conf" \
        > /etc/nginx/sites-available/sg-noc-api
    ln -sf /etc/nginx/sites-available/sg-noc-api /etc/nginx/sites-enabled/sg-noc-api

    nginx -t
    systemctl reload nginx

    # Make sure php-fpm is running
    systemctl enable --now php8.3-fpm 2>/dev/null \
        || systemctl enable --now php-fpm
}

install_partition_rotation() {
    note "Installing nightly partition-rotate timer"
    install -m 0755 "$SRC_DIR/mariadb/partition-rotate.sh" /opt/sg-noc-branch/partition-rotate.sh
    install -m 0644 "$SRC_DIR/systemd/sg-noc-partition-rotate.service" /etc/systemd/system/
    install -m 0644 "$SRC_DIR/systemd/sg-noc-partition-rotate.timer"   /etc/systemd/system/
    systemctl daemon-reload
    systemctl enable --now sg-noc-partition-rotate.timer
}

install_telegraf() {
    note "Configuring Telegraf — global output to NOC VictoriaMetrics"

    # Bail early if the operator hasn't filled in the metrics endpoint.
    # Snmp-sync still works, but nothing will arrive on the NOC side.
    if [[ -z "${NOC_METRICS_URL:-}" || -z "${NOC_METRICS_USER:-}" \
          || -z "${NOC_METRICS_PASSWORD:-}" || -z "${NOC_URL:-}" ]]; then
        red "Skipping Telegraf wiring — set NOC_URL, NOC_METRICS_URL, NOC_METRICS_USER,"
        red "NOC_METRICS_PASSWORD in $ENV_FILE then re-run install.sh."
        return 0
    fi

    # 1) Per-type templates (read-only, used by snmp-sync.php)
    cp -a "$SRC_DIR/telegraf/templates/." /opt/sg-noc-branch/telegraf/templates/
    chmod 0644 /opt/sg-noc-branch/telegraf/templates/*

    # 2) Global output config — substitute creds + branch-id
    sed -e "s|__BRANCH_ID__|$BRANCH_ID|g" \
        -e "s|__NOC_METRICS_URL__|${NOC_METRICS_URL//|/\\|}|g" \
        -e "s|__NOC_METRICS_USER__|$NOC_METRICS_USER|g" \
        -e "s|__NOC_METRICS_PASSWORD__|$NOC_METRICS_PASSWORD|g" \
        "$SRC_DIR/telegraf/templates/00-output.conf.tpl" \
        > /etc/telegraf/telegraf.d/00-output.conf
    chmod 0640 /etc/telegraf/telegraf.d/00-output.conf
    chgrp telegraf /etc/telegraf/telegraf.d/00-output.conf 2>/dev/null || true

    # 3) The default /etc/telegraf/telegraf.conf has a noisy [[outputs.influxdb]]
    #    block on most distros — disable it so only our NOC output is active.
    if [[ -f /etc/telegraf/telegraf.conf ]]; then
        sed -i 's|^\[\[outputs\.influxdb\]\]|# &|' /etc/telegraf/telegraf.conf
    fi

    # 4) snmp-sync + nmap-discover scripts
    install -m 0755 "$SRC_DIR/telegraf/snmp-sync.php"     /opt/sg-noc-branch/snmp-sync.php
    install -m 0755 "$SRC_DIR/telegraf/nmap-discover.php" /opt/sg-noc-branch/nmap-discover.php

    # 5) systemd units + timers
    install -m 0644 "$SRC_DIR/systemd/sg-noc-snmp-sync.service"     /etc/systemd/system/
    install -m 0644 "$SRC_DIR/systemd/sg-noc-snmp-sync.timer"       /etc/systemd/system/
    install -m 0644 "$SRC_DIR/systemd/sg-noc-nmap-discover.service" /etc/systemd/system/
    install -m 0644 "$SRC_DIR/systemd/sg-noc-nmap-discover.timer"   /etc/systemd/system/
    systemctl daemon-reload

    # 6) Run snmp-sync once to bootstrap (if any devices exist for this branch).
    #    If the API call fails, no big deal — the timer will retry every 5 min.
    note "Running first SNMP sync (devices may be 0 if none configured yet)"
    /usr/bin/php /opt/sg-noc-branch/snmp-sync.php || true

    # 7) Start telegraf + timers
    systemctl enable --now telegraf
    systemctl enable --now sg-noc-snmp-sync.timer
    systemctl enable --now sg-noc-nmap-discover.timer
}

configure_firewall() {
    note "Configuring ufw"
    # Allow SSH so we don't lock ourselves out
    ufw --force enable
    ufw allow 22/tcp comment 'ssh'
    ufw allow 514/udp comment 'syslog from devices (UDP)'
    ufw allow 514/tcp comment 'syslog from devices (TCP)'
    ufw allow from "$NOC_ALLOWED_CIDR" to any port 8514 proto tcp comment 'sg-noc-api from NOC'
    ufw status verbose | sed 's/^/  /'
}

print_summary() {
    cat <<EOF

$(green "✔ SG_NOC branch VM ready: $BRANCH_ID ($BRANCH_NAME)")

  Listening:
    UDP/TCP 514   ← devices send syslog here
    TCP 8514      ← NOC queries via $NOC_ALLOWED_CIDR

  Health check:
    curl http://127.0.0.1:8514/api/health

  Ingester logs:    journalctl -u sg-noc-ingester -f
  API access logs:  /var/log/nginx/sg-noc-access.log

  Add this to the NOC Laravel .env:
    BRANCH_API_TOKEN_${BRANCH_ID^^}=$API_TOKEN

  And in NOC config/branches.php:
    '$BRANCH_ID' => [
        'name'  => '$BRANCH_NAME',
        'host'  => '<IP of this VM on the IPsec tunnel>',
        'port'  => 8514,
        'token' => env('BRANCH_API_TOKEN_${BRANCH_ID^^}'),
    ],

EOF
}

main() {
    require_root
    require_ubuntu_24
    ensure_env_file
    load_and_complete_env
    set_timezone
    apt_install_deps
    setup_directories
    configure_mariadb
    install_rsyslog_config
    install_ingester_service
    install_query_api
    install_partition_rotation
    install_telegraf
    configure_firewall
    print_summary
}

main "$@"
