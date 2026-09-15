#!/usr/bin/env bash
# =============================================================================
# Scan-to-e-mail for the document archive.
#
# Teaches the NOC's existing Postfix relay to accept mail for ONE extra domain —
# the scan domain — and drop each message into a spool directory the app reads
# with `archive:ingest-mail`. Everything else about the relay is left exactly as
# it is: the SES smarthost, the sender rewriting and the maillog auditing are
# what the printers' scan-to-email already depends on.
#
# Run on the NOC VM as root, AFTER deployment/smtp-relay/setup.sh:
#
#   sudo bash scan-mail.sh
#
# Idempotent: safe to re-run after a `git pull`.
#
# WHY THIS IS SAFE TO ADD
# -----------------------
# The relay is a pure relay — `mydestination` is empty, local delivery is an
# error — and it only accepts mail from `mynetworks`. This script does not widen
# that: the scan domain is added as a TRANSPORT, not to mydestination, so the
# box still refuses to be a mail server for anything, and only the office
# networks can reach port 25 in the first place.
#
# WHAT THIS DELIBERATELY DOES NOT DO
# ----------------------------------
#   * It does not publish an MX record. The scan domain must NOT resolve
#     publicly: a token in an address is a write credential, and an address the
#     internet can reach is an open door into somebody's inbox.
#   * It does not touch main.cf's relayhost, SASL map or sender_canonical. Those
#     belong to the printer relay and re-running smtp-relay/setup.sh owns them.
# =============================================================================
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="${APP_DIR:-$(cd "$HERE/../.." && pwd)}"

SCAN_DOMAIN="${SCAN_DOMAIN:-scan.archive.samirgroup.net}"
SPOOL_ROOT="${SPOOL_ROOT:-/var/spool/archive-mail}"

if [[ $EUID -ne 0 ]]; then
    echo "This script must run as root (use sudo)." >&2
    exit 1
fi

if ! command -v postconf >/dev/null 2>&1; then
    echo "Postfix is not installed. Run deployment/smtp-relay/setup.sh first." >&2
    exit 1
fi

# The app user, so the spool is readable by the scheduler that drains it.
APP_OWNER="$(stat -c '%U' "$APP_DIR/artisan" 2>/dev/null || echo azureuser)"

echo "Scan domain : $SCAN_DOMAIN"
echo "Spool       : $SPOOL_ROOT"
echo "App user    : $APP_OWNER"
echo

# --- Spool directories -------------------------------------------------------
# new/   Postfix writes here, the app reads here
# done/  read successfully, kept briefly as evidence
# failed/ nothing could be made of it — kept for support questions
#
# Owned by the app user with the group set to it as well: Postfix's pipe runs as
# the app user (see master.cf below), so both sides are the same account and
# there is no www-data/azureuser split to get wrong here.
for dir in new done failed; do
    install -d -o "$APP_OWNER" -g "$APP_OWNER" -m 0750 "$SPOOL_ROOT/$dir"
done

# --- The delivery agent ------------------------------------------------------
# Writes the raw message to a uniquely named file in new/. Named with a leading
# dot and renamed into place, so the app never reads a half-written message.
DROP=/usr/local/bin/archive-scan-drop
cat > "$DROP" <<'SCRIPT'
#!/usr/bin/env bash
# Postfix pipe delivery agent: stdin (one raw message) -> the archive spool.
# Renamed into place after writing so a reader never sees a partial file.
set -euo pipefail
SPOOL="${ARCHIVE_MAIL_SPOOL:-/var/spool/archive-mail}/new"
NAME="$(date +%Y%m%d-%H%M%S)-$$-$RANDOM.eml"
umask 027
cat > "$SPOOL/.$NAME"
mv "$SPOOL/.$NAME" "$SPOOL/$NAME"
SCRIPT
chmod 0755 "$DROP"

# --- master.cf: the transport ------------------------------------------------
# One pipe service. flags=DRhu gives us the headers Postfix knows about,
# including Delivered-To, which is what the app routes on.
if grep -q '^archive-scan\b' /etc/postfix/master.cf; then
    echo "master.cf already has the archive-scan transport."
else
    cp -a /etc/postfix/master.cf "/etc/postfix/master.cf.bak.$(date +%Y%m%d%H%M%S)"
    cat >> /etc/postfix/master.cf <<EOF

