#!/usr/bin/env bash
# =============================================================================
# Provision the NOC VM to serve Grandstream phone firmware.
#
# Two listeners, both static — nginx serves the .bin files straight off disk and
# Laravel is never in the path:
#
#   1. An internal vhost on port 80 for the branch tunnels (the preferred path).
#   2. A /fw/ location snippet dropped into /etc/nginx/sites-dynamic/, which the
#      main NOC vhost already includes — that gives the public fallback without
#      hand-editing a vhost that is not in version control.
#
# Run on the NOC VM as root. Idempotent: safe to re-run after a `git pull`.
#
#   sudo bash deployment/firmware/setup.sh
#   sudo FIRMWARE_HOST=fw.samirgroup.net bash deployment/firmware/setup.sh
#   sudo FIRMWARE_LISTEN=172.16.8.11 bash deployment/firmware/setup.sh
#
# ---------------------------------------------------------------------------
# DO NOT PUT THIS VHOST BEHIND AN HTTP->HTTPS REDIRECT.
#
# Older Grandstream firmware fails modern TLS handshakes and does not reliably
# follow 302s. A phone that gets redirected simply never upgrades, silently, and
# the only symptom is a status board that stays red. If you ever add TLS here,
# run certbot with --no-redirect and leave port 80 answering.
# ---------------------------------------------------------------------------
#
# See ../../PHONE_FIRMWARE_SERVER.md for the runbook and the UCM-side settings.
# =============================================================================
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="${APP_DIR:-$(cd "$HERE/../.." && pwd)}"

# Hostname the internal vhost answers to, alongside the raw IP.
FIRMWARE_HOST="${FIRMWARE_HOST:-fw.samirgroup.net}"
# Internal address the phones are pointed at. Empty = listen on every interface.
FIRMWARE_LISTEN="${FIRMWARE_LISTEN:-}"

FW_ROOT="$APP_DIR/storage/app/public/firmware"
AVAILABLE="/etc/nginx/sites-available/phone-firmware"
ENABLED="/etc/nginx/sites-enabled/phone-firmware"
DYNAMIC_DIR="/etc/nginx/sites-dynamic"
SNIPPET="$DYNAMIC_DIR/phone-firmware.conf"
ACCESS_LOG="/var/log/nginx/phone-firmware.access.log"

