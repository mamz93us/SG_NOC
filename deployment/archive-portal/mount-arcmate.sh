#!/usr/bin/env bash
# =============================================================================
# Mount the ArcMate file share on the NOC, READ-ONLY.
#
# The document archive reads ArcMate's files straight off the Windows share
# until the transfer worker has copied each one to Azure and verified it. This
# sets that mount up and makes it survive a reboot.
#
#   sudo bash deployment/archive-portal/mount-arcmate.sh
#   sudo ARCMATE_HOST=172.16.8.10 ARCMATE_SHARE=d/ArcRepositories bash ...
#
# THE CREDENTIALS FILE IS NOT WRITTEN BY THIS SCRIPT, on purpose. It prints
# exactly what to put in it and stops. A password typed into a script ends up in
# shell history, in the file's own backups, and in whatever copies of the repo
# exist — which is the precise mistake ArcMate already made: its `sa` password
# sits in plain text in every project.config on this very share.
#
# Use a DEDICATED read-only Windows account, never a domain admin and never the
# account a person signs in with.
# =============================================================================
set -euo pipefail

HOST="${ARCMATE_HOST:-172.16.8.10}"
# ArcMate exposes the repositories TWICE: the whole D: drive is shared as "d"
# (so \host\d\ArcRepositories works), and there is a dedicated
# ArcRepositories share pointing at the same folder. Use the dedicated one:
# it is what production mounts, and share-level access can then be granted to
# the read-only account without also exposing the rest of D:.
#
# If a host only has the drive share, override it -- mount.cifs accepts a
# subdirectory in the UNC:
#
#     sudo ARCMATE_SHARE=d/ArcRepositories bash mount-arcmate.sh
SHARE="${ARCMATE_SHARE:-ArcRepositories}"
MOUNT="${ARCMATE_MOUNT:-/mnt/arcmate}"
CRED="${ARCMATE_CRED:-/etc/arcmate-share.cred}"
APP_USER="${APP_USER:-azureuser}"
WEB_USER="${WEB_USER:-www-data}"

log()  { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[warn]\033[0m %s\n' "$*"; }
die()  { printf '\033[1;31m[fail]\033[0m %s\n' "$*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || die "Run as root (sudo)."

# --- cifs-utils ---------------------------------------------------------------
if ! command -v mount.cifs >/dev/null; then
    log "Installing cifs-utils ..."
    apt-get update -qq && apt-get install -y cifs-utils
fi

# --- The credentials file, which the admin writes ------------------------------
if [[ ! -f "$CRED" ]]; then
    cat <<REMINDER

  The credentials file does not exist yet. Create it yourself:

      sudo install -m 600 -o root -g root /dev/null $CRED
      sudo nano $CRED

  Put exactly three lines in it, for a READ-ONLY account that can see
  \\\\$HOST\\$SHARE and nothing else:

      username=noc_archive
      password=<that account's own password>
      domain=<AD domain, or the server name for a local account>

  Then run this script again.

REMINDER
    exit 1
fi

# The password lives in this file, so nobody but root may read it.
chown root:root "$CRED"
chmod 600 "$CRED"

# --- Mount point ---------------------------------------------------------------
# The web user serves the files and the scheduler reads them for the transfer, so
# both need access. uid/gid are set at mount time because cifs has no Unix
# permissions of its own here.
WEB_UID="$(id -u "$WEB_USER")"
APP_GID="$(id -g "$APP_USER")"

mkdir -p "$MOUNT"

# ro is not a formality. The NOC has no business writing to ArcMate: the mirror
# is one-directional by design, and a read-only mount makes that structural
# rather than a promise in the code.
OPTS="ro,credentials=${CRED},uid=${WEB_UID},gid=${APP_GID},file_mode=0640,dir_mode=0750,iocharset=utf8,vers=3.0,nofail,_netdev"

FSTAB_LINE="//${HOST}/${SHARE}  ${MOUNT}  cifs  ${OPTS}  0  0"

if grep -q "[[:space:]]${MOUNT}[[:space:]]" /etc/fstab; then
    log "Updating the existing fstab entry for $MOUNT"
    sed -i "\|[[:space:]]${MOUNT}[[:space:]]|c\\${FSTAB_LINE}" /etc/fstab
else
    log "Adding $MOUNT to /etc/fstab"
    printf '\n# ArcMate document share — read-only, for the document archive portal\n%s\n' "$FSTAB_LINE" >> /etc/fstab
fi

# --- Mount ---------------------------------------------------------------------
if mountpoint -q "$MOUNT"; then
    log "Remounting $MOUNT ..."
    umount "$MOUNT" || warn "Could not unmount cleanly; the new options apply after a reboot."
fi

log "Mounting //${HOST}/${SHARE} at $MOUNT (read-only) ..."
mount "$MOUNT" || die "Mount failed. Check the account in $CRED, and that //${HOST}/${SHARE} is shared to it."

# --- Prove it worked -----------------------------------------------------------
log "Checking ..."
mountpoint -q "$MOUNT" || die "$MOUNT is still not a mount point."

PROJECTS="$(find "$MOUNT" -maxdepth 2 -name 'project.config' 2>/dev/null | wc -l)"
log "ArcMate projects visible: $PROJECTS"

if [[ "$PROJECTS" -eq 0 ]]; then
    warn "Mounted, but no project.config found. Is $SHARE the repositories folder itself?"
fi

# Read-only really means read-only.
if sudo -u "$WEB_USER" test -w "$MOUNT" 2>/dev/null; then
    warn "$MOUNT appears WRITABLE. It must be read-only — check the 'ro' option."
else
    log "Confirmed read-only."
fi

sudo -u "$WEB_USER" test -r "$MOUNT" \
    && log "$WEB_USER can read it (the portal serves files as this user)." \
    || warn "$WEB_USER cannot read $MOUNT — the viewer will 404 on every file."

cat <<DONE

  Mounted. Next, in the portal at /manage:

    1. Enter the SQL host, the read-only login and its password.
    2. Test connection — it checks SQL, this mount, and whether a stored path
       actually lands on a file. Those three fail for different reasons.
    3. Mirror the projects you want. The sync starts within five minutes.

DONE
