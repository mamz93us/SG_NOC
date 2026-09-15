#!/usr/bin/env bash
# =============================================================================
# Provision the NOC VM for the document archive subdomain.
#
# Creates the nginx vhost, obtains the TLS certificate, installs the TIFF
# converter, clears the app's cached config/routes and smoke-tests host
# isolation.
#
#   sudo bash deployment/archive-portal/setup.sh
#   sudo ARCHIVE_PORTAL_DOMAIN=archive.samirgroup.net bash deployment/archive-portal/setup.sh
#   sudo SKIP_TLS=1 bash deployment/archive-portal/setup.sh    # HTTP only, cert later
#
# Derived from deployment/hr-portal/setup.sh, with one important difference:
# TLS is obtained with the WEBROOT authenticator, not --nginx.
#
# Why: every external port-80 request on this host lands on the firmware vhost,
# because that vhost binds a SPECIFIC address (listen 172.16.8.11:80) while the
# others use a wildcard, and nginx picks a server block by listen specificity
# BEFORE it looks at server_name. Azure NATs inbound traffic to that private
# address, so the Host header never gets consulted and certbot's --nginx
# authenticator cannot validate anything here. The fix is a server block on that
# same specific address for this name, serving /var/www/acme.
# =============================================================================
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="${APP_DIR:-$(cd "$HERE/../.." && pwd)}"
DOMAIN="${ARCHIVE_PORTAL_DOMAIN:-archive.samirgroup.net}"
INTERNAL_IP="${INTERNAL_IP:-172.16.8.11}"
ACME_ROOT="${ACME_ROOT:-/var/www/acme}"

AVAILABLE="/etc/nginx/sites-available/${DOMAIN}"
ENABLED="/etc/nginx/sites-enabled/${DOMAIN}"

