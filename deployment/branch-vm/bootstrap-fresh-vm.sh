#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────
# SG_NOC Branch VM — fresh-machine bootstrap.
#
# Run this on a brand-new Ubuntu 24.04 server (the kind of minimal image
# that ships without ca-certificates or git). It installs the few prereqs
# git itself needs, clones the repo to /opt/sg-noc, then prints the next
# command for the operator to run.
#
# Two ways to invoke it:
#
#   (A) Pre-clone — operator manually runs it once on a new VM:
#       wget -qO- https://raw.githubusercontent.com/mamz93us/SG_NOC/main/deployment/branch-vm/bootstrap-fresh-vm.sh | sudo bash
#
#   (B) Post-clone (re-running) — re-runs are safe and idempotent:
#       sudo bash /opt/sg-noc/deployment/branch-vm/bootstrap-fresh-vm.sh
#
# After this finishes, the operator still needs to:
#   1. Edit /etc/sg-noc-branch.env (BRANCH_ID, BRANCH_NAME, TIMEZONE, NOC_ALLOWED_CIDR)
#   2. Run install.sh to provision MariaDB / rsyslog / nginx / services
# ─────────────────────────────────────────────────────────────────────────
set -euo pipefail

REPO_URL="${SG_NOC_REPO_URL:-https://github.com/mamz93us/SG_NOC.git}"
REPO_DIR="${SG_NOC_REPO_DIR:-/opt/sg-noc}"
BRANCH="${SG_NOC_BRANCH:-main}"

red()   { printf '\033[31m%s\033[0m\n' "$*" >&2; }
green() { printf '\033[32m%s\033[0m\n' "$*"; }
note()  { printf '\033[36m▸ %s\033[0m\n' "$*"; }

[[ $EUID -eq 0 ]] || { red "Run with sudo (need root for apt + /opt)."; exit 1; }

# ─── 1. Sanity checks ─────────────────────────────────────────────────────

if [[ -r /etc/os-release ]]; then
    . /etc/os-release
    if [[ "${ID:-}" != "ubuntu" || "${VERSION_ID:-}" != "24.04" ]]; then
        red "Warning: this is built for Ubuntu 24.04 (you're on ${PRETTY_NAME:-unknown})."
        red "install.sh will refuse to run if the OS isn't 24.04 — abort and use a 24.04 image."
        exit 1
    fi
fi

# ─── 2. Install the bare-minimum prereqs git itself needs ─────────────────

note "Installing ca-certificates + git + curl (skip if already present)"
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y --no-install-recommends ca-certificates git curl
update-ca-certificates --fresh

# ─── 3. Clone (or refresh) the repo ───────────────────────────────────────

mkdir -p "$(dirname "$REPO_DIR")"

if [[ -d "$REPO_DIR/.git" ]]; then
    note "Repo already cloned — pulling latest from origin/$BRANCH"
    git -C "$REPO_DIR" fetch --quiet origin
    git -C "$REPO_DIR" checkout --quiet "$BRANCH"
    git -C "$REPO_DIR" pull --ff-only --quiet
else
    note "Cloning $REPO_URL → $REPO_DIR"
    if ! git clone --branch "$BRANCH" --depth 1 "$REPO_URL" "$REPO_DIR" 2>/tmp/sg-clone.err; then
        # Re-emit the error and offer the PAT path explicitly
        cat /tmp/sg-clone.err >&2
        red ""
        red "Clone failed. If the repo is private, retry with a Personal Access Token:"
        red "  git clone https://<github-username>:<PAT>@github.com/mamz93us/SG_NOC.git $REPO_DIR"
        red ""
        red "Or save the credential first so update.sh / cron pulls work:"
        red "  git config --global credential.helper store"
        red "  git clone $REPO_URL $REPO_DIR    # enter username + PAT once"
        exit 1
    fi
fi

# Make ownership match a regular user so subsequent git pulls don't need sudo.
# Default to azureuser (matches the Azure VM image), fall back to first
# non-root sudoer, fall back to root.
TARGET_USER="${SUDO_USER:-azureuser}"
if ! id "$TARGET_USER" >/dev/null 2>&1; then
    TARGET_USER=$(getent passwd | awk -F: '$3>=1000 && $3<65534 {print $1; exit}')
fi
if [[ -n "${TARGET_USER:-}" ]] && id "$TARGET_USER" >/dev/null 2>&1; then
    chown -R "$TARGET_USER":"$TARGET_USER" "$REPO_DIR"
    note "Repo owned by $TARGET_USER"
fi

# ─── 4. Friendly next-steps ───────────────────────────────────────────────

cat <<EOF

$(green "✔ Bootstrap complete: $REPO_DIR")

Next steps:

  1. Render the per-VM env file (replace placeholders for BRANCH_ID etc.):

       sudo cp $REPO_DIR/deployment/branch-vm/.env.example /etc/sg-noc-branch.env
       sudo nano /etc/sg-noc-branch.env
         BRANCH_ID=<jed|ryd|mak|...>
         BRANCH_NAME="<office name>"
         TIMEZONE=Asia/Riyadh
         NOC_ALLOWED_CIDR=<NOC's tunnel CIDR>

  2. Run the full installer (MariaDB + rsyslog + nginx + ingester + API):

       cd $REPO_DIR/deployment/branch-vm
       sudo bash install.sh

  3. Note the API_TOKEN install.sh prints, and add this branch via the
     NOC UI at https://noc.samirgroup.net/admin/branches/log-collectors

EOF
