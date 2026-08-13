"""Measuring what actually came back on a call.

The first version of this trimmed leading and trailing silence off the recording
and compared what was left against the reference's length, with the silence
threshold set to 10% of the loudest 20ms block in the file. In the field that
produced numbers that meant nothing:

  * a recording containing only comfort noise has its threshold set BY that
    noise, so nearly every block counts as content and the whole capture reads
    as one long sound — which is why failures showed up as a duration equal to
    the force-hangup ceiling (the 10s readings);
  * one click or RTP glitch becomes the peak, pushing the threshold above the
    actual prompt so only the click gets measured (the 1s readings);
  * an empty recording reads as 0.0s, indistinguishable from "never measured";
  * and because only the LENGTH was compared, any audio of roughly the right
    duration passed. Ringback, hold music or the wrong prompt entirely all
    scored OK.

Three things are measured here instead, computed identically for the reference
and for every capture:

  1. Is there audible signal at all? Judged against an ABSOLUTE floor as well as
     a relative one, so a file of pure noise fails outright rather than being
     scaled up into "content".
  2. How long is the longest CONTINUOUS run of that signal? Runs separated by a
     short gap are merged; isolated blips are not. A click at the head of the
     recording can no longer stretch the measurement across the silence that
     follows it.
  3. Does it sound like the reference? A pitch contour is compared, so a
     recording of the right length but the wrong audio is caught.

Everything is stdlib — this runs under the system Python on the NOC host with no
packages installed.
"""
import math
import wave
from array import array
from dataclasses import dataclass, field
from pathlib import Path

FRAME_MS = 20.0
# Runs of signal separated by less than this are one sound. The reference prompt
# steps between tones without a real gap; a pause longer than this is a genuine
# break, not part of the same utterance.
GAP_MERGE_MS = 200.0

# Absolute RMS floor for 16-bit audio (~-44 dBFS). This is the guard that makes
# a silent or noise-only recording fail instead of being normalised into
# "content" — without it the threshold is relative to whatever the loudest thing
# in the file happens to be, even if that thing is hiss.
ABSOLUTE_FLOOR = 200.0

# Signal level is taken at this percentile rather than the maximum, so a single
# click cannot define the threshold for the whole recording.
SIGNAL_PERCENTILE = 0.90
RELATIVE_FLOOR = 0.25

# Pitch contours are resampled to this many points before comparison, so
# recordings of slightly different length still line up.
CONTOUR_POINTS = 32

# Median relative pitch error must be below this for the audio to be considered
# the same prompt. Tones an octave apart differ by 100%, so this is generous
# enough for jitter and codec artefacts while still rejecting different audio.
MAX_PITCH_ERROR = 0.40


@dataclass
class AudioProfile:
    """What a recording contains, as far as we can tell from the samples."""

    path: str = ""
    sample_rate: int = 0
    total_sec: float = 0.0
    duration_sec: float = 0.0     # longest continuous run of audible signal
    peak_rms: float = 0.0
    signal_rms: float = 0.0
    audible: bool = False
    error: str = ""
    contour: list = field(default_factory=list)   # pitch (Hz) per audible frame


def _percentile(values: list, fraction: float) -> float:
    if not values:
        return 0.0
    ordered = sorted(values)
    index = min(len(ordered) - 1, max(0, int(round(fraction * (len(ordered) - 1)))))
    return ordered[index]


def _read_samples(path: Path):
    """Mono 16-bit samples, or (None, reason)."""
    if not path.exists() or path.stat().st_size == 0:
        return None, 0, "recording is empty"

    try:
        with wave.open(str(path), "rb") as w:
            channels = w.getnchannels()
            width = w.getsampwidth()
            rate = w.getframerate()
            raw = w.readframes(w.getnframes())
    except (wave.Error, OSError, EOFError) as e:
        return None, 0, f"recording is not a readable wav: {e}"

    if width != 2:
        return None, rate, f"only 16-bit PCM is supported, got {width * 8}-bit"

    samples = array("h", raw[: len(raw) - (len(raw) % 2)])

    if channels > 1:
        samples = array("h", samples[::channels])

    return samples, rate, ""


