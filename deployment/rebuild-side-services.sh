#!/usr/bin/env bash
#
# rebuild-side-services.sh — one-shot setup of the NOC side services on a fresh VM:
#   • Docker (prereq for the browser portal)
#   • CUPS print manager (+ TLS via the Let's Encrypt cert)
#   • Telnet/SSH device-console proxy (Node + PM2)
#   • Browser portal (Neko) infra (calls deployment/browser-portal/bootstrap-vps.sh)
#   • SFTPGo device-backup ingestion (calls deployment/sftpgo/install.sh if the binary is present)
#
# Idempotent and NON-DESTRUCTIVE — safe to re-run. It only installs/enables/symlinks;
# it never deletes data. Anything needing a human value (nginx blocks, Settings UI,
# certs, VPN) is printed at the end under "MANUAL FOLLOW-UPS".
#
#   sudo bash deployment/rebuild-side-services.sh
#
# Override defaults via env, e.g.:
#   sudo APP_DIR=/home/azureuser/phonebook2 NOC_HOST=noc.samirgroup.net bash deployment/rebuild-side-services.sh

set -uo pipefail   # NOT -e: a failing service must not abort the others

APP_DIR="${APP_DIR:-/home/azureuser/phonebook2}"
APP_USER="${APP_USER:-azureuser}"
WEB_USER="${WEB_USER:-www-data}"   # php-fpm pool user (calls the VPN wrapper via sudo)
NOC_HOST="${NOC_HOST:-noc.samirgroup.net}"
LE_DIR="/etc/letsencrypt/live/${NOC_HOST}"
ENV_FILE="${APP_DIR}/.env"

ok()   { printf '  \033[1;32m·\033[0m %s\n' "$*"; }
warn() { printf '  \033[1;33m!\033[0m %s\n' "$*"; }
sec()  { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }
NOTES=()
note() { NOTES+=("$*"); }

[ "$(id -u)" -eq 0 ] || { echo "Run as root (sudo)."; exit 1; }
[ -f "$ENV_FILE" ]   || { echo "App .env not found at ${ENV_FILE} — set APP_DIR."; exit 1; }

export DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=a

# ───────────────────────────────────────────────────────── Docker
sec "Docker"
if command -v docker >/dev/null 2>&1; then
    ok "docker present ($(docker --version 2>/dev/null))"
else
    if curl -fsSL https://get.docker.com | sh >/dev/null 2>&1; then ok "docker installed"; else warn "docker install failed"; fi
fi
usermod -aG docker "$APP_USER" 2>/dev/null && ok "${APP_USER} added to docker group (needs a fresh login to take effect)" || true

# ───────────────────────────────────────────────────────── CUPS
sec "CUPS (print manager)"
if apt-get install -y cups >/dev/null 2>&1; then ok "cups installed"; else warn "cups install failed"; fi
systemctl enable --now cups >/dev/null 2>&1 || true
HN="$(hostname)"
# CUPS reads /etc/cups/ssl/<machine-hostname>.{crt,key} — NOT keyed off ServerName.
if [ -f "${LE_DIR}/fullchain.pem" ]; then
    mkdir -p /etc/cups/ssl
    ln -sf "${LE_DIR}/fullchain.pem" "/etc/cups/ssl/${HN}.crt"
    ln -sf "${LE_DIR}/privkey.pem"   "/etc/cups/ssl/${HN}.key"
    systemctl restart cups >/dev/null 2>&1 || true
    ok "CUPS TLS: /etc/cups/ssl/${HN}.{crt,key} → ${NOC_HOST} LE cert (don't rename the host or these break)"
else
    warn "no LE cert at ${LE_DIR} — skipped CUPS TLS symlink"
    note "CUPS TLS: once a cert exists, ln -sf ${LE_DIR}/fullchain.pem /etc/cups/ssl/${HN}.crt and privkey.pem → ${HN}.key, then restart cups."
fi
note "CUPS: if the app manages it remotely, run 'sudo cupsctl --remote-admin --remote-any' and set the CUPS connection under Admin → Settings → Print Manager."