# Document archive scan-to-email: hand the raw message to the app's spool.
# user= is the app account so the scheduler can read what lands here.
archive-scan unix -       n       n       -       -       pipe
  flags=DRhu user=$APP_OWNER argv=$DROP
EOF
    echo "Added the archive-scan transport to master.cf."
fi

# --- Route the scan domain to it ---------------------------------------------
# A transport map entry, NOT mydestination: the box stays a pure relay.
TRANSPORT=/etc/postfix/transport
touch "$TRANSPORT"

if grep -qE "^${SCAN_DOMAIN//./\\.}[[:space:]]" "$TRANSPORT"; then
    echo "transport already routes $SCAN_DOMAIN."
else
    printf '%s\tarchive-scan:\n' "$SCAN_DOMAIN" >> "$TRANSPORT"
    echo "Routed $SCAN_DOMAIN to archive-scan."
fi

postmap "$TRANSPORT"

# relay_domains is what makes Postfix accept mail for the domain at all, and
# reject_unauth_destination in main.cf still limits WHO may send it to
# mynetworks. Appended rather than replaced, so an existing value survives.
CURRENT_RELAY="$(postconf -h relay_domains 2>/dev/null || true)"
if [[ "$CURRENT_RELAY" == *"$SCAN_DOMAIN"* ]]; then
    echo "relay_domains already includes $SCAN_DOMAIN."
else
    if [[ -n "$CURRENT_RELAY" ]]; then
        postconf -e "relay_domains=$CURRENT_RELAY, $SCAN_DOMAIN"
    else
        postconf -e "relay_domains=$SCAN_DOMAIN"
    fi
    echo "Added $SCAN_DOMAIN to relay_domains."
fi

CURRENT_MAPS="$(postconf -h transport_maps 2>/dev/null || true)"
if [[ "$CURRENT_MAPS" != *"hash:/etc/postfix/transport"* ]]; then
    postconf -e "transport_maps=hash:/etc/postfix/transport${CURRENT_MAPS:+, $CURRENT_MAPS}"
    echo "Enabled transport_maps."
fi

# A scan is big. Postfix's default message_size_limit is 10 MB, which silently
# bounces a 40-page colour scan — keep it in step with the app's own limit.
postconf -e "message_size_limit=$(( ${ARCHIVE_MAX_SCAN_MB:-50} * 1024 * 1024 ))"

# --- Prune the spool ---------------------------------------------------------
# done/ and failed/ are evidence, not a queue. Two weeks is long enough to
# answer "did the copier send it?" and short enough not to accumulate scans of
# invoices on a VM disk indefinitely.
CRON=/etc/cron.daily/archive-scan-spool-prune
cat > "$CRON" <<EOF
#!/bin/sh
# Drop read and rejected scan mail after 14 days (see scan-mail.sh).
find $SPOOL_ROOT/done $SPOOL_ROOT/failed -type f -mtime +14 -delete 2>/dev/null || true
EOF
chmod 0755 "$CRON"

# --- Apply -------------------------------------------------------------------
postfix reload || systemctl restart postfix

cat <<EOF

Scan-to-email is set up.

  Domain  : $SCAN_DOMAIN   (transport only — the box is still a pure relay)
  Spool   : $SPOOL_ROOT/new  ->  archive:ingest-mail (runs every minute)

STILL TO DO, and none of it belongs in this script:

  1. DNS. Add an INTERNAL-only A/MX record for $SCAN_DOMAIN pointing at the NOC.
     Do NOT publish it publicly: the token in a scan address is a write
     credential, and a publicly reachable address is an open door into an inbox.

  2. Add the address to each copier, from the portal's Scan destinations page.
     Every destination has its own address; the token is shown once.

Test it from a machine inside a mynetworks subnet:

  swaks --server <noc-ip>:25 --from copier@branch.local \\
        --to u-<token>@$SCAN_DOMAIN \\
        --attach-type application/pdf --attach /path/to/a.pdf \\
        --header 'Subject: scan test'

Then watch it arrive:

  ls -l $SPOOL_ROOT/new
  sudo -u $APP_OWNER php $APP_DIR/artisan archive:ingest-mail
EOF
