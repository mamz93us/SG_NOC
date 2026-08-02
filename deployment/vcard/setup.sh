#!/usr/bin/env bash
# =============================================================================
# Provision the NOC VM for the digital business card subdomain.
#
# Creates the nginx vhost, obtains the TLS certificate, clears the app's cached
# config/routes, and smoke-tests host isolation.
#
# Run on the NOC VM as root (or with sudo). Idempotent: safe to re-run after a
# `git pull`.
#
#   sudo bash deployment/vcard/setup.sh
#   sudo VCARD_DOMAIN=cards.samirgroup.com bash deployment/vcard/setup.sh
#   sudo CERTBOT_EMAIL=it@samirgroup.com bash deployment/vcard/setup.sh
#   sudo SKIP_DNS_CHECK=1 bash deployment/vcard/setup.sh    # DNS not propagated yet
#   sudo SKIP_TLS=1 bash deployment/vcard/setup.sh          # HTTP only, cert later
#
# The vhost is derived from the EXISTING NOC vhost (docroot + php-fpm socket) so
# this keeps working across PHP upgrades instead of hardcoding php8.2.
#
# See ../../docs/VCARD_SUBDOMAIN.md for the full runbook, including the Entra
# redirect URI that must be registered separately.
# =============================================================================
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="${APP_DIR:-$(cd "$HERE/../.." && pwd)}"
DOMAIN="${VCARD_DOMAIN:-vcard.samirgroup.net}"

AVAILABLE="/etc/nginx/sites-available/${DOMAIN}"
ENABLED="/etc/nginx/sites-enabled/${DOMAIN}"

