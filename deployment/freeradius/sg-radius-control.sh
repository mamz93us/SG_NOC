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
        # FreeRADIUS 3.x has no granular "reload clients" command — that's
        # a v4 feature. To refresh the SQL clients table we send SIGHUP,
        # which re-reads the entire config (including read_clients=yes).
        #
        # Try `radmin -e "hup"` first (no downtime), fall back to
        # systemctl reload (also SIGHUP via the unit's ExecReload).
        if /usr/sbin/radmin -e "hup" 2>/dev/null; then
            exit 0
        fi
        exec /usr/bin/systemctl reload freeradius
        ;;

    restart)
        exec /usr/bin/systemctl restart freeradius
        ;;

    *)
        usage
        ;;
esac
