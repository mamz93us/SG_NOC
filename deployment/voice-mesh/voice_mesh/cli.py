"""voice-mesh prober: synthetic branch-to-branch call testing for the NOC.

Runs on the NOC host, which reaches every branch's UCM over the Azure tunnels.
For each branch it registers to *that branch's own UCM* with *that branch's own
SIP credentials* and dials every other branch's IVR extension — a full mesh,
derived from the branch list the NOC hands back. Each recording's trimmed
duration is compared against the reference prompt, and one combined report
covering every pair is POSTed back.

Nothing here ever aborts a sweep part-way: an unreachable branch or a wedged
call is recorded as a failed leg and the run moves on, so one bad branch never
costs you visibility into the rest.

Subcommands:
  verify         dial every pair, POST the report, exit non-zero on failure
                 (what the systemd service runs)
  send-health    the same, but always exit 0 — for testing the endpoint
  show-config    print the resolved branch list (passwords redacted) and exit
"""
import argparse
import hashlib
import json
import logging
import sys
import time
import types
from datetime import datetime
from pathlib import Path

from . import config, noc
from .prober import call_and_record
from .reference import compare, load_reference_duration

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
log = logging.getLogger("voice-mesh")

LAST_RUN_NAME = "last-run.json"
PROBE_VERSION = "2.0"


def _pairs(branches):
    """Every (caller, dest) pair — each branch calling every other branch's IVR
    on the caller's own UCM and credentials."""
    for caller in branches:
        for dest in branches:
            if dest["name"] != caller["name"]:
                yield caller, dest


def _check(cfg, state_dir: Path, reference_duration_sec: float):
    results = []

    for caller, dest in _pairs(cfg.BRANCHES):
        ext, name = dest["ext"], dest["name"]
        rec_path = state_dir / f"last-verify-{caller['name']}-{ext}.wav"

        try:
            capture = call_and_record(
                sip_server=caller["sip_server"],
                sip_user=caller["sip_user"],
                sip_pass=caller["sip_pass"],
                dest=ext,
                rec_path=rec_path,
                max_duration=cfg.DURATION,
                sip_port=caller["sip_port"],
                local_port=cfg.LOCAL_PORT,
                pjsua_bin=cfg.PJSUA_BIN,
            )
            # The per-call log is where a registration rejection, a missing IVR
            # and a stuck call are actually distinguishable from each other.
            rec_path.with_suffix(".log").write_text(capture.log_text)
        except (SystemExit, Exception) as e:
            reason = f"call setup failed: {e}"
            results.append({
                "caller": caller["name"], "dest": name, "dest_ext": ext, "ok": False,
                "rx_pkt": 0, "duration_sec": 0.0,
                "reference_duration_sec": reference_duration_sec, "reason": reason,
            })
            log.warning("[%s -> %s (ext %s)] FAIL — %s", caller["name"], name, ext, reason)
            continue

        ok, reason = compare(capture, reference_duration_sec, tolerance_pct=cfg.TOLERANCE_PCT)

        log.info(
            "[%s -> %s (ext %s)] %s rx_pkt=%s duration=%.2fs (reference=%.2fs) — %s",
            caller["name"], name, ext, "OK" if ok else "FAIL",
            capture.rx_pkt, capture.duration_sec, reference_duration_sec, reason,
        )
        results.append({
            "caller": caller["name"],
            "dest": name,
            "dest_ext": ext,
            "ok": ok,
            "rx_pkt": capture.rx_pkt,
            "duration_sec": round(capture.duration_sec, 2),
            "reference_duration_sec": round(reference_duration_sec, 2),
            "reason": reason,
        })

    return all(r["ok"] for r in results), results


def _post(cfg, overall_ok: bool, results: list, state_dir: Path) -> None:
    noc.post_report(
        cfg.NOC_REPORT_URL,
        cfg.NOC_SECRET,
        {
            "runner_name": cfg.RUNNER_NAME,
            "probe_version": PROBE_VERSION,
            "timestamp": datetime.now().isoformat(timespec="seconds"),
            "ok": overall_ok,
            "results": results,
        },
        state_dir,
    )


def _due(state_dir: Path, interval_minutes: float) -> bool:
    """Has a full interval passed since the last sweep?

    The systemd timer deliberately fires more often than the configured
    interval, and this gates it. That is what makes the interval genuinely
    NOC-managed: without it, changing the interval in the admin UI would do
    nothing until somebody re-ran deploy.py on this host.

    The 30s grace stops a timer firing a hair early from pushing every
    subsequent sweep a whole tick later.
    """
    marker = state_dir / LAST_RUN_NAME
    if not marker.exists():
        return True

    try:
        last = json.loads(marker.read_text()).get("epoch", 0)
    except (ValueError, OSError):
        return True

    return (time.time() - last) >= (interval_minutes * 60 - 30)