# ───────────────────────────────────────────── Telnet/SSH console proxy
sec "Telnet/SSH proxy (device console, :8765)"
SECRET="$(grep -E '^TELNET_INTERNAL_SECRET=' "$ENV_FILE" | head -1 | cut -d= -f2-)"
if [ -z "${SECRET}" ]; then
    SECRET="$(openssl rand -hex 32)"
    printf '\nTELNET_INTERNAL_SECRET=%s\n' "$SECRET" >> "$ENV_FILE"
    ok "generated TELNET_INTERNAL_SECRET → ${ENV_FILE}"
else
    ok "TELNET_INTERNAL_SECRET already set in .env"
fi
if command -v npm >/dev/null 2>&1; then
    npm install -g pm2 >/dev/null 2>&1 || true
    sudo -u "$APP_USER" bash -lc "cd '${APP_DIR}/telnet-proxy' && npm install --omit=dev >/dev/null 2>&1" && ok "proxy deps installed" || warn "npm install (telnet-proxy) failed"
    sudo -u "$APP_USER" bash -lc "pm2 delete sg-noc-telnet >/dev/null 2>&1; cd '${APP_DIR}/telnet-proxy' && INTERNAL_SECRET='${SECRET}' WS_PORT=8765 LARAVEL_URL='https://${NOC_HOST}' pm2 start server.js --name sg-noc-telnet >/dev/null 2>&1 && pm2 save >/dev/null 2>&1" \
        && ok "telnet proxy running (pm2: sg-noc-telnet)" || warn "pm2 start failed — check 'pm2 logs sg-noc-telnet'"
    pm2 startup systemd -u "$APP_USER" --hp "/home/${APP_USER}" >/dev/null 2>&1 || true
else
    warn "npm not found — install Node 20 first, then re-run"
fi
note "nginx: add to the ${NOC_HOST} 443 server block (then 'sudo nginx -t && sudo systemctl reload nginx'):
        location /ws/telnet {
            proxy_pass http://127.0.0.1:8765;
            proxy_http_version 1.1;
            proxy_set_header Upgrade \$http_upgrade;
            proxy_set_header Connection \"upgrade\";
            proxy_set_header Host \$host;
            proxy_read_timeout 3600s;
        }"
note "Telnet proxy LARAVEL_URL is set to https://${NOC_HOST} (loops back through nginx). If the device console can't get a token, that hairpin is blocked — change it to a reachable internal URL and 'pm2 restart sg-noc-telnet --update-env'."

# ───────────────────────────────────────────── Browser portal (Neko)
sec "Browser portal (Neko)"
if command -v docker >/dev/null 2>&1; then
    if [ -f "${APP_DIR}/deployment/browser-portal/bootstrap-vps.sh" ]; then
        bash "${APP_DIR}/deployment/browser-portal/bootstrap-vps.sh" >/dev/null 2>&1 && ok "browser-portal infra bootstrapped (docker net + kill-switch)" || warn "browser-portal bootstrap had issues — run it directly to see output"
    else
        warn "deployment/browser-portal/bootstrap-vps.sh missing"
    fi
    ok "Neko image will be pulled by the app on first session (docker run auto-pulls)"
else
    warn "docker missing — skipped browser portal"
fi
note "Browser portal: set Admin → Settings → Remote Browser (vps_public_ip=20.82.165.228, neko_admin_password, idle minutes), wire the per-session nginx snippet include per deployment/browser-portal/DEPLOY-STEP5.md. VPN egress needs the strongSwan tunnels — rebuild from INFRA_SETUP.md (NEVER add a 0.0.0.0/0 child SA to an existing IKE conn)."

# ───────────────────────────────────────────── SFTPGo (device backups)
sec "SFTPGo (device backups)"
if command -v sftpgo >/dev/null 2>&1; then
    [ -f "${APP_DIR}/deployment/sftpgo/install.sh" ] && bash "${APP_DIR}/deployment/sftpgo/install.sh" && ok "sftpgo install.sh ran" || warn "sftpgo install.sh missing/failed"
