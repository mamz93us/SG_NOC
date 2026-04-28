#!/bin/bash
# =============================================================================
# sg-radius-control — Laravel-side wrapper for FreeRADIUS reload/restart
# =============================================================================
# Symlinked from /usr/local/bin/sg-radius-control. Invoked by the Laravel
# RadiusNasController via `sudo -n` whenever a NAS row changes, so FreeRADIUS
# picks up the new clients table without a full systemctl restart.
#
# The /etc/sudoers.d/sg-radius file allows the webserver user to run only
# the two subcommands listed below — nothing else.
#
# Mirrors deployment/sg-vpn-control.sh in spirit: a tiny, auditable wrapper
# that owns the privileged-action surface area.
# =============================================================================

set -euo pipefail

usage() {
    echo "Usage: $0 {reload-clients|restart}" >&2
    exit 64
}

if [[ $# -ne 1 ]]; then
    usage
fi

case "$1" in
    reload-clients)
        # `radmin -e "reload clients"` re-reads the SQL clients table without
        # restarting the daemon. -e exits non-zero on failure.
        exec /usr/sbin/radmin -e "reload clients"
        ;;

    restart)
        exec /usr/bin/systemctl restart freeradius
        ;;

    *)
        usage
        ;;
esac
