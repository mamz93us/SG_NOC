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
NOC_HOST="${NOC_HOST:-noc.samirgroup.net}"
LE_DIR="/etc/letsencrypt/live/${NOC_HOST}"
ENV_FILE="${APP_DIR}/.env"

ok()   { printf '  \033[1;32m·\033[0m %s\n' "$*"; }
warn() { printf '  \033[1;33m!\033[0m %s\n' "$*"; }
head() { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }
NOTES=()
note() { NOTES+=("$*"); }

[ "$(id -u)" -eq 0 ] || { echo "Run as root (sudo)."; exit 1; }
[ -f "$ENV_FILE" ]   || { echo "App .env not found at ${ENV_FILE} — set APP_DIR."; exit 1; }

export DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=a

# ───────────────────────────────────────────────────────── Docker
head "Docker"
if command -v docker >/dev/null 2>&1; then
    ok "docker present ($(docker --version 2>/dev/null))"
else
    if curl -fsSL https://get.docker.com | sh >/dev/null 2>&1; then ok "docker installed"; else warn "docker install failed"; fi
fi
usermod -aG docker "$APP_USER" 2>/dev/null && ok "${APP_USER} added to docker group (needs a fresh login to take effect)" || true

# ───────────────────────────────────────────────────────── CUPS
head "CUPS (print manager)"
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
head "Telnet/SSH proxy (device console, :8765)"
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
head "Browser portal (Neko)"
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
head "SFTPGo (device backups)"
if command -v sftpgo >/dev/null 2>&1; then
    [ -f "${APP_DIR}/deployment/sftpgo/install.sh" ] && bash "${APP_DIR}/deployment/sftpgo/install.sh" && ok "sftpgo install.sh ran" || warn "sftpgo install.sh missing/failed"
else
    warn "sftpgo binary not installed (optional) — see deployment/sftpgo/README.md §1"
    note "SFTPGo (optional, device backups): install binary + resource dirs (README §1), run setup.sql, then deployment/sftpgo/install.sh; configure Admin → Settings → SFTPGo."
fi

# ───────────────────────────────────────────── refresh app config
head "App config"
sudo -u "$APP_USER" bash -lc "cd '${APP_DIR}' && php artisan config:clear >/dev/null 2>&1" && ok "config cache cleared (picks up TELNET_INTERNAL_SECRET)" || warn "config:clear failed"

# ───────────────────────────────────────────── summary
head "MANUAL FOLLOW-UPS"
if [ "${#NOTES[@]}" -gt 0 ]; then
    for n in "${NOTES[@]}"; do printf '  • %s\n' "$n"; done
fi
printf '\n\033[1;32mDone.\033[0m Re-run anytime — idempotent. Verify: systemctl status cups ; pm2 status ; docker network ls\n'
