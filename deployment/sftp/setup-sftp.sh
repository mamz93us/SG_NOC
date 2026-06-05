#!/usr/bin/env bash
#
# setup-sftp.sh — stand up the chrooted, SFTP-only inbox that network devices
# push their backups into. The Laravel `sftp-backups:sweep` scheduled command
# then streams each file up to Azure Blob and deletes the local copy.
#
# Idempotent: safe to re-run. Run as root on the NOC VM:
#     sudo bash deployment/sftp/setup-sftp.sh
#
# Override any default via env, e.g.:
#     sudo SFTP_USER=backuppush APP_USER=azureuser bash deployment/sftp/setup-sftp.sh
#
# See deployment/sftp/README.md for the full walkthrough.
#
set -euo pipefail

SFTP_GROUP="${SFTP_GROUP:-sftpbackup}"          # group gated by the sshd Match block
SFTP_USER="${SFTP_USER:-backuppush}"            # account devices authenticate as
SFTP_CHROOT="${SFTP_CHROOT:-/srv/sftp-backups}" # chroot root (must stay root-owned)
INBOX_SUBDIR="${INBOX_SUBDIR:-inbox}"           # writable folder under the chroot
APP_USER="${APP_USER:-azureuser}"               # user the Laravel scheduler runs as
KEYDIR="/etc/ssh/sftp-authorized-keys"          # authorized_keys live OUTSIDE the chroot
SSHD_SNIPPET="/etc/ssh/sshd_config.d/60-sftp-backup.conf"

INBOX="${SFTP_CHROOT}/${INBOX_SUBDIR}"