log()  { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[warn]\033[0m %s\n' "$*"; }
die()  { printf '\033[1;31m[fail]\033[0m %s\n' "$*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || die "Run as root (sudo)."
[[ -f "$APP_DIR/artisan" ]] || die "No artisan at $APP_DIR — set APP_DIR."
command -v nginx >/dev/null || die "nginx is not installed."

log "App:    $APP_DIR"
log "Domain: $DOMAIN"

# --- The TIFF converter --------------------------------------------------------
# ~35 GB of the archive is multi-page G4 TIFF, which no browser will display.
if ! command -v tiff2pdf >/dev/null; then
    log "Installing libtiff-tools (tiff2pdf) ..."
    apt-get update -qq && apt-get install -y libtiff-tools
else
    log "tiff2pdf present."
fi

# --- Derive docroot + php-fpm socket from the live NOC vhost -------------------
# Copying the running vhost beats hardcoding: the fastcgi socket name changes
# with every PHP point release, and a wrong one fails as a 502 at request time
# rather than at `nginx -t`.
DOCROOT="$APP_DIR/public"
REFERENCE_VHOST="$(grep -rlsF "$DOCROOT" /etc/nginx/sites-available/ 2>/dev/null | grep -v "$DOMAIN" | head -1 || true)"
FASTCGI_PASS=""

if [[ -n "$REFERENCE_VHOST" ]]; then
    log "Reference vhost: $REFERENCE_VHOST"
    FASTCGI_PASS="$(grep -oP '^\s*fastcgi_pass\s+\K[^;]+' "$REFERENCE_VHOST" | head -1 || true)"
fi

if [[ -z "$FASTCGI_PASS" ]]; then
    FASTCGI_PASS="unix:$(ls -1 /run/php/php*-fpm.sock 2>/dev/null | sort -V | tail -1 || true)"
    [[ "$FASTCGI_PASS" != "unix:" ]] || die "Could not find a php-fpm socket."
    warn "No reference vhost — using detected socket $FASTCGI_PASS"
fi

log "PHP-FPM: $FASTCGI_PASS"

if [[ -f /etc/nginx/snippets/fastcgi-php.conf ]]; then
    FASTCGI_INCLUDE='include snippets/fastcgi-php.conf;'
else
    FASTCGI_INCLUDE='include fastcgi_params;
        fastcgi_split_path_info ^(.+\.php)(/.+)$;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param PATH_INFO $fastcgi_path_info;
        fastcgi_index index.php;'
fi

# --- ACME webroot --------------------------------------------------------------
mkdir -p "$ACME_ROOT/.well-known/acme-challenge"
chown -R www-data:www-data "$ACME_ROOT"

# --- The vhost -----------------------------------------------------------------
# Port 80 only at first: a 443 block referencing a certificate that does not
# exist yet makes `nginx -t` fail, and then certbot cannot run either.
log "Writing $AVAILABLE"
cat > "$AVAILABLE" <<NGINX
# Document archive subdomain — managed by deployment/archive-portal/setup.sh
# Same app, same docroot as NOC; Laravel routes by Host header.
# TLS is added by certbot (webroot authenticator — see the script header).
server {
    listen 80;
    listen [::]:80;
    server_name ${DOMAIN};

    root ${DOCROOT};
    index index.php;

    charset utf-8;

    # Scans, not forms. Phase 4 uploads whole multi-page PDFs from a scanner.
    client_max_body_size 110M;

    # A big scan streaming off the cifs mount or out of Azure can outlast the
    # default 60s, and the result is a 504 halfway through a document.
    fastcgi_read_timeout 300s;

    access_log /var/log/nginx/${DOMAIN}.access.log;
    error_log  /var/log/nginx/${DOMAIN}.error.log;

    location ^~ /.well-known/acme-challenge/ {
        root ${ACME_ROOT};
        default_type "text/plain";
    }

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    location ~ \.php\$ {
        ${FASTCGI_INCLUDE}
        fastcgi_pass ${FASTCGI_PASS};
        fastcgi_param HTTP_PROXY "";
    }

    location ~ /\.(?!well-known).* { deny all; }
}
NGINX

# The specific-address block. Without this, ACME validation for this name never
# reaches the vhost above — see the header. Named zz- so it sorts after the
# firmware vhost, which must stay the default_server.
log "Writing the ACME block on ${INTERNAL_IP}:80"
cat > "/etc/nginx/sites-available/zz-${DOMAIN}-http" <<NGINX
# ACME + redirect for ${DOMAIN} on the SPECIFIC address Azure NATs to.
# nginx picks a server block by listen specificity before server_name, so a
# wildcard block never sees these requests.
server {
    listen ${INTERNAL_IP}:80;
    server_name ${DOMAIN};

    location ^~ /.well-known/acme-challenge/ {
        root ${ACME_ROOT};
        default_type "text/plain";
    }

    location / { return 301 https://\$host\$request_uri; }
}
NGINX

ln -sfn "$AVAILABLE" "$ENABLED"
ln -sfn "/etc/nginx/sites-available/zz-${DOMAIN}-http" "/etc/nginx/sites-enabled/zz-${DOMAIN}-http"

log "Testing nginx config ..."
nginx -t || die "nginx config test failed — NOT applied."
systemctl reload nginx
log "nginx reloaded."

# --- TLS -----------------------------------------------------------------------
if [[ "${SKIP_TLS:-0}" == "1" ]]; then
    warn "SKIP_TLS=1 — no certificate. Later:"
    warn "    sudo certbot certonly -a webroot -w $ACME_ROOT -d $DOMAIN"
elif [[ -d "/etc/letsencrypt/live/${DOMAIN}" ]]; then
    log "Certificate already exists for $DOMAIN."
elif ! command -v certbot >/dev/null; then
    warn "certbot is not installed."
else
    log "Obtaining certificate (webroot) ..."
    CERTBOT_ARGS=(certonly -a webroot -w "$ACME_ROOT" -d "$DOMAIN" --non-interactive --agree-tos)
    [[ -n "${CERTBOT_EMAIL:-}" ]] && CERTBOT_ARGS+=(-m "$CERTBOT_EMAIL")

    if certbot "${CERTBOT_ARGS[@]}"; then
        log "Certificate issued. Adding the TLS server block ..."
        cat >> "$AVAILABLE" <<NGINX

server {
    listen 443 ssl;
    listen [::]:443 ssl;
    http2 on;
    server_name ${DOMAIN};

    ssl_certificate     /etc/letsencrypt/live/${DOMAIN}/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/${DOMAIN}/privkey.pem;
    include /etc/letsencrypt/options-ssl-nginx.conf;
    ssl_dhparam /etc/letsencrypt/ssl-dhparams.pem;

    root ${DOCROOT};
    index index.php;
    charset utf-8;
    client_max_body_size 110M;
    fastcgi_read_timeout 300s;

    access_log /var/log/nginx/${DOMAIN}.access.log;
    error_log  /var/log/nginx/${DOMAIN}.error.log;

    location / { try_files \$uri \$uri/ /index.php?\$query_string; }
    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    location ~ \.php\$ {
        ${FASTCGI_INCLUDE}
        fastcgi_pass ${FASTCGI_PASS};
        fastcgi_param HTTP_PROXY "";
    }

    location ~ /\.(?!well-known).* { deny all; }
}
NGINX
        nginx -t && systemctl reload nginx && log "TLS live."
    else
        warn "certbot failed. The site is up over HTTP; fix DNS then re-run."
    fi
fi

# --- App -----------------------------------------------------------------------
APP_OWNER="$(stat -c '%U' "$APP_DIR/artisan")"

log "Clearing cached config + routes (as $APP_OWNER) ..."
sudo -u "$APP_OWNER" php "$APP_DIR/artisan" config:clear >/dev/null
sudo -u "$APP_OWNER" php "$APP_DIR/artisan" route:clear  >/dev/null

log "Running migrations ..."
sudo -u "$APP_OWNER" php "$APP_DIR/artisan" migrate --force

# --- Smoke test ----------------------------------------------------------------
# Proves both halves at once: the vhost resolves, and host isolation is live.
log "Smoke testing ..."
probe() { curl -s -o /dev/null -w '%{http_code}' -H "Host: ${DOMAIN}" "http://127.0.0.1$1" || echo "ERR"; }

LOGIN_CODE="$(probe /login)"
ADMIN_CODE="$(probe /admin/employees)"
FAILED=0

[[ "$LOGIN_CODE" == "200" ]] \
    && log "  /login           -> 200 OK" \
    || { warn "  /login           -> $LOGIN_CODE (expected 200)"; FAILED=1; }

[[ "$ADMIN_CODE" == "404" ]] \
    && log "  /admin/employees -> 404 (isolated, correct)" \
    || { warn "  /admin/employees -> $ADMIN_CODE (expected 404 — HOST ISOLATION IS NOT WORKING)"; FAILED=1; }

echo
[[ $FAILED -eq 0 ]] && log "Done. https://${DOMAIN}/ is ready." || warn "Done with warnings — see above."

cat <<REMINDER

  TWO MANUAL STEPS REMAIN — sign-in and file reading will fail without them.

  1. Entra App Registration -> Authentication -> Web -> Redirect URIs, add:

         https://${DOMAIN}/auth/microsoft/callback

     Without it every sign-in fails with AADSTS50011.

  2. Mount the ArcMate share, read-only:

         sudo bash deployment/archive-portal/mount-arcmate.sh

  Then grant people access: Admin -> Users -> Permissions -> use-archive-portal,
  and add them to individual archives at https://${DOMAIN}/manage.

REMINDER
