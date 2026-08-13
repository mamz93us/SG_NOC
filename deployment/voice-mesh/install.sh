#!/usr/bin/env bash
#
# install.sh — set up the voice-mesh prober on the NOC host.
#
#   sudo bash deployment/voice-mesh/install.sh
#
# Idempotent and non-destructive: it installs build deps, builds pjsua if it
# isn't already present, and hands over to deploy.py for the systemd units.
# It never overwrites an existing config.conf.
#
# It does NOT open the SIP ACLs this needs on each branch UCM — that is a manual
# per-firewall change, and without it every leg fails. See VOICE_MESH_SETUP.md.

set -uo pipefail   # NOT -e: a failing step must report, not abort silently

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PJPROJECT_VERSION="${PJPROJECT_VERSION:-2.14.1}"
PJSUA_BIN="/usr/local/bin/pjsua"
BUILD_DIR="${BUILD_DIR:-/usr/local/src}"

ok()   { printf '  \033[1;32m·\033[0m %s\n' "$*"; }
warn() { printf '  \033[1;33m!\033[0m %s\n' "$*"; }
sec()  { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }

[ "$(id -u)" -eq 0 ] || { echo "Run as root (sudo)."; exit 1; }

export DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=a

# ───────────────────────────────────────────────── pjsua
sec "pjsua"
if command -v pjsua >/dev/null 2>&1 || [ -x "$PJSUA_BIN" ]; then
    ok "pjsua already installed ($(command -v pjsua || echo "$PJSUA_BIN"))"
else
    warn "pjsua not found — building pjproject ${PJPROJECT_VERSION} from source"
    warn "there is no apt package for it; this takes a few minutes"

    apt-get update -qq >/dev/null 2>&1
    if apt-get install -y build-essential libasound2-dev uuid-dev pkg-config >/dev/null 2>&1; then
        ok "build dependencies installed"
    else
        warn "could not install build dependencies — pjsua build will probably fail"
    fi

    mkdir -p "$BUILD_DIR"
    if [ ! -d "${BUILD_DIR}/pjproject-${PJPROJECT_VERSION}" ]; then
        curl -fsSL -o "${BUILD_DIR}/pjproject.tar.gz" \
            "https://github.com/pjsip/pjproject/archive/refs/tags/${PJPROJECT_VERSION}.tar.gz" \
            && tar xf "${BUILD_DIR}/pjproject.tar.gz" -C "$BUILD_DIR" \
            && ok "pjproject source unpacked" \
            || warn "could not download pjproject"
    fi

    if [ -d "${BUILD_DIR}/pjproject-${PJPROJECT_VERSION}" ]; then
        (
            cd "${BUILD_DIR}/pjproject-${PJPROJECT_VERSION}" || exit 1
            # No video, no local sound device — this box places calls and records
            # to a file; it has no audio hardware and doesn't need any.
            ./configure --disable-video --disable-sound --disable-opencore-amr >/dev/null 2>&1 \
                && make dep >/dev/null 2>&1 && make >/dev/null 2>&1
        ) && ok "pjproject built" || warn "pjproject build failed — see ${BUILD_DIR}"

        # The binary is named for the build target, e.g. pjsua-x86_64-unknown-linux-gnu.
        BUILT="$(find "${BUILD_DIR}/pjproject-${PJPROJECT_VERSION}/pjsip-apps/bin" -maxdepth 1 -name 'pjsua-*' -type f 2>/dev/null | head -1)"
        if [ -n "$BUILT" ]; then
            # System-wide: the service runs as `voicemesh`, which has no home dir.
            install -m0755 "$BUILT" "$PJSUA_BIN" && ok "installed $PJSUA_BIN"
        else
            warn "no pjsua binary produced — install it by hand before running deploy.py"
        fi
    fi
fi

# ───────────────────────────────────────────────── config
sec "config"
if [ -f "${PROJECT_DIR}/config.conf" ]; then
    ok "config.conf already present — left alone"
else
    cp "${PROJECT_DIR}/config.conf.example" "${PROJECT_DIR}/config.conf"
    chmod 600 "${PROJECT_DIR}/config.conf"
    warn "created config.conf — put the ingest secret in NOC_SECRET before continuing"
    warn "generate one at https://noc.samirgroup.net/admin/network/voice-mesh"
fi

# ───────────────────────────────────────────────── systemd
sec "systemd units"
if grep -q '^NOC_SECRET *= *CHANGEME' "${PROJECT_DIR}/config.conf" 2>/dev/null; then
    warn "NOC_SECRET is still CHANGEME — skipping deploy.py"
    warn "set it, then run: sudo python3 ${PROJECT_DIR}/deploy.py"
else
    python3 "${PROJECT_DIR}/deploy.py" || warn "deploy.py failed — see the output above"
fi

sec "MANUAL FOLLOW-UPS"
cat <<'NOTES'
  1. Each branch UCM must permit SIP from this host (172.16.8.11). The tunnel
     watchdog measured every UCM silently dropping UDP/5060 from the NOC, so
     registration WILL fail until this is changed per firewall.
  2. Each branch needs a dedicated probe extension (not a real desk phone — we
     would steal its registration) and a test IVR playing reference.wav, set to
     hang up on no digits, repeat once.
  3. Add every branch at /admin/network/voice-mesh with its UCM address, probe
     extension and IVR extension.
  4. Set VOICE_MESH_REFERENCE_SHA256 in the NOC .env to the checksum of
     reference.wav, or the prober cannot confirm it has the right prompt:
NOTES
sha256sum "${PROJECT_DIR}/reference.wav" 2>/dev/null | awk '{print "       " $1}'
