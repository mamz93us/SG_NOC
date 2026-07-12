#!/usr/bin/env bash
# =============================================================================
# Install + configure the SG-NOC local SMTP relay (Postfix -> Amazon SES).
#
# Run on the NOC VM as root (or with sudo). Idempotent: safe to re-run after a
# `git pull` to push config changes.
#
#   sudo ./setup.sh                 # reads AWS creds from /home/azureuser/phonebook2/.env
#   sudo APP_ENV_FILE=/path/.env ./setup.sh
#   sudo AWS_ACCESS_KEY_ID=... AWS_SECRET_ACCESS_KEY=... ./setup.sh
#
# See ../../SMTP_RELAY_SETUP.md for the full runbook and prerequisites.
# =============================================================================
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_ENV_FILE="${APP_ENV_FILE:-/home/azureuser/phonebook2/.env}"
SES_REGION="${SES_REGION:-us-east-1}"

if [[ $EUID -ne 0 ]]; then
    echo "This script must run as root (use sudo)." >&2
    exit 1
fi

# --- Resolve AWS credentials -------------------------------------------------
# Priority: explicit env vars > app .env file.
read_env() {  # $1 = key -> prints value from APP_ENV_FILE, unquoted
    [[ -f "$APP_ENV_FILE" ]] || return 0
    sed -n "s/^$1=//p" "$APP_ENV_FILE" | tail -n1 | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'\$//"
}

AWS_ACCESS_KEY_ID="${AWS_ACCESS_KEY_ID:-$(read_env AWS_ACCESS_KEY_ID)}"
AWS_SECRET_ACCESS_KEY="${AWS_SECRET_ACCESS_KEY:-$(read_env AWS_SECRET_ACCESS_KEY)}"
SES_REGION="$(read_env AWS_DEFAULT_REGION || true)"; SES_REGION="${SES_REGION:-us-east-1}"

if [[ -z "${AWS_ACCESS_KEY_ID:-}" || -z "${AWS_SECRET_ACCESS_KEY:-}" ]]; then
    echo "Could not resolve AWS creds. Set AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY," >&2
    echo "or point APP_ENV_FILE at the app .env (tried: $APP_ENV_FILE)." >&2
    exit 1
fi

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
# Pin myhostname to this box's FQDN so SES/EHLO look sane.
postconf -e "myhostname=$(hostname -f 2>/dev/null || hostname)"

# --- Build the SASL password map (SES SMTP creds derived from AWS secret) -----
SMTP_PASS="$(bash "$HERE/ses-smtp-password.sh" "$AWS_SECRET_ACCESS_KEY" "$SES_REGION")"
umask 077
printf '[email-smtp.%s.amazonaws.com]:587 %s:%s\n' \
    "$SES_REGION" "$AWS_ACCESS_KEY_ID" "$SMTP_PASS" > /etc/postfix/sasl_passwd
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
