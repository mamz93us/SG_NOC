#!/usr/bin/env python3
"""Install the voice-mesh prober's systemd service and timer on this host.

Run after editing config.conf:

    sudo python3 deploy.py

It no longer validates a branch list — that lives in the NOC database now.
Instead it proves, before installing anything, that NOC_CONFIG_URL and
NOC_SECRET actually fetch a usable config, so a bad secret surfaces here rather
than as a silently stale board an hour later.
"""
import os
import pwd
import subprocess
import sys
from pathlib import Path

PROJECT_DIR = Path(__file__).resolve().parent
sys.path.insert(0, str(PROJECT_DIR))
from voice_mesh import config, noc  # noqa: E402

SYSTEMD_DIR = Path("/etc/systemd/system")
SERVICE_NAME = "voice-mesh-verify"
DEDICATED_USER = "voicemesh"

# The timer fires at this fixed cadence; the prober itself decides whether a
# sweep is actually due, from the interval configured in the NOC. That is what
# lets the interval be changed from the admin UI without touching this host.
#
# A minute, not five, so "Run a sweep now" in the admin UI starts within about a
# minute. The wake is cheap when nothing is due — fetch the config, find no
# request and an unexpired interval, exit — so the cost of the tighter cadence
# is one short-lived Python process a minute, in line with tunnel-health:watch.
TIMER_CADENCE = "1min"


def resolve_service_user() -> tuple[str, bool]:
    """Decide who the service runs as, and whether ProtectHome can stay on.

    Returns (username, protect_home).

    This project lives inside the app repo so that `git pull` updates it like
    everything else. On the NOC that repo is under /home/azureuser, and a
    dedicated system user cannot run from there at all:

      * ProtectHome=true masks /home outright, so the unit fails at
        WorkingDirectory with 200/CHDIR before Python is even reached;
      * and even with it off, /home/azureuser is 0750, so a system user cannot
        traverse into it anyway.

    Rather than fight that (chmod'ing someone's home, or copying the code to
    /opt and losing git-pull deploys), run as the account that already owns the
    checkout — the same one the app and scheduler run as — and turn ProtectHome
    off, since the working directory *is* in a home.

    Outside /home the upstream arrangement is better, so keep it: a dedicated
    locked-down user with ProtectHome=true.
    """
    if PROJECT_DIR.is_relative_to("/home"):
        owner = pwd.getpwuid(PROJECT_DIR.stat().st_uid).pw_name
        return owner, False

    return DEDICATED_USER, True


def ensure_user_and_state(service_user: str, state_dir: Path) -> None:
    try:
        pwd.getpwnam(service_user)
        print(f"running as existing user {service_user}")
    except KeyError:
        subprocess.run(
            ["useradd", "--system", "--no-create-home", "--shell", "/usr/sbin/nologin", service_user],
            check=True,
        )
        print(f"created system user {service_user}")

    state_dir.mkdir(parents=True, exist_ok=True)
    entry = pwd.getpwnam(service_user)
    uid, gid = entry.pw_uid, entry.pw_gid
    os.chown(state_dir, uid, gid)
    os.chmod(state_dir, 0o750)

    # Recursively, not just the directory: we run as root and fetch the NOC
    # config before this point, so the cached copy and anything an earlier
    # `sudo deploy.py` left behind is root-owned. The service would then be
    # unable to refresh its own cache.
    reowned = 0
    for path in state_dir.rglob("*"):
        try:
            os.chown(path, uid, gid)
            reowned += 1
        except OSError as e:
            print(f"  ! could not chown {path}: {e}")

    print(f"state dir {state_dir} owned by {service_user}"
          + (f" ({reowned} existing file(s) re-owned)" if reowned else ""))

    # config.conf holds the ingest secret, and the cached NOC config under the
    # state dir holds every branch's SIP password.
    os.chown(config.CONFIG_PATH, uid, gid)
    os.chmod(config.CONFIG_PATH, 0o600)
    print(f"{config.CONFIG_PATH} locked to {service_user} (0600)")


def install_units(service_user: str, protect_home: bool, branch_count: int, duration: int) -> None:
    legs = branch_count * (branch_count - 1)
    # Every leg is a sequential pjsua call: register, dial, wait up to DURATION
    # for the IVR to hang up, tear down. Size the timeout so a full mesh is never
    # killed mid-sweep.
    timeout_sec = legs * (duration + 12) + 120

    service = f"""[Unit]
Description=Dial every branch pair's test IVR and report call health to the NOC
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
User={service_user}
WorkingDirectory={PROJECT_DIR}
ExecStart=/usr/bin/python3 -m voice_mesh.cli verify
TimeoutStartSec={timeout_sec}

# Hardening. ProtectSystem=strict keeps the whole filesystem read-only apart
# from ReadWritePaths — the prober only ever writes to the state dir, and pjsua's
# credentials file goes to the private /tmp.
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome={'true' if protect_home else 'false'}
ReadWritePaths=/var/lib/voice-mesh

[Install]
WantedBy=multi-user.target
"""

    timer = f"""[Unit]
Description=Wake the voice-mesh prober; it runs a sweep when one is due

[Timer]
OnBootSec=3min
OnUnitActiveSec={TIMER_CADENCE}
AccuracySec=30s
Unit={SERVICE_NAME}.service

[Install]
WantedBy=timers.target
"""

    (SYSTEMD_DIR / f"{SERVICE_NAME}.service").write_text(service)
    (SYSTEMD_DIR / f"{SERVICE_NAME}.timer").write_text(timer)
    print(f"wrote {SYSTEMD_DIR}/{SERVICE_NAME}.{{service,timer}} (timeout {timeout_sec}s for {legs} legs)")

    subprocess.run(["systemctl", "daemon-reload"], check=True)
    subprocess.run(["systemctl", "enable", "--now", f"{SERVICE_NAME}.timer"], check=True)
    print(f"enabled {SERVICE_NAME}.timer (wakes every {TIMER_CADENCE})")


def main():
    if os.geteuid() != 0:
        sys.exit("deploy.py must be run as root: sudo python3 deploy.py")

    local = config.load_local()
    state_dir = Path(local["STATE_DIR"])

    # Prove the NOC link works before installing a timer that depends on it.
    state_dir.mkdir(parents=True, exist_ok=True)
    remote = noc.fetch_config(local["NOC_CONFIG_URL"], local["NOC_SECRET"], state_dir)
    branches = config.validate_branches(remote.get("branches", []), "the NOC")
    print(f"NOC OK: {len(branches)} active branches ({', '.join(b['name'] for b in branches)})")

    service_user, protect_home = resolve_service_user()
    if not protect_home:
        print(
            f"project is under /home, so the service runs as its owner ({service_user}) "
            "with ProtectHome off — a dedicated system user cannot chdir into a home directory"
        )

    ensure_user_and_state(service_user, state_dir)
    install_units(service_user, protect_home, len(branches), int(remote.get("duration", 10)))

    interval = float(remote.get("interval_minutes", 30))
    legs = len(branches) * (len(branches) - 1)
    est = legs * (int(remote.get("duration", 10)) + 12) / 60
    print(f"\nsweep is ~{est:.0f} min for {legs} legs; the NOC interval is {interval:g} min")
    if est > interval:
        print(
            "WARNING: a sweep may take longer than the interval, so runs will queue. "
            "Raise VOICE_MESH_INTERVAL on the NOC, or shorten VOICE_MESH_DURATION."
        )

    print(f"\nrun one now:  sudo systemctl start {SERVICE_NAME}.service")
    print(f"watch it:     journalctl -u {SERVICE_NAME} -f")


if __name__ == "__main__":
    main()
