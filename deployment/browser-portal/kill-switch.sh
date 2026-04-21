#!/bin/bash
#
# Browser-portal kill-switch: applies iptables rules scoped to the
# `browser-net` Docker network (172.30.0.0/16) via Docker's DOCKER-USER chain.
#
# Allowed destination subnets are listed in /etc/browser-portal/allowed-subnets.conf.
# If that file contains the line `0.0.0.0/0` the kill-switch is effectively
# permissive (browser containers can reach the whole internet). To enforce a
# strict allow-list, remove `0.0.0.0/0` and list only company subnets, then:
#
#   sudo systemctl restart browser-portal-killswitch
#
# This script is idempotent. Run it as root.

set -euo pipefail

SUBNET="172.30.0.0/16"
CONFIG_FILE="/etc/browser-portal/allowed-subnets.conf"
MARK_COMMENT="browser-portal-killswitch"

if [[ $EUID -ne 0 ]]; then
    echo "must be run as root" >&2
    exit 1
fi

if [[ ! -f "$CONFIG_FILE" ]]; then
    echo "config file not found: $CONFIG_FILE" >&2
    exit 1
fi

# Read non-empty, non-comment lines.
mapfile -t ALLOWED < <(grep -Ev '^\s*(#|$)' "$CONFIG_FILE" | awk '{print $1}')

if [[ ${#ALLOWED[@]} -eq 0 ]]; then
    echo "no allowed subnets configured in $CONFIG_FILE; refusing to install rules that would let everything through silently" >&2
    exit 1
fi

# Flush any previous rules we own (identified by our comment tag).
flush_our_rules() {
    local table="$1" chain="$2"
    iptables -t "$table" -S "$chain" 2>/dev/null | grep -- "-m comment --comment \"$MARK_COMMENT\"" | while read -r line; do
        # Turn "-A CHAIN ..." into "-D CHAIN ..." to delete.
        rule="${line/#-A/-D}"
        iptables -t "$table" $rule || true
    done
}

flush_our_rules filter DOCKER-USER
flush_our_rules nat    POSTROUTING

# Allow established/related back to the containers (stateful).
iptables -I DOCKER-USER 1 \
    -s "$SUBNET" -m conntrack --ctstate RELATED,ESTABLISHED -j ACCEPT \
    -m comment --comment "$MARK_COMMENT"

# Allow each configured subnet.
permissive=0
for CIDR in "${ALLOWED[@]}"; do
    if [[ "$CIDR" == "0.0.0.0/0" ]]; then
        permissive=1
    fi
    iptables -I DOCKER-USER 2 \
        -s "$SUBNET" -d "$CIDR" -j ACCEPT \
        -m comment --comment "$MARK_COMMENT"

    # MASQUERADE so internal services see the VPS host IP (xfrm then picks it up).
    iptables -t nat -A POSTROUTING \
        -s "$SUBNET" -d "$CIDR" -j MASQUERADE \
        -m comment --comment "$MARK_COMMENT"
done

# If the allow-list does NOT include 0.0.0.0/0, append a DROP for everything else.
if [[ $permissive -eq 0 ]]; then
    iptables -A DOCKER-USER \
        -s "$SUBNET" -j DROP \
        -m comment --comment "$MARK_COMMENT"
    echo "strict mode: blackholed everything from $SUBNET except ${ALLOWED[*]}"
else
    echo "permissive mode: $SUBNET has unrestricted egress (contains 0.0.0.0/0)"
fi

# Ensure net.ipv4.ip_forward is on (strongSwan likely already set this).
sysctl -w net.ipv4.ip_forward=1 >/dev/null

echo "kill-switch rules applied."
