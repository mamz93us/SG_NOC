"""Pass/fail for one leg.

The reference is a single known-good .wav — the exact prompt uploaded to every
branch's IVR. It is profiled once at startup and every capture is measured the
same way and compared against it. There is no live "capture a reference first"
step and nothing to keep in sync per branch pair.

A leg passes only if all four hold:

  1. the call reached CONFIRMED (signalling worked);
  2. RTP actually came back (media worked);
  3. the recording contains audible signal of roughly the right length;
  4. and that signal sounds like the reference prompt.

(4) is the one that was missing. Comparing length alone means ringback, hold
music, or a completely different prompt of similar duration all score OK — see
audio.py for what the old measurement did and why its numbers were unusable.

The checks run in that order and the first one to fail is the reason reported,
because that ordering is cause before symptom: a leg with no RTP has nothing to
say about pitch, and reporting the pitch would bury the actual fault.
"""
import sys
from pathlib import Path

from . import audio
from .audio import AudioProfile
from .prober import CallCapture

# A length difference smaller than this never fails a leg, however tight
# TOLERANCE_PCT is set in the admin UI.
#
# The percentage alone is unforgiving on a short prompt: 10% of the 5.8s
# reference is 0.58s, and normal IVR timing plus a trimmed RTP tail moves the
# measurement more than that on a leg that is perfectly healthy. The percentage
# stays the operator's dial for anything larger than this floor.
DURATION_GRACE_SEC = 1.0


def load_reference_profile(path: Path) -> AudioProfile:
    """Profile REFERENCE_WAV once at startup.

    This is a misconfiguration check, not a per-run flakiness check: an
    unreadable, silent or missing prompt means the deployment is wrong, so it
    exits immediately rather than producing a health report full of failures
    that all say the same useless thing.
    """
    if not path.exists():
        sys.exit(f"REFERENCE_WAV not found at {path} — point it at the known-good prompt wav")

    profile = audio.analyze(path)

    if not profile.audible:
        sys.exit(
            f"REFERENCE_WAV at {path} has no usable audio: {profile.error or 'silent'}. "
            "It must be the same prompt uploaded to every branch's IVR."
        )

    return profile


def compare(capture: CallCapture, reference: AudioProfile, tolerance_pct: float) -> tuple[bool, str]:
    """Judge one capture. Returns (ok, reason) — the reason is what lands in the
    NOC's matrix, so it has to name the fault, not just report failure."""
    if not capture.answered:
        return False, "call never reached CONFIRMED (signalling failure)"

    if capture.rx_pkt <= 0:
        return False, "no RTP media received"

    profile = capture.profile

    # Media arrived but the recording holds nothing audible: one-way audio, a
    # codec both ends "agreed" on but neither produced, or an IVR that answered
    # and played nothing. Previously this scored as a duration and was compared
    # like real audio.
    if profile is None or not profile.audible:
        detail = (profile.error if profile and profile.error else "recording is silent")
        return False, f"{capture.rx_pkt} RTP packets arrived but {detail}"

    reference_sec = reference.duration_sec
    off_by = abs(profile.duration_sec - reference_sec)

    if off_by > _allowed_drift(reference_sec, tolerance_pct):
        # Name the likely cause: a capture that ran to the hangup ceiling is a
        # different fault from one that was cut short.
        hint = (
            "IVR may not be hanging up on its own"
            if profile.duration_sec > reference_sec
            else "prompt may be truncated or the wrong file"
        )
        return False, (
            f"prompt ran {profile.duration_sec:.2f}s vs reference {reference_sec:.2f}s "
            f"({off_by:.2f}s off) — {hint}"
        )

    match = audio.pitch_match(profile, reference)

    if match < audio.MIN_PITCH_MATCH:
        return False, (
            f"audio does not match the reference prompt (pitch match {match:.0%}, "
            f"needs {audio.MIN_PITCH_MATCH:.0%}) — right length "
            f"({profile.duration_sec:.2f}s), wrong audio"
        )

    return True, f"OK ({profile.duration_sec:.2f}s, match {match:.0%})"


def _allowed_drift(reference_sec: float, tolerance_pct: float) -> float:
    """How far from the reference length a capture may be, in seconds.

    Whichever is more generous: the configured percentage, or the absolute
    grace. One number, so the failure reason can quote plain seconds instead of
    making whoever reads the matrix convert a percentage in their head.
    """
    return max(DURATION_GRACE_SEC, reference_sec * tolerance_pct / 100.0)