log()  { printf '  \033[1;32m·\033[0m %s\n' "$*"; }
warn() { printf '  \033[1;33m!\033[0m %s\n' "$*"; }
die()  { printf '  \033[1;31m✗ %s\033[0m\n' "$*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || die "Run as root (sudo)."

NOLOGIN="$(command -v nologin || echo /usr/sbin/nologin)"

echo "==> SFTP backup inbox setup"
log "group=${SFTP_GROUP} user=${SFTP_USER} chroot=${SFTP_CHROOT} inbox=${INBOX} app_user=${APP_USER}"

# 1. Group ----------------------------------------------------------------
if ! getent group "$SFTP_GROUP" >/dev/null; then
    groupadd "$SFTP_GROUP"; log "created group ${SFTP_GROUP}"
else
    log "group ${SFTP_GROUP} exists"
fi

# 2. SFTP user: no shell, locked password, primary group = SFTP_GROUP, ------
#    home = "/" so the session starts at the chroot root and writes under inbox/.
if ! getent passwd "$SFTP_USER" >/dev/null; then
    useradd -g "$SFTP_GROUP" -s "$NOLOGIN" -M -d / "$SFTP_USER"
    passwd -l "$SFTP_USER" >/dev/null 2>&1 || true
    log "created user ${SFTP_USER} (no shell, locked password)"
else
    usermod -g "$SFTP_GROUP" -s "$NOLOGIN" -d / "$SFTP_USER"
    log "user ${SFTP_USER} exists (normalised shell/home/group)"
fi

# 3. App user joins the group so the sweeper can read + delete inbox files --
if getent passwd "$APP_USER" >/dev/null; then
    if id -nG "$APP_USER" | tr ' ' '\n' | grep -qx "$SFTP_GROUP"; then
        log "${APP_USER} already in ${SFTP_GROUP}"
    else
        usermod -aG "$SFTP_GROUP" "$APP_USER"
        warn "added ${APP_USER} to ${SFTP_GROUP} — restart the scheduler/supervisor so it picks up the new group"
    fi
else
    warn "app user ${APP_USER} not found — re-run with APP_USER=<user>, or add it to ${SFTP_GROUP} by hand"
fi

# 4. Chroot dir: every path component must be root-owned and NOT group/world
#    writable, or sshd refuses to chroot ("bad ownership or modes").
mkdir -p "$SFTP_CHROOT"
chown root:root "$SFTP_CHROOT"
chmod 755 "$SFTP_CHROOT"
log "chroot ${SFTP_CHROOT} → root:root 755"

# 5. Writable inbox: setgid (2) so uploads inherit ${SFTP_GROUP}; group rwx so -
#    the in-group APP_USER can read files and delete them after upload.
mkdir -p "$INBOX"
chown "${SFTP_USER}:${SFTP_GROUP}" "$INBOX"
chmod 2770 "$INBOX"
log "inbox ${INBOX} → ${SFTP_USER}:${SFTP_GROUP} 2770 (setgid)"

# 5b. Grant the app user durable rwx on the inbox via ACLs. Group membership
#     only takes effect on a fresh login, so a long-lived scheduler/SSH session
#     started before this run can't read the 2770 inbox yet — and the SFTP user
#     creates nested subfolders the app user would otherwise be "other" on. An
#     ACL (with default inheritance via -d) grants access immediately and for
#     every future upload, independent of login sessions.
if ! command -v setfacl >/dev/null 2>&1; then
    warn "setfacl not found; attempting to install 'acl'…"
    (apt-get update -qq && apt-get install -y acl) >/dev/null 2>&1 || true
fi
if command -v setfacl >/dev/null 2>&1 && getent passwd "$APP_USER" >/dev/null; then
    setfacl -R    -m "u:${APP_USER}:rwx" "$INBOX"
    setfacl -R -d -m "u:${APP_USER}:rwx" "$INBOX"
    log "ACL: ${APP_USER} granted rwx on ${INBOX} (with default inheritance)"
else
    warn "setfacl unavailable — the sweeper then needs ${APP_USER} in ${SFTP_GROUP} AND a fresh login / 'sudo systemctl restart supervisor'."
fi

# 6. authorized_keys dir, OUTSIDE the chroot (sshd reads keys before chroot) -
mkdir -p "$KEYDIR"
chown root:root "$KEYDIR"
chmod 755 "$KEYDIR"
log "authorized-keys dir ${KEYDIR} ready (device public keys go in ${KEYDIR}/${SFTP_USER})"

# 7. sshd Match snippet ---------------------------------------------------
if [ -d /etc/ssh/sshd_config.d ] && grep -qE '^\s*Include\s+/etc/ssh/sshd_config\.d' /etc/ssh/sshd_config 2>/dev/null; then
    cat > "$SSHD_SNIPPET" <<EOF
# SFTP-only chroot for NOC device backups — managed by deployment/sftp/setup-sftp.sh
# (edit the script, not this file). See deployment/sftp/README.md.
Match Group ${SFTP_GROUP}
    ChrootDirectory ${SFTP_CHROOT}
    ForceCommand internal-sftp -u 0002
    AllowTcpForwarding no
    X11Forwarding no
    PermitTunnel no
    AuthorizedKeysFile ${KEYDIR}/%u
    # PasswordAuthentication yes   # uncomment if a device can ONLY do password auth
EOF
    chmod 644 "$SSHD_SNIPPET"
    log "wrote ${SSHD_SNIPPET}"
else
    warn "/etc/ssh/sshd_config.d is not Include-d by sshd_config."
    warn "Append the Match block from deployment/sftp/sshd_sftp.conf to the END of /etc/ssh/sshd_config by hand (Match blocks must be last)."
fi

# 8. Validate + reload sshd ----------------------------------------------
if sshd -t; then
    if systemctl reload ssh 2>/dev/null || systemctl reload sshd 2>/dev/null; then
        log "sshd config valid; reloaded"
    else
        warn "sshd config valid but reload via systemctl failed — reload it manually"
    fi
else
    die "sshd -t failed — NOT reloading. Fix the config and re-run."
fi

cat <<EOF

==> Done. Next steps:
  1. Give a device access (pick one):
       • key:      install its public key →  ${KEYDIR}/${SFTP_USER}   (root:root, chmod 644)
       • password: 'sudo passwd ${SFTP_USER}', uncomment PasswordAuthentication in
                   ${SSHD_SNIPPET}, then 'sudo sshd -t && sudo systemctl reload ssh'
  2. (Optional) per-source folders so backups are grouped by device in Azure:
       sudo -u ${SFTP_USER} mkdir -p ${INBOX}/sophos-jed ${INBOX}/ucm-cai
     The sweeper records the first folder name as the backup "source".
  3. Point the app at the inbox — in the NOC .env:
       SFTP_BACKUP_INBOX=${INBOX}
     then 'php artisan config:cache'. Confirm the scheduler is running.
  4. Test from a client (you should land in / and only be able to write under ${INBOX_SUBDIR}/):
       sftp ${SFTP_USER}@<noc-host>
       sftp> put backup.tar.gz ${INBOX_SUBDIR}/sophos-jed/
  5. Watch it sweep (no Azure writes):
       php artisan sftp-backups:sweep --dry-run
EOF
