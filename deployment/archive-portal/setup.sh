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
# Written WHOLE on every run, by one function, because this script has to be safe
# to re-run. The first version always wrote an HTTP-only config and appended the
# TLS block only in the branch that obtained a certificate -- so re-running it on
# a working site silently deleted that site's HTTPS. The file now always states
# exactly what the current certificate state supports.
write_vhost() {
    local with_tls="$1"
    local root_location

    if [[ "$with_tls" == "1" ]]; then
        # TLS is up, so plain HTTP only redirects. The ACME location below is ^~,
        # which outranks this, so certificate renewal still works over port 80.
        root_location='location / { return 301 https://$host$request_uri; }'
    else
        # No certificate yet: serve the app over HTTP so the portal is usable and
        # so certbot has something to validate against.
        root_location='location / { try_files $uri $uri/ /index.php?$query_string; }'
    fi

    cat > "$AVAILABLE" <<NGINX
# Document archive subdomain -- GENERATED by deployment/archive-portal/setup.sh.
# Edit the script, not this file: a re-run overwrites it.
# Same app and docroot as NOC; Laravel routes by Host header.
server {
    listen 80;
    listen [::]:80;
    server_name ${DOMAIN};

    root ${DOCROOT};
    index index.php;

    charset utf-8;

    # Scans, not forms: a multi-page PDF off a scanner is the normal upload.
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

    ${root_location}

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

    [[ "$with_tls" == "1" ]] || return 0

    cat >> "$AVAILABLE" <<NGINX

server {
    # The listen-line form, NOT the newer http2 directive: nginx on this host is
    # 1.24, where "http2 on;" is an unknown directive that makes the WHOLE config
    # invalid. nginx then keeps serving its old config while every later reload or
    # reboot fails -- a latent outage rather than an obvious one.
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
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
}

# Write it, test it, and NEVER leave an invalid file on disk: nginx would carry on
# with its old in-memory config and then fail the next reload or reboot.
apply_vhost() {
    local with_tls="$1" backup=""

    if [[ -f "$AVAILABLE" ]]; then
        backup="${AVAILABLE}.prev.$(date +%Y%m%d%H%M%S)"
        cp -a "$AVAILABLE" "$backup"
    fi

    write_vhost "$with_tls"

    if nginx -t 2>/dev/null; then
        systemctl reload nginx
        return 0
    fi

    warn "The generated vhost did not validate. nginx -t says:"
    nginx -t 2>&1 | sed 's/^/      /' || true

    if [[ -n "$backup" ]]; then
        cp -a "$backup" "$AVAILABLE"
        warn "Reverted to the previous vhost."
    else
        rm -f "$ENABLED"
        warn "Removed the broken vhost."
    fi

    nginx -t >/dev/null 2>&1 && systemctl reload nginx
    return 1
}

# The ACME block on the SPECIFIC address Azure NATs to. Without it, validation for
# this name never reaches the vhost above: nginx picks a server block by listen
# specificity before it looks at server_name, and the firmware vhost binds
# ${INTERNAL_IP}:80 while everything else uses a wildcard. Named zz- so it sorts
# after the firmware vhost, which must stay the default_server.
log "Writing the ACME block on ${INTERNAL_IP}:80"
cat > "/etc/nginx/sites-available/zz-${DOMAIN}-http" <<NGINX
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

# --- TLS -----------------------------------------------------------------------
# Decided BEFORE the config is written, so a re-run on a provisioned host
# reproduces its working config instead of deleting it.
TLS_OK=0
[[ -d "/etc/letsencrypt/live/${DOMAIN}" ]] && TLS_OK=1

if [[ "${SKIP_TLS:-0}" == "1" ]]; then
    TLS_OK=0
    warn "SKIP_TLS=1 -- HTTP only. To add TLS later:"
    warn "    sudo certbot certonly -a webroot -w $ACME_ROOT -d $DOMAIN"
fi

if [[ "$TLS_OK" == "1" ]]; then
    log "Writing $AVAILABLE (with TLS -- certificate already present)"
else
    log "Writing $AVAILABLE (HTTP only for now)"
fi

apply_vhost "$TLS_OK" || die "Could not install a working vhost."

# Only now, with port 80 serving the ACME path, is it worth asking for a cert.
if [[ "$TLS_OK" == "0" && "${SKIP_TLS:-0}" != "1" ]]; then
    if ! command -v certbot >/dev/null; then
        warn "certbot is not installed; no certificate obtained."
    else
        log "Obtaining certificate (webroot) ..."
        CERTBOT_ARGS=(certonly -a webroot -w "$ACME_ROOT" -d "$DOMAIN" --non-interactive --agree-tos)
        [[ -n "${CERTBOT_EMAIL:-}" ]] && CERTBOT_ARGS+=(-m "$CERTBOT_EMAIL")

        if certbot "${CERTBOT_ARGS[@]}"; then
            log "Certificate issued. Rewriting the vhost with TLS ..."
            if apply_vhost 1; then
                TLS_OK=1
                log "TLS live; HTTP redirects to it."
            fi
        else
            warn "certbot failed. The site is up over HTTP; fix DNS then re-run."
        fi
    fi
fi

# --- App -----------------------------------------------------------------------
# Run artisan as the WEB user, not as the file owner.
#
# On this host the app files belong to azureuser while PHP-FPM runs as www-data,
# and azureuser is not in the www-data group. artisan writes to bootstrap/cache
# and storage/logs, so running it as the owner can leave cache and log files
# PHP-FPM cannot rewrite — the app breaking *after* a deploy that reported
# success. The scheduler is the opposite case and runs its own commands as
# azureuser; see the "scheduler runs as azureuser" gotcha in CLAUDE.md.
WEB_USER="${WEB_USER:-www-data}"
id -u "$WEB_USER" >/dev/null 2>&1 || die "No such user: $WEB_USER (override with WEB_USER=)."

log "Clearing cached config + routes (as $WEB_USER) ..."
sudo -u "$WEB_USER" php "$APP_DIR/artisan" config:clear >/dev/null
sudo -u "$WEB_USER" php "$APP_DIR/artisan" route:clear  >/dev/null

log "Running migrations ..."
sudo -u "$WEB_USER" php "$APP_DIR/artisan" migrate --force

# --- Smoke test ----------------------------------------------------------------
# Proves both halves at once: the vhost resolves, and host isolation is live.
log "Smoke testing ..."
probe() {
    if [[ "$TLS_OK" == "1" ]]; then
        curl -sk -o /dev/null -w '%{http_code}' --resolve "${DOMAIN}:443:127.0.0.1" "https://${DOMAIN}$1" || echo "ERR"
    else
        curl -s -o /dev/null -w '%{http_code}' -H "Host: ${DOMAIN}" "http://127.0.0.1$1" || echo "ERR"
    fi
}

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
if [[ $FAILED -eq 0 && "$TLS_OK" == "1" ]]; then
    log "Done. https://${DOMAIN}/ is ready."
elif [[ "$TLS_OK" != "1" ]]; then
    # Saying "ready" with no working certificate is how a broken deploy gets
    # signed off. Be explicit about what is and is not up.
    warn "Done, but WITHOUT TLS — http://${DOMAIN}/ only. See the warnings above."
else
    warn "Done with warnings — see above."
fi

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
