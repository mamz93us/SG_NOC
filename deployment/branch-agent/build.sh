#!/usr/bin/env bash
#
# Build the agent binary for the branch VMs (Ubuntu 24.04 / x86_64) and stage
# it where the NOC serves it from (/branch-agent/sg-branch-agent).
#
# Run from the repo root:  ./deployment/branch-agent/build.sh [version]
#
# Then commit the repo / pull on the VPS, OR upload storage/app/branch-agent/*
# to the production server's storage/app/branch-agent/.
set -euo pipefail

VERSION="${1:-$(git describe --tags --always --dirty 2>/dev/null || echo dev)}"
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
SRC="$ROOT/branch-agent"
OUT_DIR="$ROOT/storage/app/branch-agent"
OUT_BIN="$OUT_DIR/sg-branch-agent"

mkdir -p "$OUT_DIR"

echo "Building sg-branch-agent $VERSION (linux/amd64, CGO disabled)…"
( cd "$SRC" && \
  CGO_ENABLED=0 GOOS=linux GOARCH=amd64 \
  go build -trimpath \
    -ldflags "-s -w -X github.com/samirgroup/sg-branch-agent/internal/version.Version=$VERSION" \
    -o "$OUT_BIN" ./cmd/sg-branch-agent )

sha256sum "$OUT_BIN" | awk '{print $1}' > "$OUT_BIN.sha256"
# The NOC reads this to tell agents the target version (self-update).
printf '%s' "$VERSION" > "$OUT_BIN.version"

echo "Built:    $OUT_BIN"
echo "Version:  $VERSION"
echo "SHA256:   $(cat "$OUT_BIN.sha256")"
echo "Served at: <NOC_URL>/branch-agent/sg-branch-agent"
echo "Agents on a different version will auto-update on their next heartbeat."
