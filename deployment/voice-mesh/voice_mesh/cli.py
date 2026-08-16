"""voice-mesh prober: synthetic branch-to-branch call testing for the NOC.

Runs on the NOC host, which reaches every branch's UCM over the Azure tunnels.
For each branch it registers to *that branch's own UCM* with *that branch's own
SIP credentials* and dials every other branch's IVR extension — a full mesh,
derived from the branch list the NOC hands back. Each recording is measured for
audible content, its length compared against the reference prompt and its pitch
contour matched against it, and one combined report covering every pair is
POSTed back.

A leg is never failed on a single call: a failure is re-tested, and if the
re-test disagrees a third call decides it (see _probe_leg). The report says only
OK or FAIL — how many calls it took is written to the log, not onto the result.

Nothing here ever aborts a sweep part-way: an unreachable branch or a wedged
call is recorded as a failed leg and the run moves on, so one bad branch never
costs you visibility into the rest.

Subcommands:
  verify         dial every pair and POST the report (what the systemd service
                 runs). Exits non-zero only if the report could not be
                 delivered — failing legs are output, not a service failure.
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
from dataclasses import dataclass
from datetime import datetime
from pathlib import Path

from . import config, noc
from .prober import call_and_record
from .reference import compare, load_reference_profile

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
log = logging.getLogger("voice-mesh")

LAST_RUN_NAME = "last-run.json"
PROBE_VERSION = "2.1"

# Never more than three calls for one leg: fail, re-test, and a decider if those
# two disagree. See _probe_leg for why.
MAX_ATTEMPTS = 3

# Long enough that a retry isn't racing whatever the first attempt hit — a UCM
# mid-reload, a re-registration, a tunnel that just flapped — and short enough
# that a red mesh still finishes inside a sweep interval.
RETRY_DELAY_SEC = 5


def _pairs(branches, scope=None):
    """Every (caller, dest) pair — each branch calling every other branch's IVR
    on the caller's own UCM and credentials.

    `scope` narrows it to one leg, for a retry requested from the admin UI: one
    call instead of N*(N-1), so confirming a fix takes seconds rather than
    minutes.
    """
    for caller in branches:
        for dest in branches:
            if dest["name"] == caller["name"]:
                continue
            if scope and not (caller["name"] == scope.get("caller") and dest["name"] == scope.get("dest")):
                continue
            yield caller, dest


@dataclass
class Attempt:
    """One call placed for one leg, and what it was judged to be."""

    number: int
    ok: bool
    reason: str
    capture: object = None      # None when the call could not be placed at all


def _rec_path(state_dir: Path, caller: str, ext: str, attempt: int) -> Path:
    """Where attempt `n` of one leg records to.

    The first attempt keeps the plain name every runbook already refers to;
    retries get their own files rather than overwriting it, so a leg that
    flapped leaves both the failure and the pass on disk to compare.
    """
    base = f"last-verify-{caller}-{ext}"
    return state_dir / (f"{base}.wav" if attempt == 1 else f"{base}.attempt{attempt}.wav")


def _dial(cfg, caller, dest, rec_path: Path, reference) -> tuple:
    """Place one call and judge it. Never raises — a call that could not be
    placed is just a failed attempt, so the retry policy treats a wedged pjsua
    the same as a bad recording."""
    try:
        capture = call_and_record(
            sip_server=caller["sip_server"],
            sip_user=caller["sip_user"],
            sip_pass=caller["sip_pass"],
            dest=dest["ext"],
            rec_path=rec_path,
            max_duration=cfg.DURATION,
            sip_port=caller["sip_port"],
            local_port=cfg.LOCAL_PORT,
            pjsua_bin=cfg.PJSUA_BIN,
        )
    except (SystemExit, Exception) as e:
        return False, f"call setup failed: {e}", None

    # The per-call log is where a registration rejection, a missing IVR and a
    # stuck call are actually distinguishable from each other.
    rec_path.with_suffix(".log").write_text(capture.log_text)

    ok, reason = compare(capture, reference, tolerance_pct=cfg.TOLERANCE_PCT)
    return ok, reason, capture


def _probe_leg(cfg, caller, dest, state_dir: Path, reference) -> list:
    """Dial one leg until the answer is believable. Returns every attempt made,
    in order — the last one is always the verdict.

    One call is not evidence. A UCM mid-reload, a lost INVITE or a single
    glitched RTP stream fails a leg that is fine, and the NOC's matrix then
    shows red for something nobody can reproduce by hand. So:

      * a first attempt that passes is taken at its word — the healthy case
        stays one call, and a green sweep does not get three times longer;
      * a failure is re-tested once, and only a second failure is believed;
      * a pass after a failure is one of each, so a third call breaks the tie.

    The cost lands only on legs that are actually misbehaving: a fully red mesh
    dials twice as many calls as before, which is why RETRY_DELAY_SEC is seconds
    and not minutes.
    """
    ext = dest["ext"]

    # A leg that passes cleanly this sweep must not leave last sweep's retries
    # sitting in the state dir looking current.
    for stale in state_dir.glob(f"last-verify-{caller['name']}-{ext}.attempt*"):
        stale.unlink(missing_ok=True)

    def attempt(number: int) -> Attempt:
        if number > 1:
            time.sleep(RETRY_DELAY_SEC)

        ok, reason, capture = _dial(cfg, caller, dest, _rec_path(state_dir, caller["name"], ext, number), reference)

        log.info(
            "[%s -> %s (ext %s)] attempt %s/%s %s rx_pkt=%s duration=%.2fs (reference=%.2fs) — %s",
            caller["name"], dest["name"], ext, number, MAX_ATTEMPTS, "OK" if ok else "FAIL",
            capture.rx_pkt if capture else 0, capture.duration_sec if capture else 0.0,
            reference.duration_sec, reason,
        )
        return Attempt(number=number, ok=ok, reason=reason, capture=capture)

    first = attempt(1)
    if first.ok:
        return [first]

    second = attempt(2)
    if not second.ok:
        return [first, second]

    return [first, second, attempt(3)]


def _verdict(attempts: list) -> tuple:
    """(ok, reason) for a leg: the deciding attempt's, and nothing else.

    A leg is OK or it is FAIL. Whether it took one call or three is not a third
    state and is not decorated onto the result — the reason quotes the deciding
    attempt verbatim, so a leg that passed on the third try reads exactly like
    one that passed on the first.

    The retry history is not lost, it just isn't part of the verdict: every
    attempt is logged as it happens, and _log_retries writes the summary. Read
    the log when you want to know how hard a leg had to work.
    """
    decided = attempts[-1]
    return decided.ok, decided.reason


def _log_retries(caller, dest, attempts: list) -> None:
    """The one place a leg's retry history is recorded. Log only."""
    if len(attempts) == 1:
        return

    trail = ", ".join(f"#{a.number} {'OK' if a.ok else 'FAIL'}" for a in attempts)
    log.warning(
        "[%s -> %s (ext %s)] took %s attempts (%s) — reported as %s",
        caller["name"], dest["name"], dest["ext"], len(attempts), trail,
        "OK" if attempts[-1].ok else "FAIL",
    )


