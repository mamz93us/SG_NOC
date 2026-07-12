#!/usr/bin/env bash
# =============================================================================
# Install + configure the SG-NOC local SMTP relay (Postfix -> Amazon SES).
#
# Run on the NOC VM as root (or with sudo). Idempotent: safe to re-run after a
# `git pull` to push config changes.
#
# SES credentials come from the app's stored Email Marketing settings (encrypted
# at rest), read via `php artisan smtp-relay:sasl-line`. No AWS keys in .env are
# needed. You can still override with explicit env vars:
#
#   sudo bash setup.sh
#   sudo APP_DIR=/home/azureuser/phonebook2 bash setup.sh
#   sudo AWS_ACCESS_KEY_ID=... AWS_SECRET_ACCESS_KEY=... AWS_DEFAULT_REGION=us-east-1 bash setup.sh
#
# See ../../SMTP_RELAY_SETUP.md for the full runbook and prerequisites.
# =============================================================================
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="${APP_DIR:-$(cd "$HERE/../.." && pwd)}"

if [[ $EUID -ne 0 ]]; then
    echo "This script must run as root (use sudo)." >&2
    exit 1
fi

# --- Resolve the SES SASL line -----------------------------------------------
# Produces one Postfix map line: [email-smtp.<region>.amazonaws.com]:587 KEY:PASS
# Priority: explicit AWS_* env vars > the app's stored SES settings (via artisan).
get_sasl_line() {
    if [[ -n "${AWS_ACCESS_KEY_ID:-}" && -n "${AWS_SECRET_ACCESS_KEY:-}" ]]; then
        local region="${AWS_DEFAULT_REGION:-us-east-1}" pass
        pass="$(bash "$HERE/ses-smtp-password.sh" "$AWS_SECRET_ACCESS_KEY" "$region")"
        printf '[email-smtp.%s.amazonaws.com]:587 %s:%s\n' "$region" "$AWS_ACCESS_KEY_ID" "$pass"
        return 0
    fi
    if command -v php >/dev/null 2>&1 && [[ -f "$APP_DIR/artisan" ]]; then
        # Run as the app owner so we don't leave root-owned cache/log files.
        local owner; owner="$(stat -c '%U' "$APP_DIR/artisan")"
        ( cd "$APP_DIR" && sudo -u "$owner" php artisan smtp-relay:sasl-line )
        return $?
    fi
    return 1
}

SASL_LINE="$(get_sasl_line || true)"
if [[ "$SASL_LINE" != \[email-smtp.* ]]; then
    echo "Could not obtain SES credentials. Either:" >&2
    echo "  - set them under Admin -> Email Marketing settings (ses_access_key_id / secret), or" >&2
    echo "  - pass AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY to this script." >&2
    echo "  (app dir tried: $APP_DIR)" >&2
    exit 1
fi
# Extract region from the line to keep relayhost in sync.
SES_REGION="$(printf '%s' "$SASL_LINE" | sed -n 's/^\[email-smtp\.\([^.]*\)\..*/\1/p')"
SES_REGION="${SES_REGION:-us-east-1}"

# --- Install Postfix (non-interactive: no local mail config) -----------------
export DEBIAN_FRONTEND=noninteractive
debconf-set-selections <<< "postfix postfix/main_mailer_type string 'No configuration'"
apt-get update -qq
apt-get install -y -qq postfix

# --- Deploy main.cf ----------------------------------------------------------
if [[ -f /etc/postfix/main.cf ]]; then
    cp -a /etc/postfix/main.cf "/etc/postfix/main.cf.bak.$(date +%Y%m%d%H%M%S)"
fi
install -m 0644 "$HERE/main.cf" /etc/postfix/main.cf
install -m 0644 "$HERE/sender_canonical.regexp" /etc/postfix/sender_canonical.regexp
# Pin myhostname to this box's FQDN so SES/EHLO look sane.
postconf -e "myhostname=$(hostname -f 2>/dev/null || hostname)"

# --- Write the SASL password map ---------------------------------------------
umask 077
printf '%s\n' "$SASL_LINE" > /etc/postfix/sasl_passwd
postmap /etc/postfix/sasl_passwd
chmod 600 /etc/postfix/sasl_passwd /etc/postfix/sasl_passwd.db

# Keep relayhost in sync with the resolved region.
postconf -e "relayhost=[email-smtp.$SES_REGION.amazonaws.com]:587"

# --- Start / reload ----------------------------------------------------------
systemctl enable --now postfix
postfix reload || systemctl restart postfix

echo
echo "SMTP relay is up. Test it (install swaks if needed: apt-get install -y swaks):"
echo "  swaks --server 127.0.0.1:25 --from test@printer.local \\"
echo "        --to you@samirgroup.com --to youralt@gmail.com \\"
echo "        --header 'Subject: SG-NOC relay test'"
echo
echo "Watch delivery:  tail -f /var/log/mail.log"