def analyze(path: Path) -> AudioProfile:
    """Profile one recording. Never raises — an unreadable or silent file comes
    back as a profile with `audible=False` and a reason, because a bad capture
    is a result to report, not a crash mid-sweep."""
    samples, rate, error = _read_samples(path)

    if samples is None or not rate:
        return AudioProfile(path=str(path), sample_rate=rate, error=error or "no audio")

    profile = AudioProfile(
        path=str(path),
        sample_rate=rate,
        total_sec=len(samples) / float(rate),
    )

    frame_len = max(1, int(rate * FRAME_MS / 1000))
    if len(samples) < frame_len:
        profile.error = "recording is shorter than one frame"
        return profile

    rms_per_frame = []
    pitch_per_frame = []

    for start in range(0, len(samples) - frame_len + 1, frame_len):
        frame = samples[start:start + frame_len]

        total = 0
        crossings = 0
        previous = frame[0]
        for sample in frame:
            total += sample * sample
            # Zero crossings give a cheap pitch estimate, which is all that's
            # needed to tell one tone from another without an FFT.
            if (sample >= 0) != (previous >= 0):
                crossings += 1
            previous = sample

        rms_per_frame.append(math.sqrt(total / frame_len))
        pitch_per_frame.append(crossings / 2.0 / (frame_len / float(rate)))

    profile.peak_rms = max(rms_per_frame)
    profile.signal_rms = _percentile(rms_per_frame, SIGNAL_PERCENTILE)

    threshold = max(ABSOLUTE_FLOOR, profile.signal_rms * RELATIVE_FLOOR)
    loud = [rms >= threshold for rms in rms_per_frame]

    if not any(loud):
        profile.error = (
            f"no audible audio (loudest {profile.peak_rms:.0f} rms, "
            f"needs {ABSOLUTE_FLOOR:.0f})"
        )
        return profile

    start, end = _longest_run(loud, frame_len, rate)

    profile.duration_sec = (end - start) * frame_len / float(rate)
    profile.contour = pitch_per_frame[start:end]
    profile.audible = profile.duration_sec > 0

    if not profile.audible:
        profile.error = "audible signal too short to measure"

    return profile


def _longest_run(loud: list, frame_len: int, rate: int) -> tuple:
    """Longest stretch of audible frames, merging gaps shorter than
    GAP_MERGE_MS. Returns (start_frame, end_frame_exclusive).

    Merging matters because the prompt steps between tones; taking the longest
    run rather than first-to-last matters because it stops a click at the head
    of the recording from dragging the measurement across the silence after it.
    """
    max_gap = max(1, int(GAP_MERGE_MS / FRAME_MS))

    runs = []
    start = None
    gap = 0

    for index, is_loud in enumerate(loud):
        if is_loud:
            if start is None:
                start = index
            gap = 0
        elif start is not None:
            gap += 1
            if gap > max_gap:
                runs.append((start, index - gap + 1))
                start = None
                gap = 0

    if start is not None:
        runs.append((start, len(loud) - gap))

    if not runs:
        return 0, 0

    return max(runs, key=lambda r: r[1] - r[0])


def _resample(sequence: list, points: int) -> list:
    if not sequence:
        return []
    if len(sequence) == 1:
        return [sequence[0]] * points

    out = []
    for i in range(points):
        position = i * (len(sequence) - 1) / (points - 1)
        low = int(math.floor(position))
        high = min(low + 1, len(sequence) - 1)
        weight = position - low
        out.append(sequence[low] * (1 - weight) + sequence[high] * weight)
    return out


def pitch_match(capture: AudioProfile, reference: AudioProfile) -> float:
    """How alike the two pitch contours are, 0.0 to 1.0.

    Both are resampled to a fixed length first, so this answers "is it the same
    sound?" independently of "is it the same length?" — which is checked
    separately. The median error is used rather than the mean so one glitched
    frame cannot condemn an otherwise clean capture.
    """
    a = _resample(capture.contour, CONTOUR_POINTS)
    b = _resample(reference.contour, CONTOUR_POINTS)

    if not a or not b:
        return 0.0

    errors = []
    for x, y in zip(a, b):
        # Floor the denominator: near-zero reference pitch would otherwise make
        # any difference look infinite.
        errors.append(min(1.0, abs(x - y) / max(y, 50.0)))

    errors.sort()
    median = errors[len(errors) // 2]

    return max(0.0, 1.0 - median)