def _check(cfg, state_dir: Path, reference, scope=None):
    results = []

    for caller, dest in _pairs(cfg.BRANCHES, scope):
        attempts = _probe_leg(cfg, caller, dest, state_dir, reference)
        _log_retries(caller, dest, attempts)

        ok, reason = _verdict(attempts)
        capture = attempts[-1].capture

        log.info("[%s -> %s (ext %s)] %s — %s",
                 caller["name"], dest["name"], dest["ext"], "OK" if ok else "FAIL", reason)

        results.append({
            "caller": caller["name"],
            "dest": dest["name"],
            "dest_ext": dest["ext"],
            "ok": ok,
            "rx_pkt": capture.rx_pkt if capture else 0,
            "duration_sec": round(capture.duration_sec, 2) if capture else 0.0,
            "reference_duration_sec": round(reference.duration_sec, 2),
            "reason": reason,
        })

    return all(r["ok"] for r in results), results


def _post(cfg, overall_ok: bool, results: list, state_dir: Path) -> bool:
    return noc.post_report(
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
        SWEEP_REQUEST=remote.get("sweep_request") or None,
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


def _shared_options(suppress: bool) -> argparse.ArgumentParser:
    """The flags that apply to every subcommand.

    Added to both the top-level parser and each subparser, so `--force verify`
    and `verify --force` both work — argparse only accepts a flag on the side it
    was declared, and nobody should have to remember which.

    The subparser copies use SUPPRESS defaults: without that, a subparser would
    overwrite a flag given before the subcommand with its own default, silently
    dropping it.
    """
    p = argparse.ArgumentParser(add_help=False)
    p.add_argument("--state-dir", type=Path,
                   default=argparse.SUPPRESS if suppress else None,
                   help="overrides STATE_DIR from config.conf")
    p.add_argument("--force", action="store_true",
                   default=argparse.SUPPRESS if suppress else False,
                   help="run now even if the configured interval hasn't elapsed")
    return p


def build_parser() -> argparse.ArgumentParser:
    ap = argparse.ArgumentParser(description=__doc__, parents=[_shared_options(False)])
    shared = _shared_options(True)
    sub = ap.add_subparsers(dest="command", required=True)
    sub.add_parser("verify", parents=[shared],
                   help="dial every pair and POST the report; exits non-zero only if the report could not be delivered")
    sub.add_parser("send-health", parents=[shared],
                   help="the same, but always exit 0 — for testing the endpoint")
    sub.add_parser("show-config", parents=[shared],
                   help="print the resolved branch list (passwords redacted)")
    return ap


def main():
    args = build_parser().parse_args()

    local = config.load_local()
    state_dir = args.state_dir or Path(local["STATE_DIR"])
    state_dir.mkdir(parents=True, exist_ok=True)

    cfg = _load(local, state_dir)

    if args.command == "show-config":
        show_config(cfg)
        return

    # A "run now" from the admin UI stands in for the interval gate. The NOC
    # clears the request when the resulting report lands, so it fires once.
    requested = cfg.SWEEP_REQUEST
    scope = None

    if requested and not requested.get("all"):
        scope = {"caller": requested.get("caller"), "dest": requested.get("dest")}
        log.info("retry requested from the NOC: %s -> %s", scope["caller"], scope["dest"])
    elif requested:
        log.info("full sweep requested from the NOC")

    if not args.force and not requested and not _due(state_dir, cfg.INTERVAL_MINUTES):
        log.info("last sweep was less than %g min ago — nothing to do (use --force to override)",
                 cfg.INTERVAL_MINUTES)
        return

    reference = load_reference_profile(Path(cfg.REFERENCE_WAV))
    log.info("reference prompt: %.2fs of audio in %s", reference.duration_sec, cfg.REFERENCE_WAV)

    if scope is None:
        # Before dialling, so a crash mid-sweep can't hot-loop the timer. A
        # scoped retry deliberately does NOT stamp: it tested one leg, so it
        # should not push the next full sweep back by a whole interval.
        _stamp(state_dir)

    overall_ok, results = _check(cfg, state_dir, reference, scope)
    delivered = _post(cfg, overall_ok, results, state_dir)

    failed = sum(1 for r in results if not r["ok"])
    log.info("sweep complete: %s/%s legs OK", len(results) - failed, len(results))

    # Exit non-zero only when the NOC does not have the result. Failing legs are
    # this service's OUTPUT, not its failure: the NOC raises the alerts and its
    # stale check notices if we stop reporting. Exiting non-zero on a red mesh
    # would leave the unit permanently "failed" in systemctl for as long as a
    # branch is down, which says nothing useful and buries a real crash.
    if args.command == "verify" and not delivered:
        sys.exit(2)


if __name__ == "__main__":
    main()