log()  { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[warn]\033[0m %s\n' "$*"; }
die()  { printf '\033[1;31m[fail]\033[0m %s\n' "$*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || die "This script must run as root (use sudo)."
[[ -f "$APP_DIR/artisan" ]] || die "No artisan at $APP_DIR — set APP_DIR to the app root."
command -v nginx >/dev/null || die "nginx is not installed."

log "App:      $APP_DIR"
log "Serving:  $FW_ROOT"

# --- The firmware directory ---------------------------------------------------
# Lives under the `public` disk root so the existing storage symlink already
# exposes it; nginx reads the same directory directly for the internal listener.
APP_USER="$(stat -c '%U' "$APP_DIR/artisan")"
install -d -o "$APP_USER" -g "$APP_USER" -m 755 "$FW_ROOT"
install -d -o "$APP_USER" -g "$APP_USER" -m 755 "$FW_ROOT/library"

# nginx (www-data) traverses APP_DIR/storage/app/public to reach it. The
# storage:link symlink already proves this path is readable, but a fresh clone
# can land with a tighter umask.
chmod o+x "$APP_DIR/storage" "$APP_DIR/storage/app" "$APP_DIR/storage/app/public" 2>/dev/null || true

# --- Shared location block ----------------------------------------------------
# Firmware images are opaque blobs: force octet-stream so no MIME guess turns a
# .bin into something a phone refuses. autoindex stays off — the directory is
# reachable by anything on the branch LAN and there is no reason to enumerate it.
# Kept flat on purpose: nested locations inside a prefix location are legal but
# fragile, and `try_files` misbehaves under `alias`. Everything the two blocks
# share is just these three directives.
read -r -d '' FW_LOCATION <<'NGINX' || true
    default_type application/octet-stream;
    types { }
    autoindex off;
NGINX

# --- Internal vhost -----------------------------------------------------------
# Binding to a specific IP makes this the default server for that address, so a
# phone pointed at the bare IP matches regardless of the Host header it sends.
# Without FIRMWARE_LISTEN the vhost answers by hostname only — phones configured
# with an IP would fall through to whatever the default server is, which is why
# FIRMWARE_LISTEN is the normal way to run this.
if [[ -n "$FIRMWARE_LISTEN" ]]; then
    LISTEN_DIRECTIVE="listen ${FIRMWARE_LISTEN}:80;"
    SERVER_NAMES="${FIRMWARE_HOST} ${FIRMWARE_LISTEN}"
else
    LISTEN_DIRECTIVE="listen 80;"
    SERVER_NAMES="${FIRMWARE_HOST}"
    warn "FIRMWARE_LISTEN not set — phones pointed at a bare IP will not reach this vhost."
fi

log "Writing $AVAILABLE"
cat > "$AVAILABLE" <<NGINX
# Grandstream phone firmware — managed by deployment/firmware/setup.sh
#
# Static only. No PHP, no auth, and deliberately NO HTTPS REDIRECT: old phone
# firmware fails modern TLS and does not follow 302s, so a redirect here means
# every phone silently stops upgrading.
server {
    ${LISTEN_DIRECTIVE}
    server_name ${SERVER_NAMES};

    root ${FW_ROOT};

    access_log ${ACCESS_LOG};
    error_log  /var/log/nginx/phone-firmware.error.log;

    # The library/ subdirectory holds every image ever added, published or not.
    # Only what has been published sits at the root, and only that is servable.
    location ^~ /library/ { deny all; }
    location ~ /\.        { deny all; }

    location / {
${FW_LOCATION}
        try_files \$uri =404;
    }
}
NGINX

ln -sfn "$AVAILABLE" "$ENABLED"

# --- Public fallback via the sites-dynamic include ----------------------------
# The main NOC vhost already carries `include /etc/nginx/sites-dynamic/*.conf;`
# for the browser portal, so we can add /fw/ without touching that file.
if [[ -d "$DYNAMIC_DIR" ]]; then
    log "Writing $SNIPPET"
    cat > "$SNIPPET" <<NGINX
# Public fallback for phone firmware — managed by deployment/firmware/setup.sh
# For branches whose tunnel does not carry the NOC subnet. Prefer the internal
# path: this one is reachable from the internet.
location ^~ /fw/library/ { deny all; }

location ^~ /fw/ {
    alias ${FW_ROOT}/;
${FW_LOCATION}
    access_log ${ACCESS_LOG};
}
NGINX
else
    warn "$DYNAMIC_DIR does not exist — public /fw/ fallback NOT installed."
    warn "Create it and add 'include $DYNAMIC_DIR/*.conf;' inside the NOC server block,"
    warn "then re-run this script. See deployment/browser-portal/nginx/README.md."
fi

# --- Apply --------------------------------------------------------------------
log "Testing nginx config ..."
nginx -t || die "nginx config test failed — nothing was reloaded. Fix the errors above."
systemctl reload nginx
log "nginx reloaded."

# --- Smoke test ---------------------------------------------------------------
# A canary proves the path end to end without needing a real firmware image.
CANARY="$FW_ROOT/setup-canary.bin"
echo "phone-firmware-canary" > "$CANARY"
chown "$APP_USER:$APP_USER" "$CANARY"

PROBE_HOST="${FIRMWARE_LISTEN:-127.0.0.1}"
CODE="$(curl -s -o /dev/null -w '%{http_code}' --max-time 5 "http://${PROBE_HOST}/setup-canary.bin" || true)"
rm -f "$CANARY"

case "$CODE" in
    200) log "Smoke test OK — http://${PROBE_HOST}/<file>.bin returns 200." ;;
    30*) die "Got HTTP $CODE — something is redirecting port 80. Phones will NOT upgrade through a redirect." ;;
    *)   warn "Smoke test returned HTTP $CODE. Check $ACCESS_LOG and the error log." ;;
esac

cat <<DONE

Next:
  1. Publish an image:  https://noc.samirgroup.net/admin/phones/firmware
  2. Point the phones:  UCM -> Zero Config -> Global Policy -> Upgrade
                        Firmware Source            = URL Download
                        Upgrade Via                = HTTP
                        Firmware Upgrade Server Path = ${FIRMWARE_LISTEN:-<NOC internal IP>}
  3. Watch who took it: https://noc.samirgroup.net/admin/phones/firmware/status

  Before switching a branch to the internal path, confirm its tunnel actually
  carries the NOC subnet — a reachable branch firewall does not prove it.
DONE
