"""Place an outbound call from this branch to the central test IVR, record
what comes back, and report RTP/audio stats for comparison against a
reference capture.
"""
import contextlib
import os
import re
import shutil
import stat
import subprocess
import sys
import tempfile
import threading
import time
from dataclasses import dataclass
from pathlib import Path

from . import audio

_FALLBACK_PJSUA_LOCATIONS = (Path("/usr/local/bin/pjsua"), Path.home() / ".local" / "bin" / "pjsua")


def resolve_pjsua(pjsua_bin: str = "pjsua") -> str:
    if "/" in pjsua_bin:
        if Path(pjsua_bin).exists():
            return pjsua_bin
        sys.exit(f"pjsua not found at {pjsua_bin}")
    found = shutil.which(pjsua_bin)
    if found:
        return found
    for candidate in _FALLBACK_PJSUA_LOCATIONS:
        if candidate.exists():
            return str(candidate)
    sys.exit(
        f"'{pjsua_bin}' not found on PATH or in "
        f"{', '.join(str(p) for p in _FALLBACK_PJSUA_LOCATIONS)}. "
        "Install it system-wide: sudo cp <built-pjsua> /usr/local/bin/pjsua"
    )


@dataclass
class CallCapture:
    answered: bool
    rx_pkt: int
    duration_sec: float
    log_text: str
    # What the recording actually contains. None when no call was placed.
    # reference.compare() judges against this; duration_sec is kept alongside it
    # because that is the field the health report puts on the wire.
    profile: object = None


def _write_credentials_file(sip_server, sip_port, sip_user, sip_pass, local_port) -> Path:
    fd, path = tempfile.mkstemp(prefix="branch-dummy-phone-", suffix=".cfg")
    os.chmod(path, stat.S_IRUSR | stat.S_IWUSR)
    with os.fdopen(fd, "w") as f:
        f.write(f"--local-port={local_port}\n")
        f.write(f"--id=sip:{sip_user}@{sip_server}\n")
        f.write(f"--registrar=sip:{sip_server}:{sip_port}\n")
        f.write("--realm=*\n")
        f.write(f"--username={sip_user}\n")
        f.write(f"--password={sip_pass}\n")
    return Path(path)


def call_and_record(
    sip_server: str,
    sip_user: str,
    sip_pass: str,
    dest: str,
    rec_path: Path,
    max_duration: int = 30,
    sip_port: int = 5060,
    local_port: int = 5080,
    pjsua_bin: str = "pjsua",
    registration_timeout: int = 10,
) -> CallCapture:
    """Register and wait for confirmation, *then* dial `dest` (the IVR),
    record whatever it sends back, and hang up either when it disconnects
    us or `max_duration` elapses, whichever is first. If registration
    doesn't succeed within `registration_timeout` seconds, no call is
    attempted."""
    pjsua_bin = resolve_pjsua(pjsua_bin)
    rec_path.parent.mkdir(parents=True, exist_ok=True)
    rec_path.unlink(missing_ok=True)
    cfg_path = _write_credentials_file(sip_server, sip_port, sip_user, sip_pass, local_port)

    # No destination on the command line — see sip-tone-tester's prober.py
    # for why (avoids racing INVITE against REGISTER). stdbuf forces
    # line-buffered stdout so our real-time event detection isn't reading
    # stale, already-buffered output.
    cmd = [
        "stdbuf", "-oL", "-eL",
        pjsua_bin,
        f"--config-file={cfg_path}",
        "--null-audio",
        "--no-vad",
        f"--rec-file={rec_path}",
        "--auto-rec",
        "--app-log-level=4",
    ]

    lines: list[str] = []
    registered = threading.Event()
    reg_failed = threading.Event()
    reg_fail_reason = ""
    disconnected = threading.Event()

    def _reader(proc):
        nonlocal reg_fail_reason
        for raw in proc.stdout:
            line = raw.rstrip("\n")
            lines.append(line)
            if "registration success" in line and not registered.is_set():
                registered.set()
            elif "registration failed" in line and not reg_failed.is_set():
                reg_fail_reason = line.strip()
                reg_failed.set()
            elif "DISCONNECTED" in line:
                disconnected.set()

    proc = None
    try:
        proc = subprocess.Popen(
            cmd, stdin=subprocess.PIPE, stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT, text=True, bufsize=1,
        )
        reader_thread = threading.Thread(target=_reader, args=(proc,), daemon=True)
        reader_thread.start()

        reg_ok = registered.wait(timeout=registration_timeout) and not reg_failed.is_set()
        if not reg_ok:
            with contextlib.suppress(BrokenPipeError):
                proc.stdin.write("q\n")
                proc.stdin.flush()
            with contextlib.suppress(OSError, ValueError):
                proc.stdin.close()
            proc.wait(timeout=10)
            reader_thread.join(timeout=5)
            log_text = "\n".join(lines)
            return CallCapture(answered=False, rx_pkt=0, duration_sec=0.0, log_text=log_text)

        with contextlib.suppress(BrokenPipeError):
            proc.stdin.write("m\n")
            proc.stdin.flush()
            time.sleep(0.3)
            proc.stdin.write(f"sip:{dest}@{sip_server}\n")
            proc.stdin.flush()

        # the IVR is expected to hang up on its own after playing its
        # announcement; only force a hangup if it doesn't within max_duration
        hung_up_by_peer = disconnected.wait(timeout=max_duration)
        if not hung_up_by_peer:
            with contextlib.suppress(BrokenPipeError):
                proc.stdin.write("h\n")
                proc.stdin.flush()
            time.sleep(1)

        # Close stdin as well as sending 'q'. Without an EOF, a pjsua that has
        # stopped reading its menu — wedged, or already tearing down — sits
        # there until the wait() below times out, 15 seconds of nothing per
        # call. Closing stdin is the only signal that does not depend on it
        # still processing commands.
        with contextlib.suppress(BrokenPipeError):
            proc.stdin.write("q\n")
            proc.stdin.flush()
        with contextlib.suppress(OSError, ValueError):
            proc.stdin.close()

        proc.wait(timeout=15)
        reader_thread.join(timeout=5)
    finally:
        if proc and proc.poll() is None:
            proc.kill()
        cfg_path.unlink(missing_ok=True)

    log_text = "\n".join(lines)
    answered = bool(re.search(r"CONFIRMED", log_text))
    rx_pkt = _rx_pkt_from_log(log_text)
    profile = audio.analyze(rec_path)

    return CallCapture(
        answered=answered,
        rx_pkt=rx_pkt,
        duration_sec=profile.duration_sec,
        log_text=log_text,
        profile=profile,
    )


def _rx_pkt_from_log(log_text: str) -> int:
    idx = log_text.find("RX pt=")
    if idx == -1:
        return 0
    m = re.search(r"total\s+(\d+)\s*pkt", log_text[idx : idx + 400])
    return int(m.group(1)) if m else 0
