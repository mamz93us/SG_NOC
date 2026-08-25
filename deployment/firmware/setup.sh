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
# Upload ceiling for the NOC vhost. nginx defaults to 1m, which 413s any real
# firmware package (60-150 MB) long before Laravel sees the request.
UPLOAD_MAX_BODY="${UPLOAD_MAX_BODY:-512m}"

FW_ROOT="$APP_DIR/storage/app/public/firmware"
AVAILABLE="/etc/nginx/sites-available/phone-firmware"
ENABLED="/etc/nginx/sites-enabled/phone-firmware"
DYNAMIC_DIR="/etc/nginx/sites-dynamic"
SNIPPET="$DYNAMIC_DIR/phone-firmware.conf"
ACCESS_LOG="/var/log/nginx/phone-firmware.access.log"
# Backups go OUTSIDE /etc/nginx. nginx.conf includes sites-enabled/* with no
# extension filter, so a backup dropped beside the vhost is parsed as a second
# copy of it — "duplicate listen options", and the config stops loading.
BACKUP_DIR="/var/backups/sg-noc-nginx"

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

# PHP-FPM writes the uploads; nginx only reads them. Those can be different
# users, and a directory owned by the deploy user alone means every upload dies
# with a permission error the operator never sees. Take the group from the FPM
# pool and make the directory group-writable.
FPM_USER="$(sed -nE 's|^[[:space:]]*user[[:space:]]*=[[:space:]]*(.+)$|\1|p' /etc/php/*/fpm/pool.d/www.conf 2>/dev/null | head -1 | tr -d '[:space:]')"
FPM_GROUP="$(sed -nE 's|^[[:space:]]*group[[:space:]]*=[[:space:]]*(.+)$|\1|p' /etc/php/*/fpm/pool.d/www.conf 2>/dev/null | head -1 | tr -d '[:space:]')"
FW_GROUP="${FPM_GROUP:-$APP_USER}"

log "App user: $APP_USER   PHP-FPM: ${FPM_USER:-unknown}:${FPM_GROUP:-unknown}"

install -d -o "$APP_USER" -g "$FW_GROUP" -m 2775 "$FW_ROOT"
install -d -o "$APP_USER" -g "$FW_GROUP" -m 2775 "$FW_ROOT/library"

# If FPM runs as neither the owner nor a member of that group, say so plainly
# rather than leaving an upload to fail with a bare 500 later.
if [[ -n "$FPM_USER" && "$FPM_USER" != "$APP_USER" ]] && ! id -nG "$FPM_USER" 2>/dev/null | tr ' ' '
' | grep -qx "$FW_GROUP"; then
    warn "PHP-FPM runs as '$FPM_USER', which is not '$APP_USER' and not in group '$FW_GROUP'."
    warn "Uploads will fail. Fix with:  sudo usermod -aG $FW_GROUP $FPM_USER && sudo systemctl restart php*-fpm"
fi

# nginx traverses APP_DIR/storage/app/public to reach it. The storage:link
# symlink already proves this path is readable, but a fresh clone can land with
# a tighter umask.
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

# --- Upload ceiling -----------------------------------------------------------
# Two ceilings gate an upload: nginx client_max_body_size (default 1m, rejects
# with a bare 413 before PHP runs) and PHP upload_max_filesize/post_max_size
# (public/.user.ini). Raising one without the other changes nothing.
#
# The nginx half is fiddly. client_max_body_size is only allowed ONCE per
# context — a second one in the same server block is a hard `nginx -t` error,
# not a last-one-wins override — and the limit that actually applies is the one
# in effect where the body is read, which for a PHP app is the server block or
# the fastcgi location. So: if the vhost already sets it, raise that directive
# in place; only add our own when there is none.

# Find the NOC vhost by the include itself rather than by docroot: several
# sibling subdomains (vcard, hr-portal, marketing) share this app's docroot, and
# matching on that picks whichever sorts first.
NOC_VHOST="$(grep -rlsE 'include[[:space:]]+/etc/nginx/sites-dynamic' /etc/nginx/sites-enabled/ 2>/dev/null | head -1 || true)"
if [[ -n "$NOC_VHOST" ]]; then
    NOC_VHOST="$(readlink -f "$NOC_VHOST")"
fi

to_bytes() {
    local v="${1,,}" n="${1//[^0-9]/}"
    case "$v" in
        *g) echo $(( n * 1024 * 1024 * 1024 )) ;;
        *m) echo $(( n * 1024 * 1024 )) ;;
        *k) echo $(( n * 1024 )) ;;
        *)  echo "${n:-0}" ;;
    esac
}