log()  { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[warn]\033[0m %s\n' "$*"; }
die()  { printf '\033[1;31m[fail]\033[0m %s\n' "$*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || die "This script must run as root (use sudo)."
[[ -f "$APP_DIR/artisan" ]] || die "No artisan at $APP_DIR — set APP_DIR to the app root."
command -v nginx >/dev/null || die "nginx is not installed."

log "App:    $APP_DIR"
log "Domain: $DOMAIN"

# --- Derive docroot + php-fpm socket from the existing NOC vhost --------------
# Copying the live vhost beats hardcoding: the fastcgi socket name changes with
# every PHP point release, and a wrong one fails as a 502 at request time rather
# than at `nginx -t`, which is a miserable thing to debug.
DOCROOT="$APP_DIR/public"
FASTCGI_PASS=""

REFERENCE_VHOST="$(grep -rlsF "$DOCROOT" /etc/nginx/sites-available/ 2>/dev/null | grep -v "$DOMAIN" | head -1 || true)"

if [[ -n "$REFERENCE_VHOST" ]]; then
    log "Reference vhost: $REFERENCE_VHOST"
    FASTCGI_PASS="$(grep -oP '^\s*fastcgi_pass\s+\K[^;]+' "$REFERENCE_VHOST" | head -1 || true)"
fi

if [[ -z "$FASTCGI_PASS" ]]; then
    # Fall back to the newest php-fpm socket present on the box.
    FASTCGI_PASS="unix:$(ls -1 /run/php/php*-fpm.sock 2>/dev/null | sort -V | tail -1 || true)"
    [[ "$FASTCGI_PASS" != "unix:" ]] || die "Could not find a php-fpm socket. Set FASTCGI_PASS manually."
    warn "No reference vhost found — using detected socket $FASTCGI_PASS"
fi

log "Docroot: $DOCROOT"
log "PHP-FPM: $FASTCGI_PASS"
[[ -d "$DOCROOT" ]] || die "Docroot $DOCROOT does not exist."

# The fastcgi-php.conf snippet is Debian/Ubuntu packaging. Inline the equivalent
# if it's absent so this doesn't emit a vhost that fails `nginx -t`.
if [[ -f /etc/nginx/snippets/fastcgi-php.conf ]]; then
    FASTCGI_INCLUDE='include snippets/fastcgi-php.conf;'
else
    warn "snippets/fastcgi-php.conf not found — inlining fastcgi params."
    FASTCGI_INCLUDE='include fastcgi_params;
        fastcgi_split_path_info ^(.+\.php)(/.+)$;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param PATH_INFO $fastcgi_path_info;
        fastcgi_index index.php;'
fi

# --- Pre-flight: does DNS actually point here? --------------------------------
# certbot fails the HTTP-01 challenge if it doesn't, with a much less obvious
# error than this one.
#
# The authoritative comparison is against the host already serving this app, NOT
# against our egress IP: on Azure the outbound address (NAT gateway / LB) is
# frequently not the inbound one, so an egress comparison false-fails on a
# perfectly correct A record. Egress is only the fallback when no sibling host
# can be resolved.
if [[ "${SKIP_DNS_CHECK:-0}" != "1" && "${SKIP_TLS:-0}" != "1" ]]; then
    log "Checking DNS for $DOMAIN ..."

    # ahostsv4, not `hosts`: we are comparing against an A record, and `getent
    # hosts` can hand back a AAAA first.
    resolve4() { getent ahostsv4 "$1" 2>/dev/null | awk '{print $1}' | head -1 || true; }

    RESOLVED="$(resolve4 "$DOMAIN")"
    [[ -n "$RESOLVED" ]] || die "$DOMAIN does not resolve yet.
     Add an A record for it pointing at the same address as the host already
     serving this app, then re-run. To provision the vhost now and add TLS
     later, re-run with SKIP_DNS_CHECK=1 (TLS will be skipped)."

    # A sibling only counts if it resolves to a PUBLIC address. This box pins
    # noc.samirgroup.net to 127.0.0.1 in /etc/hosts (the telnet-proxy loopback
    # fix), and other hosts may resolve to tunnel/private addresses — comparing
    # against those would tell you to point the A record at loopback.
    is_public_ip() {
        case "$1" in
            127.*|10.*|192.168.*|169.254.*|0.*|'') return 1 ;;
            172.1[6-9].*|172.2[0-9].*|172.3[01].*) return 1 ;;
            *) return 0 ;;
        esac
    }

    # Consider every server_name across the enabled vhosts, not just the
    # reference one, and take the first that yields a public address.
    SIBLING_HOST=""; SIBLING_IP=""
    while read -r candidate; do
        [[ -n "$candidate" && "$candidate" != "$DOMAIN" ]] || continue
        ip="$(resolve4 "$candidate")"
        if is_public_ip "$ip"; then
            SIBLING_HOST="$candidate"; SIBLING_IP="$ip"; break
        fi
    done < <(grep -hoP '^\s*server_name\s+\K[^;]+' /etc/nginx/sites-available/* 2>/dev/null \
                | tr ' ' '\n' | grep -vE '^(_|\*.*|)$' | sort -u)

    if [[ -n "$SIBLING_IP" ]]; then
        if [[ "$RESOLVED" == "$SIBLING_IP" ]]; then
            log "DNS OK: $DOMAIN -> $RESOLVED (matches $SIBLING_HOST)"
        else
            die "$DOMAIN resolves to $RESOLVED, but $SIBLING_HOST — already served by
     this box — resolves to $SIBLING_IP. Point the A record at $SIBLING_IP.
     Re-run with SKIP_DNS_CHECK=1 to continue anyway."
        fi
    else
        # No sibling to compare against; fall back to the egress address.
        PUBLIC_IP="$(curl -4 -s --max-time 10 https://api.ipify.org || curl -4 -s --max-time 10 https://ifconfig.me || true)"
        if [[ -n "$PUBLIC_IP" && "$RESOLVED" != "$PUBLIC_IP" ]]; then
            warn "$DOMAIN resolves to $RESOLVED; this VM's egress IP is $PUBLIC_IP."
            warn "On Azure inbound and outbound addresses often differ, so this may be fine."
            warn "Continuing — certbot will be the real test."
        else
            log "DNS OK: $DOMAIN -> $RESOLVED"
        fi
    fi
fi

# --- Write the vhost (HTTP only) ----------------------------------------------
# Deliberately port 80 only. Writing a 443 block that references a certificate
# which does not exist yet makes `nginx -t` fail, and then certbot can't run
# either — the classic chicken-and-egg. certbot --nginx adds the TLS block and
# the HTTP->HTTPS redirect once the cert is issued.
log "Writing $AVAILABLE"
cat > "$AVAILABLE" <<NGINX
# Digital business card subdomain — managed by deployment/vcard/setup.sh
# Same app, same docroot as NOC; Laravel routes by Host header.
# TLS + the HTTP->HTTPS redirect are added by certbot.
server {
    listen 80;
    listen [::]:80;
    server_name ${DOMAIN};

    root ${DOCROOT};
    index index.php;

    charset utf-8;
    client_max_body_size 20M;

    access_log /var/log/nginx/${DOMAIN}.access.log;
    error_log  /var/log/nginx/${DOMAIN}.error.log;

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

ln -sfn "$AVAILABLE" "$ENABLED"

log "Testing nginx config ..."
nginx -t || die "nginx config test failed — the vhost above was NOT applied."
systemctl reload nginx
log "nginx reloaded."

# --- TLS ----------------------------------------------------------------------
if [[ "${SKIP_TLS:-0}" == "1" ]]; then
    warn "SKIP_TLS=1 — no certificate issued. Run later:"
    warn "    sudo certbot --nginx -d $DOMAIN --redirect"
elif [[ -d "/etc/letsencrypt/live/${DOMAIN}" ]]; then
    log "Certificate already exists for $DOMAIN — skipping certbot."
elif ! command -v certbot >/dev/null; then
    warn "certbot is not installed. Install it and run:"
    warn "    sudo certbot --nginx -d $DOMAIN --redirect"
else
    log "Obtaining certificate for $DOMAIN ..."
    CERTBOT_ARGS=(--nginx -d "$DOMAIN" --non-interactive --agree-tos --redirect)
    [[ -n "${CERTBOT_EMAIL:-}" ]] && CERTBOT_ARGS+=(-m "$CERTBOT_EMAIL")
    if certbot "${CERTBOT_ARGS[@]}"; then
        log "Certificate installed."
    else
        warn "certbot failed. The site is live over HTTP; fix DNS/firewall then re-run:"
        warn "    sudo certbot --nginx -d $DOMAIN --redirect"
    fi
fi

# --- App config ---------------------------------------------------------------
# The host is baked into the cached route table, so a stale cache silently serves
# NOC on this domain. Run as the app owner to avoid root-owned cache files.
APP_OWNER="$(stat -c '%U' "$APP_DIR/artisan")"

if [[ "$DOMAIN" != "vcard.samirgroup.net" ]]; then
    log "Pinning VCARD_DOMAIN=$DOMAIN in .env"
    if grep -q '^VCARD_DOMAIN=' "$APP_DIR/.env" 2>/dev/null; then
        sed -i "s|^VCARD_DOMAIN=.*|VCARD_DOMAIN=${DOMAIN}|" "$APP_DIR/.env"
    else
        printf '\nVCARD_DOMAIN=%s\n' "$DOMAIN" >> "$APP_DIR/.env"
    fi
fi

log "Clearing cached config + routes (as $APP_OWNER) ..."
sudo -u "$APP_OWNER" php "$APP_DIR/artisan" config:clear >/dev/null
sudo -u "$APP_OWNER" php "$APP_DIR/artisan" route:clear  >/dev/null

# --- Smoke test ---------------------------------------------------------------
# Hit the local nginx with the card Host header. This proves both halves at once:
# the vhost resolves, and host isolation is live.
log "Smoke testing ..."
probe() { curl -s -o /dev/null -w '%{http_code}' -H "Host: ${DOMAIN}" "http://127.0.0.1$1" || echo "ERR"; }

LOGIN_CODE="$(probe /login)"
ADMIN_CODE="$(probe /admin/employees)"
FAILED=0

if [[ "$LOGIN_CODE" == "200" ]]; then
    log "  /login             -> 200 OK"
else
    warn "  /login             -> $LOGIN_CODE (expected 200)"; FAILED=1
fi

if [[ "$ADMIN_CODE" == "404" ]]; then
    log "  /admin/employees   -> 404 (isolated, correct)"
else
    warn "  /admin/employees   -> $ADMIN_CODE (expected 404 — host isolation is NOT working)"; FAILED=1
fi

echo
if [[ $FAILED -eq 0 ]]; then
    log "Done. https://${DOMAIN}/ is ready."
else
    warn "Done with warnings — see above. Check /var/log/nginx/${DOMAIN}.error.log"
fi

cat <<REMINDER

  ONE MANUAL STEP REMAINS — sign-in will fail without it.

  In the Entra App Registration used for SSO, add this Web redirect URI:

      https://${DOMAIN}/auth/microsoft/callback

  Portal: https://entra.microsoft.com -> App registrations -> (your app)
          -> Authentication -> Web -> Redirect URIs -> Add URI -> Save

REMINDER
