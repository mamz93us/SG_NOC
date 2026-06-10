#!/usr/bin/env bash
#
# One-line installer for sg-branch-agent (Ubuntu 24.04 LTS).
#
#   curl -fsSL https://noc.samirgroup.net/branch-agent/install.sh | sudo bash
#
# Override the NOC URL (e.g. staging) with:
#   curl -fsSL <url>/branch-agent/install.sh | sudo NOC_URL=https://staging.noc bash
#
# Idempotent: re-running upgrades the binary in place and restarts the service.
# It does only system prep — all configuration happens afterwards in the web
# wizard at http://<branch-ip>:8080/setup.
set -euo pipefail

NOC_URL="${NOC_URL:-https://noc.samirgroup.net}"
BIN_URL="${NOC_URL%/}/branch-agent/sg-branch-agent"
SHA_URL="${NOC_URL%/}/branch-agent/sg-branch-agent.sha256"
# The binary lives in the (service-user-owned) data dir so the agent can
# replace itself during a NOC-driven self-update without root. A convenience
# symlink in /usr/local/bin is created for CLI use.
SVC_USER="sgagent"
BIN_PATH="/var/lib/sg-branch-agent/sg-branch-agent"
BIN_LINK="/usr/local/bin/sg-branch-agent"
ETC_DIR="/etc/sg-branch-agent"
DATA_DIR="/var/lib/sg-branch-agent"
UNIT="/etc/systemd/system/sg-branch-agent.service"

log() { echo -e "\033[1;34m[sg-agent]\033[0m $*"; }
die() { echo -e "\033[1;31m[sg-agent] $*\033[0m" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || die "Run as root (use sudo)."

# ── OS / arch sanity ────────────────────────────────────────────────
# shellcheck disable=SC1091
[ -r /etc/os-release ] && . /etc/os-release || true
if [ "${ID:-}" != "ubuntu" ] || [ "${VERSION_ID:-}" != "24.04" ]; then
    log "WARNING: built for Ubuntu 24.04; found '${PRETTY_NAME:-unknown}'. Continuing in 5s…"
    sleep 5
fi
[ "$(uname -m)" = "x86_64" ] || die "Only x86_64 is supported (found $(uname -m))."

# ── user + directories ──────────────────────────────────────────────
if ! id -u "$SVC_USER" >/dev/null 2>&1; then
    log "Creating system user $SVC_USER"
    useradd --system --home "$DATA_DIR" --shell /usr/sbin/nologin "$SVC_USER"
fi
install -d -o "$SVC_USER" -g "$SVC_USER" -m 0750 "$ETC_DIR" "$DATA_DIR"

# ── download + verify binary ────────────────────────────────────────
need() { command -v "$1" >/dev/null 2>&1 || die "missing required tool: $1"; }
need curl; need sha256sum; need install

tmp="$(mktemp)"; tmp_sha="$(mktemp)"
trap 'rm -f "$tmp" "$tmp_sha"' EXIT
log "Downloading agent from $BIN_URL"
curl -fsSL "$BIN_URL" -o "$tmp" || die "download failed"

if curl -fsSL "$SHA_URL" -o "$tmp_sha" 2>/dev/null && [ -s "$tmp_sha" ]; then
    want="$(awk '{print $1}' "$tmp_sha")"
    got="$(sha256sum "$tmp" | awk '{print $1}')"
    [ "$want" = "$got" ] || die "checksum mismatch (want $want, got $got)"
    log "Checksum verified"
else
    log "WARNING: no published checksum — skipping verification"
fi
# Owned by the service user so the agent can self-replace it on update.
install -o "$SVC_USER" -g "$SVC_USER" -m 0755 "$tmp" "$BIN_PATH"
ln -sf "$BIN_PATH" "$BIN_LINK"

# ── systemd unit ────────────────────────────────────────────────────
# CAP_NET_BIND_SERVICE lets the non-root agent bind syslog :514.
log "Installing systemd unit"
cat > "$UNIT" <<UNIT_EOF
[Unit]
Description=SG Branch Agent (logs, device monitoring, DDNS)
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=$SVC_USER
Group=$SVC_USER
ExecStart=$BIN_PATH
Restart=always
RestartSec=5
AmbientCapabilities=CAP_NET_BIND_SERVICE
CapabilityBoundingSet=CAP_NET_BIND_SERVICE
NoNewPrivileges=true
ProtectSystem=full
ProtectHome=true
ReadWritePaths=$DATA_DIR $ETC_DIR

[Install]
WantedBy=multi-user.target
UNIT_EOF

systemctl daemon-reload
systemctl enable sg-branch-agent >/dev/null 2>&1 || true
systemctl restart sg-branch-agent

# ── firewall ────────────────────────────────────────────────────────
if command -v ufw >/dev/null 2>&1; then
    log "Opening firewall (514/udp, 514/tcp, 8080/tcp)"
    ufw allow OpenSSH        >/dev/null 2>&1 || ufw allow 22/tcp >/dev/null 2>&1 || true
    ufw allow 514/udp        >/dev/null 2>&1 || true
    ufw allow 514/tcp        >/dev/null 2>&1 || true
    ufw allow 8080/tcp       >/dev/null 2>&1 || true
    ufw --force enable       >/dev/null 2>&1 || true
fi

ip="$(hostname -I 2>/dev/null | awk '{print $1}')"
echo
echo "==============================================================="
log  "Installed and started."
echo "  Open the setup wizard:  http://${ip:-<branch-ip>}:8080/setup"
echo
echo "  Setup token (paste it in step 1 of the wizard):"
sleep 1
journalctl -u sg-branch-agent -n 40 --no-pager 2>/dev/null | grep -i 'SETUP TOKEN' | tail -1 \
    || echo "    (run: journalctl -u sg-branch-agent | grep 'SETUP TOKEN')"
echo "==============================================================="