# Sweep any backup an older version of this script left inside sites-enabled;
# nginx is parsing those as extra server blocks right now.
if [[ -n "$NOC_VHOST" ]]; then
    mkdir -p "$BACKUP_DIR"
    shopt -s nullglob
    for stray in "${NOC_VHOST}".bak-* "$(dirname "$NOC_VHOST")"/*.bak-*; do
        [[ -f "$stray" ]] || continue
        warn "Moving stray backup out of nginx's include path: $stray"
        mv -f "$stray" "$BACKUP_DIR/$(basename "$stray")"
    done
    shopt -u nullglob
fi

BACKUP_FILE=""
EMIT_BODY_SIZE=1
if [[ -n "$NOC_VHOST" ]] && grep -qE '^[[:space:]]*client_max_body_size' "$NOC_VHOST"; then
    EMIT_BODY_SIZE=0
    CURRENT="$(sed -nE 's|^[[:space:]]*client_max_body_size[[:space:]]+([^;]+);.*|\1|p' "$NOC_VHOST" | head -1 | tr -d '[:space:]')"
    log "$NOC_VHOST already sets client_max_body_size ${CURRENT}"

    if (( $(to_bytes "$CURRENT") < $(to_bytes "$UPLOAD_MAX_BODY") )); then
        mkdir -p "$BACKUP_DIR"
        BACKUP_FILE="$BACKUP_DIR/$(basename "$NOC_VHOST").bak-$(date +%Y%m%d%H%M%S)"
        cp -a "$NOC_VHOST" "$BACKUP_FILE"
        sed -i -E "s|^([[:space:]]*)client_max_body_size[[:space:]]+[^;]+;|\1client_max_body_size ${UPLOAD_MAX_BODY};  # raised by deployment/firmware/setup.sh|" "$NOC_VHOST"
        log "Raised it to ${UPLOAD_MAX_BODY} (backup: $BACKUP_FILE)."
    else
        log "Already at or above ${UPLOAD_MAX_BODY} — leaving it alone."
    fi
else
    log "No client_max_body_size in the NOC vhost — adding ${UPLOAD_MAX_BODY} via the snippet."
fi

if [[ ! -f "$APP_DIR/public/.user.ini" ]]; then
    warn "public/.user.ini is missing — PHP will still cap uploads at its stock 2M."
fi

# --- Public fallback via the sites-dynamic include ----------------------------
# The main NOC vhost already carries `include /etc/nginx/sites-dynamic/*.conf;`
# for the browser portal, so we can add /fw/ without touching that file.
if [[ -d "$DYNAMIC_DIR" ]]; then
    log "Writing $SNIPPET"
    {
        echo "# Phone firmware — managed by deployment/firmware/setup.sh"
        echo "# Included INSIDE the NOC server block."
        echo
        if (( EMIT_BODY_SIZE )); then
            echo "# nginx defaults this to 1m, which 413s every firmware upload (and every"
            echo "# Download Center artifact) before PHP is reached. Only emitted when the"
            echo "# vhost has none of its own — a duplicate in one context fails nginx -t."
            echo "client_max_body_size ${UPLOAD_MAX_BODY};"
            echo
        fi
        echo "# Public fallback for the firmware itself, for branches whose tunnel does not"
        echo "# carry the NOC subnet. Prefer the internal path: this one faces the internet."
        echo "location ^~ /fw/library/ { deny all; }"
        echo
        echo "location ^~ /fw/ {"
        echo "    alias ${FW_ROOT}/;"
        echo "${FW_LOCATION}"
        echo "    access_log ${ACCESS_LOG};"
        echo "}"
    } > "$SNIPPET"
else
    warn "$DYNAMIC_DIR does not exist — public /fw/ fallback NOT installed."
    warn "Create it and add 'include $DYNAMIC_DIR/*.conf;' inside the NOC server block,"
    warn "then re-run this script. See deployment/browser-portal/nginx/README.md."
fi

# --- Apply --------------------------------------------------------------------
# A failed `nginx -t` used to leave the broken files on disk. nginx keeps
# running on its loaded config, so nothing looks wrong — until the next reload
# from anywhere (a certbot renewal, an unrelated deploy) fails on our mess.
# Undo everything we wrote before giving up.
rollback() {
    warn "Rolling back the files this run wrote ..."
    rm -f "$ENABLED" "$AVAILABLE" "$SNIPPET"
    if [[ -n "${NOC_VHOST:-}" && -n "${BACKUP_FILE:-}" && -f "$BACKUP_FILE" ]]; then
        cp -a "$BACKUP_FILE" "$NOC_VHOST"
        warn "Restored $NOC_VHOST from $BACKUP_FILE"
    fi
    nginx -t >/dev/null 2>&1 && log "nginx config is valid again."         || warn "nginx config is STILL invalid — something else on this box is broken."
}

log "Testing nginx config ..."
if ! nginx -t; then
    rollback
    die "nginx config test failed — changes rolled back, nothing was reloaded. See the errors above."
fi
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

log "nginx client_max_body_size: ${UPLOAD_MAX_BODY}"
log "PHP limits come from public/.user.ini (FPM re-reads it within"
log "user_ini.cache_ttl, 300s by default)."
# Deliberately not probing this with `php -r`: the CLI SAPI does not read
# .user.ini at all, so it would report php.ini and understate the real limit.
# The honest check is the figure the firmware page itself prints.
log "Verify on /admin/phones/firmware — the upload box states the live ceiling."

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