def _stamp(state_dir: Path) -> None:
    (state_dir / LAST_RUN_NAME).write_text(
        json.dumps({"epoch": time.time(), "at": datetime.now().isoformat(timespec="seconds")})
    )


def _load(local: dict, state_dir: Path):
    """Local config + whatever the NOC says, as one namespace."""
    remote = noc.fetch_config(local["NOC_CONFIG_URL"], local["NOC_SECRET"], state_dir)

    cfg = types.SimpleNamespace(
        **local,
        RUNNER_NAME=remote.get("runner_name", "noc-voice-mesh"),
        INTERVAL_MINUTES=float(remote.get("interval_minutes", 30)),
        DURATION=int(remote.get("duration", 10)),
        TOLERANCE_PCT=float(remote.get("tolerance_pct", 10)),
        LOCAL_PORT=int(remote.get("local_port", 5080)),
        REFERENCE_SHA256=str(remote.get("reference_sha256", "")),
        BRANCHES=config.validate_branches(remote.get("branches", []), "the NOC"),
    )

    _assert_reference(cfg)

    return cfg


def _assert_reference(cfg) -> None:
    """Refuse to dial if our reference prompt isn't the one the NOC expects.

    Every branch's IVR plays this exact file; a mismatch here means the prompt
    on disk has drifted from the one the durations are being judged against, and
    every leg would fail for a reason that has nothing to do with the network.
    """
    if not cfg.REFERENCE_SHA256:
        log.warning(
            "no reference checksum configured in the NOC — cannot confirm %s is the "
            "prompt the IVRs play. Set VOICE_MESH_REFERENCE_SHA256.", cfg.REFERENCE_WAV
        )
        return

    actual = hashlib.sha256(Path(cfg.REFERENCE_WAV).read_bytes()).hexdigest()
    if actual != cfg.REFERENCE_SHA256:
        sys.exit(
            f"REFERENCE_WAV mismatch: {cfg.REFERENCE_WAV} is {actual}, the NOC expects "
            f"{cfg.REFERENCE_SHA256}. Either the wrong prompt is on this host, or the "
            "prompt was replaced without updating VOICE_MESH_REFERENCE_SHA256."
        )


def show_config(cfg) -> None:
    print(f"runner         {cfg.RUNNER_NAME}")
    print(f"interval       {cfg.INTERVAL_MINUTES:g} min")
    print(f"hangup ceiling {cfg.DURATION}s   tolerance {cfg.TOLERANCE_PCT}%   local port {cfg.LOCAL_PORT}")
    print(f"reference      {cfg.REFERENCE_WAV}")
    print(f"branches       {len(cfg.BRANCHES)} ({len(cfg.BRANCHES) * (len(cfg.BRANCHES) - 1)} legs per sweep)")
    print()
    for b in cfg.BRANCHES:
        print(f"  {b['name']:<8} IVR {b['ext']:<8} register {b['sip_user']}@{b['sip_server']}:{b['sip_port']}  pass ****")


def main():
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--state-dir", type=Path, default=None,
                    help="overrides STATE_DIR from config.conf")
    ap.add_argument("--force", action="store_true",
                    help="run now even if the configured interval hasn't elapsed")
    sub = ap.add_subparsers(dest="command", required=True)
    sub.add_parser("verify", help="dial every pair, POST the report, exit non-zero if any leg failed")
    sub.add_parser("send-health", help="the same, but always exit 0 — for testing the endpoint")
    sub.add_parser("show-config", help="print the resolved branch list (passwords redacted)")
    args = ap.parse_args()

    local = config.load_local()
    state_dir = args.state_dir or Path(local["STATE_DIR"])
    state_dir.mkdir(parents=True, exist_ok=True)

    cfg = _load(local, state_dir)

    if args.command == "show-config":
        show_config(cfg)
        return

    if not args.force and not _due(state_dir, cfg.INTERVAL_MINUTES):
        log.info("last sweep was less than %g min ago — nothing to do (use --force to override)",
                 cfg.INTERVAL_MINUTES)
        return

    reference_duration_sec = load_reference_duration(Path(cfg.REFERENCE_WAV))

    _stamp(state_dir)   # before dialling, so a crash mid-sweep can't hot-loop the timer

    overall_ok, results = _check(cfg, state_dir, reference_duration_sec)
    _post(cfg, overall_ok, results, state_dir)

    if args.command == "verify" and not overall_ok:
        sys.exit(2)


if __name__ == "__main__":
    main()
