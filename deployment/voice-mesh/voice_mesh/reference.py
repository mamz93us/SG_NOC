"""The reference is a single known-good .wav file (the exact same fixed
prompt uploaded to every branch's IVR, per README step 1) — not a
live-captured value. This module derives the expected call duration from
that file and compares each actual capture against it. There is no
capture step and nothing to keep in sync per branch pair: point
REFERENCE_WAV in config.conf at the file and every run compares against
it directly."""
import sys
from pathlib import Path

from .prober import CallCapture, content_duration_sec


def load_reference_duration(path: Path) -> float:
    """Reads REFERENCE_WAV's content duration once at startup (leading/
    trailing silence trimmed, same as every call recording — see
    content_duration_sec) so it's compared on equal footing against
    captures whose call length varies independently of the prompt itself.
    This is a misconfiguration check, not a per-run flakiness check — an
    unreadable or missing file means the deployment isn't set up
    correctly, so it exits immediately rather than producing a health
    report full of meaningless failures."""
    if not path.exists():
        sys.exit(f"REFERENCE_WAV not found at {path} — point it at the known-good prompt wav")
    try:
        duration = content_duration_sec(path)
    except Exception as e:
        sys.exit(f"REFERENCE_WAV at {path} isn't a readable wav file: {e}")
    if duration <= 0:
        sys.exit(f"REFERENCE_WAV at {path} has zero duration")
    return duration


def compare(capture: CallCapture, reference_duration_sec: float, tolerance_pct: float) -> tuple[bool, str]:
    if not capture.answered:
        return False, "call never reached CONFIRMED (signalling failure)"
    if capture.rx_pkt <= 0:
        return False, "no RTP media received"

    drift_pct = abs(capture.duration_sec - reference_duration_sec) / reference_duration_sec * 100
    if drift_pct <= tolerance_pct:
        return True, "OK"
    return False, (
        f"duration {capture.duration_sec:.2f}s vs reference {reference_duration_sec:.2f}s "
        f"deviates beyond {tolerance_pct}% tolerance"
    )