else
    warn "sftpgo binary not installed (optional) — see deployment/sftpgo/README.md §1"
    note "SFTPGo (optional, device backups): install binary + resource dirs (README §1), run setup.sql, then deployment/sftpgo/install.sh; configure Admin → Settings → SFTPGo."
fi

# ───────────────────────────────────────────── strongSwan (branch VPN hub)
sec "strongSwan (branch VPN hub)"
if apt-get install -y strongswan strongswan-swanctl charon-systemd libcharon-extra-plugins >/dev/null 2>&1; then
    ok "strongswan + swanctl installed"
else
    warn "strongswan install failed"
fi
mkdir -p /etc/swanctl/conf.d
SWAN_N=0
for f in "${APP_DIR}"/*.conf; do
    [ -e "$f" ] || continue
    if grep -q '^connections {' "$f" 2>/dev/null; then
        cp -f "$f" "/etc/swanctl/conf.d/$(basename "$f")"
        chmod 600 "/etc/swanctl/conf.d/$(basename "$f")"
        SWAN_N=$((SWAN_N+1))
    fi
done
if [ "$SWAN_N" -gt 0 ]; then ok "restored ${SWAN_N} swanctl connection file(s) → /etc/swanctl/conf.d (chmod 600)"; else warn "no swanctl connection files (with 'connections {') found in ${APP_DIR}"; fi
# The VPN Hub UI writes each tunnel's .conf DIRECTLY as the php-fpm user
# (VpnControlService::saveConfig → file_put_contents /etc/swanctl/conf.d/<name>.conf),
# so php-fpm needs write on that root-owned dir. Grant via ACL (+ default for new
# files); swanctl still runs as root via the wrapper, so it reads the 600 files fine.
command -v setfacl >/dev/null 2>&1 || apt-get install -y acl >/dev/null 2>&1 || true
if command -v setfacl >/dev/null 2>&1; then
    setfacl -m   "u:${WEB_USER}:rwx" /etc/swanctl/conf.d 2>/dev/null \
      && setfacl -d -m "u:${WEB_USER}:rwx" /etc/swanctl/conf.d 2>/dev/null \
      && ok "ACL: ${WEB_USER} can write /etc/swanctl/conf.d (UI tunnel saves)" \
      || warn "setfacl on /etc/swanctl/conf.d failed — UI tunnel saves will fail until ${WEB_USER} can write there"
fi
# control wrapper invoked by the web UI via sudo (status/up/down/reload/logs).
# MUST be /usr/local/bin/sg-vpn-control (no .sh) — VpnControlService::$wrapperPath.
if [ -f "${APP_DIR}/sg-vpn-control.sh" ]; then
    install -m 0755 "${APP_DIR}/sg-vpn-control.sh" /usr/local/bin/sg-vpn-control
    printf '%s ALL=(root) NOPASSWD: /usr/local/bin/sg-vpn-control *\n' "$WEB_USER" > /etc/sudoers.d/sg-vpn-control
    chmod 440 /etc/sudoers.d/sg-vpn-control
    if visudo -cf /etc/sudoers.d/sg-vpn-control >/dev/null 2>&1; then
        ok "vpn wrapper → /usr/local/bin/sg-vpn-control + sudoers NOPASSWD for ${WEB_USER}"
    else
        rm -f /etc/sudoers.d/sg-vpn-control; warn "sudoers validation failed — removed (set WEB_USER correctly and re-run)"
    fi
fi
# Run ONLY charon-systemd (swanctl/vici). The strongswan metapackage also pulls the
# legacy 'strongswan-starter' daemon; if BOTH run they fight over UDP 500/4500 and the
# loser logs "no socket implementation registered" — tunnels stay stuck CONNECTING with
# the config in one charon and the sockets in the other. Kill the legacy one first.
systemctl disable --now strongswan-starter >/dev/null 2>&1 || true
ipsec stop >/dev/null 2>&1 || true
systemctl enable --now strongswan >/dev/null 2>&1 && ok "charon-systemd (strongswan.service) running; legacy starter disabled" || warn "strongswan.service failed to start"
if command -v swanctl >/dev/null 2>&1; then
    swanctl --load-all >/dev/null 2>&1 && ok "swanctl --load-all done (start_action=start → tunnels initiating)" || warn "swanctl --load-all failed — 'systemctl status strongswan'"
fi
note "VPN: tunnels are managed from the admin UI (VPN Hub) — stored in the DB and written to /etc/swanctl/conf.d/<name>.conf by the app, then 'swanctl --load-all'. Recreate each branch tunnel there after a rebuild. Verify: 'swanctl --list-sas'. NEVER add a 0.0.0.0/0 child SA to an existing IKE conn — Sophos widens it on rekey and hijacks ALL VPS egress."
note "VPN: the wrapper path (/usr/local/bin/sg-vpn-control) and the conf.d ACL are keyed to php-fpm user '${WEB_USER}'. If 'Failed to save swanctl configuration file' appears in the UI, php-fpm runs as a different user — re-run with WEB_USER=<that user>."
note "VPN: leaving a tunnel's local/remote subnet BLANK in the UI makes the app generate a 0.0.0.0/0 child SA (VpnControlService default). Always fill both subnets — a 0.0.0.0/0 selector risks hijacking VPS egress on rekey."

# ─────────────────────────────────── supervisor daemons (scheduler + VQ collector)
sec "Supervisor daemons (scheduler-as-worker + VQ collector)"
if apt-get install -y supervisor >/dev/null 2>&1; then ok "supervisor installed"; else warn "supervisor install failed"; fi
mkdir -p /var/log/supervisor
# VQ collector — UDP RTCP-XR voice-quality listener on :5099 (distinct daemon, always install)
if [ -f "${APP_DIR}/deployment/supervisor/vq-collector.conf" ]; then
    install -m 0644 "${APP_DIR}/deployment/supervisor/vq-collector.conf" /etc/supervisor/conf.d/vq-collector.conf
    ok "vq-collector unit installed (php artisan vq:collect --port=5099)"
else
    warn "deployment/supervisor/vq-collector.conf missing"
fi
# scheduler-as-worker — only add switch-poll if a schedule:run cron isn't already doing the job
if { crontab -l -u "$APP_USER" 2>/dev/null; cat /etc/crontab /etc/cron.d/* 2>/dev/null; } | grep -q 'schedule:run'; then
    warn "a 'schedule:run' cron already exists — NOT installing switch-poll (would double-run the scheduler)"
    note "Scheduler: schedule:run is driven by cron. Production canonically uses the supervisor 'switch-poll' unit instead — pick ONE. Running both double-fires every scheduled task (double syncs, double notifications)."
elif [ -f "${APP_DIR}/deployment/supervisor/switch-poll.conf" ]; then
    install -m 0644 "${APP_DIR}/deployment/supervisor/switch-poll.conf" /etc/supervisor/conf.d/switch-poll.conf
    ok "switch-poll unit installed (keeps 'php artisan schedule:run' alive)"
fi
systemctl enable --now supervisor >/dev/null 2>&1 || true
supervisorctl reread >/dev/null 2>&1 || true
supervisorctl update >/dev/null 2>&1 && ok "supervisor units (re)loaded + started" || warn "supervisorctl update failed — 'supervisorctl status'"
note "VQ collector: open inbound UDP 5099 to the NOC (Azure NSG + host firewall) so branch Grandstream phones can deliver SIP NOTIFY vq-rtcpxr. Verify: 'supervisorctl status vq-collector' and 'ss -lunp | grep 5099'."

# ───────────────────────────────────────────── refresh app config
sec "App config"
sudo -u "$APP_USER" bash -lc "cd '${APP_DIR}' && php artisan config:clear >/dev/null 2>&1" && ok "config cache cleared (picks up TELNET_INTERNAL_SECRET)" || warn "config:clear failed"

# ───────────────────────────────────────────── summary
sec "MANUAL FOLLOW-UPS"
if [ "${#NOTES[@]}" -gt 0 ]; then
    for n in "${NOTES[@]}"; do printf '  • %s\n' "$n"; done
fi
printf '\n\033[1;32mDone.\033[0m Re-run anytime — idempotent. Verify: systemctl status cups ; pm2 status ; docker network ls\n'
