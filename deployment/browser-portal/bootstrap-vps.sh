#!/bin/bash
#
# One-shot VPS bootstrap for the browser-portal infrastructure.
# Idempotent: safe to re-run any time.
#
# What this does:
#   1. Creates the Docker network `browser-net` (172.30.0.0/16) if missing.
#   2. Installs the kill-switch script to /usr/local/sbin/.
#   3. Seeds /etc/browser-portal/allowed-subnets.conf from the sample
#      if it doesn't exist yet (so existing config isn't overwritten).
#   4. Installs the systemd unit and enables + starts it.
#
# Run as root:
#   sudo bash deployment/browser-portal/bootstrap-vps.sh

set -euo pipefail

if [[ $EUID -ne 0 ]]; then
    echo "must be run as root (sudo)" >&2
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

echo "==> ensuring Docker network 'browser-net' exists"
if ! docker network inspect browser-net >/dev/null 2>&1; then
    docker network create \
        --driver bridge \
        --subnet 172.30.0.0/16 \
        --gateway 172.30.0.1 \
        --opt com.docker.network.bridge.name=br-browser \
        browser-net
    echo "    created"
else
    echo "    already exists"
fi

echo "==> installing /usr/local/sbin/browser-portal-killswitch.sh"
install -m 0755 "$SCRIPT_DIR/kill-switch.sh" /usr/local/sbin/browser-portal-killswitch.sh

echo "==> seeding /etc/browser-portal/allowed-subnets.conf (if missing)"
install -d -m 0755 /etc/browser-portal
if [[ ! -f /etc/browser-portal/allowed-subnets.conf ]]; then
    install -m 0644 "$SCRIPT_DIR/allowed-subnets.conf.sample" /etc/browser-portal/allowed-subnets.conf
    echo "    seeded from sample (default is permissive 0.0.0.0/0 — tighten later)"
else
    echo "    already exists, leaving untouched"
fi

echo "==> installing systemd unit"
install -m 0644 "$SCRIPT_DIR/browser-portal-killswitch.service" /etc/systemd/system/browser-portal-killswitch.service
systemctl daemon-reload
systemctl enable --now browser-portal-killswitch.service

echo "==> done. Verification:"
echo
systemctl --no-pager --full status browser-portal-killswitch.service || true
echo
echo "iptables rules in DOCKER-USER:"
iptables -S DOCKER-USER | grep browser-portal-killswitch || echo "    (none — kill-switch did not apply)"
