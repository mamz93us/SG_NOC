#!/usr/bin/env python3
"""Install the voice-mesh prober's systemd service and timer on this host.

Run after editing config.conf:

    sudo python3 deploy.py

It no longer validates a branch list — that lives in the NOC database now.
Instead it proves, before installing anything, that NOC_CONFIG_URL and
NOC_SECRET actually fetch a usable config, so a bad secret surfaces here rather
than as a silently stale board an hour later.
"""
import grp
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
SERVICE_USER = "voicemesh"

# The timer fires at this fixed cadence; the prober itself decides whether a
# sweep is actually due, from the interval configured in the NOC. That is what
# lets the interval be changed from the admin UI without touching this host.
TIMER_CADENCE = "5min"


def ensure_user_and_state(state_dir: Path) -> None:
    try:
        pwd.getpwnam(SERVICE_USER)
        print(f"user {SERVICE_USER} already exists")
    except KeyError:
        subprocess.run(
            ["useradd", "--system", "--no-create-home", "--shell", "/usr/sbin/nologin", SERVICE_USER],
            check=True,
        )
        print(f"created system user {SERVICE_USER}")

    state_dir.mkdir(parents=True, exist_ok=True)
    uid = pwd.getpwnam(SERVICE_USER).pw_uid
    gid = grp.getgrnam(SERVICE_USER).gr_gid
    os.chown(state_dir, uid, gid)
    os.chmod(state_dir, 0o750)
    print(f"state dir {state_dir} owned by {SERVICE_USER}")

    # config.conf holds the ingest secret, and the cached NOC config under the
    # state dir holds every branch's SIP password.
    os.chown(config.CONFIG_PATH, uid, gid)
    os.chmod(config.CONFIG_PATH, 0o600)
    print(f"{config.CONFIG_PATH} locked to {SERVICE_USER} (0600)")


def install_units(branch_count: int, duration: int) -> None:
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
User={SERVICE_USER}
Group={SERVICE_USER}
WorkingDirectory={PROJECT_DIR}
ExecStart=/usr/bin/python3 -m voice_mesh.cli verify
TimeoutStartSec={timeout_sec}

# Hardening
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true
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

    ensure_user_and_state(state_dir)
    install_units(len(branches), int(remote.get("duration", 10)))

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
