#!/usr/bin/env bash
#
# install.sh — OS-level setup for the SFTPGo backup-ingestion service on the NOC VM.
# Creates the service user, the per-account backup root (with an ACL the sweeper can
# read AND delete through), installs the config + systemd wiring, and enables the unit.
#
# Idempotent. Run as root AFTER installing the sftpgo binary (README §1) and creating
# the data DB (setup.sql):
#     sudo bash deployment/sftpgo/install.sh
#
# Override defaults via env:
#     sudo APP_USER=azureuser BACKUP_ROOT=/srv/backups bash deployment/sftpgo/install.sh
#
set -euo pipefail

SFTPGO_USER="${SFTPGO_USER:-sftpgo}"
APP_USER="${APP_USER:-azureuser}"           # user the Laravel scheduler/sweeper runs as
BACKUP_ROOT="${BACKUP_ROOT:-/srv/backups}"  # = data_provider.users_base_dir
CONF_DIR="${CONF_DIR:-/etc/sftpgo}"
STATE_DIR="${STATE_DIR:-/var/lib/sftpgo}"
CERT_DIR="${CERT_DIR:-/etc/sftpgo/certs}"

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

log()  { printf '  \033[1;32m·\033[0m %s\n' "$*"; }
warn() { printf '  \033[1;33m!\033[0m %s\n' "$*"; }
die()  { printf '  \033[1;31m✗ %s\033[0m\n' "$*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || die "Run as root (sudo)."

# Locate the sftpgo binary (apt → /usr/bin, manual → /usr/local/bin).
SFTPGO_BIN="$(command -v sftpgo || true)"
[ -n "$SFTPGO_BIN" ] || die "sftpgo binary not found on PATH — install it first (README §1)."
log "sftpgo binary: ${SFTPGO_BIN}"

# 1. Service user (no login) + dirs ---------------------------------------
if ! getent passwd "$SFTPGO_USER" >/dev/null; then
    useradd --system --home-dir "$STATE_DIR" --shell /usr/sbin/nologin "$SFTPGO_USER"
    log "created service user ${SFTPGO_USER}"
else
    log "service user ${SFTPGO_USER} exists"
fi

mkdir -p "$BACKUP_ROOT" "$CONF_DIR" "$STATE_DIR" "$CERT_DIR"
chown "${SFTPGO_USER}:${SFTPGO_USER}" "$BACKUP_ROOT" "$STATE_DIR" "$CERT_DIR"
chmod 750 "$BACKUP_ROOT" "$STATE_DIR" "$CERT_DIR"
log "dirs ready: ${BACKUP_ROOT}, ${STATE_DIR}, ${CERT_DIR}"

# 2. ACL so the sweeper (APP_USER) can read AND DELETE every future per-account
#    folder SFTPGo creates. rwX (capital X) grants dir traversal without marking
#    files executable; the default (-d) entry makes new folders inherit it.
if ! command -v setfacl >/dev/null 2>&1; then
    warn "setfacl missing; installing 'acl'…"
    (apt-get update -qq && apt-get install -y acl) >/dev/null 2>&1 || true
fi
if command -v setfacl >/dev/null 2>&1 && getent passwd "$APP_USER" >/dev/null; then
    setfacl -R    -m "u:${APP_USER}:rwX" "$BACKUP_ROOT"
    setfacl -R -d -m "u:${APP_USER}:rwX" "$BACKUP_ROOT"
    log "ACL: ${APP_USER} rwX on ${BACKUP_ROOT} (default-inherited)"
else
    warn "could not set ACL — the sweeper (${APP_USER}) may be unable to read/delete uploads."
fi

# 3. Config (don't clobber an edited one) ---------------------------------
if [ ! -f "${CONF_DIR}/sftpgo.json" ]; then
    install -m640 -o "$SFTPGO_USER" -g "$SFTPGO_USER" "$DIR/sftpgo.json" "${CONF_DIR}/sftpgo.json"
    log "installed ${CONF_DIR}/sftpgo.json"
else
    install -m640 -o "$SFTPGO_USER" -g "$SFTPGO_USER" "$DIR/sftpgo.json" "${CONF_DIR}/sftpgo.json.new"
    warn "${CONF_DIR}/sftpgo.json exists — wrote template to sftpgo.json.new (merge by hand)"
fi

# 4. Secret env file (don't clobber) --------------------------------------
if [ ! -f "${CONF_DIR}/sftpgo.env" ]; then
    install -m600 -o root -g root "$DIR/sftpgo.env.example" "${CONF_DIR}/sftpgo.env"
    warn "created ${CONF_DIR}/sftpgo.env from example — EDIT IT (DB + admin passwords) before starting"
else
    log "${CONF_DIR}/sftpgo.env exists (left untouched)"
fi

# 5. systemd wiring -------------------------------------------------------
if systemctl list-unit-files 2>/dev/null | grep -q '^sftpgo\.service'; then
    # A packaged unit exists (installed via apt). Add a drop-in instead of clobbering.
    mkdir -p /etc/systemd/system/sftpgo.service.d
    cat > /etc/systemd/system/sftpgo.service.d/10-sg-noc.conf <<EOF
[Service]
EnvironmentFile=${CONF_DIR}/sftpgo.env
ReadWritePaths=${BACKUP_ROOT}
EOF
    log "added systemd drop-in (EnvironmentFile) over the packaged unit"
else
    sed "s#/usr/local/bin/sftpgo#${SFTPGO_BIN}#" "$DIR/sftpgo.service" > /etc/systemd/system/sftpgo.service
    log "installed /etc/systemd/system/sftpgo.service (ExecStart=${SFTPGO_BIN})"
fi
systemctl daemon-reload
systemctl enable sftpgo >/dev/null 2>&1 || true

cat <<EOF

==> OS setup done. Remaining steps (see README):
  1. Create the data DB (if not yet):  sudo mysql < deployment/sftpgo/setup.sql
  2. Edit ${CONF_DIR}/sftpgo.env        (DB password to match setup.sql + admin password)
  3. FTPS certs in ${CERT_DIR}/         (fullchain.pem + privkey.pem; LE deploy-hook in README §5)
  4. Start it:                          sudo systemctl restart sftpgo && systemctl status sftpgo
  5. First login (SSH tunnel):          ssh -L 8080:127.0.0.1:8080 ${APP_USER}@<host>  ->  http://localhost:8080
                                        then rotate the bootstrap admin password.
  6. Create the upload event rule       (README §7) so uploads POST to the NOC webhook.
  7. Admin -> Settings -> SFTPGo        base URL http://127.0.0.1:8080, admin creds, webhook secret, Test.
EOF
