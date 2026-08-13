#!/usr/bin/env python3
"""Self-test for the voice-mesh prober — no NOC, no UCM, no pjsua needed.

Exercises everything that isn't a real phone call: config parsing, the branch
validation applied to whatever the NOC returns, the config cache fallback, the
report retry-and-spill path, the interval gate and the reference checksum
assertion.

Deliberately dependency-free (no pytest) so it can be run on the NOC host
straight after install, before anything is dialled:

    python3 deployment/voice-mesh/selftest.py

Exits non-zero on the first failure.
"""
import hashlib
import json
import logging
import math
import sys
import tempfile
import types
import urllib.error
import wave
from array import array
from pathlib import Path
from unittest import mock

PROJECT = Path(__file__).resolve().parent
sys.path.insert(0, str(PROJECT))

from voice_mesh import audio, cli, config, noc, prober, reference  # noqa: E402

logging.disable(logging.WARNING)     # the failure paths log loudly by design

REFERENCE = PROJECT / "reference.wav"

BRANCHES = [
    {"name": "CAI", "ext": "7076", "sip_server": "10.9.8.10", "sip_user": "6150", "sip_pass": "x"},
    {"name": "JED", "ext": "7071", "sip_server": "10.1.8.10", "sip_user": "1999", "sip_pass": "y", "sip_port": "5061"},
]

passed = 0


def check(label: str, condition: bool) -> None:
    global passed
    if not condition:
        sys.exit(f"FAIL: {label}")
    passed += 1
    print(f"  ok  {label}")


def rejects(label: str, entries: list) -> None:
    try:
        config.validate_branches(entries)
    except SystemExit:
        check(label, True)
        return
    sys.exit(f"FAIL: {label} — should have been rejected")


class FakeResponse:
    status = 200

    def __init__(self, body):
        self._body = json.dumps(body).encode()

    def read(self):
        return self._body

    def __enter__(self):
        return self

    def __exit__(self, *args):
        return False


def _write_wav(path: Path, segments, rate: int = 8000) -> None:
    """Synthesise a wav from [(frequency_hz, seconds, amplitude)] — frequency 0
    means silence. Amplitude is 0..1 of full scale."""
    samples = array("h")
    for freq, seconds, amplitude in segments:
        count = int(rate * seconds)
        peak = amplitude * 32767
        for n in range(count):
            value = 0.0 if freq == 0 else math.sin(2 * math.pi * freq * n / rate)
            samples.append(int(max(-32767, min(32767, value * peak))))

    with wave.open(str(path), "wb") as w:
        w.setnchannels(1)
        w.setsampwidth(2)
        w.setframerate(rate)
        w.writeframes(samples.tobytes())


def _capture(profile, rx_pkt: int = 250, answered: bool = True):
    return prober.CallCapture(answered=answered, rx_pkt=rx_pkt,
                              duration_sec=profile.duration_sec, log_text="", profile=profile)


def _audio_checks() -> None:
    """The failure modes reported from the field: recordings measuring 0s, 1s,
    or the full hangup ceiling, and audio of the right length that isn't the
    prompt at all."""
    prompt = [(350, 1.0, 0.5), (440, 1.0, 0.5), (1000, 1.0, 0.5),
              (2000, 1.0, 0.5), (3000, 1.0, 0.5)]

    with tempfile.TemporaryDirectory() as tmp:
        tmp = Path(tmp)

        ref_path = tmp / "ref.wav"
        _write_wav(ref_path, prompt)
        ref = audio.analyze(ref_path)
        check(f"reference profiles as ~5s of audio (got {ref.duration_sec:.2f}s)",
              ref.audible and 4.8 <= ref.duration_sec <= 5.2)

        # A good capture: the same prompt with the silence a real call carries
        # either side of it.
        good = tmp / "good.wav"
        _write_wav(good, [(0, 1.2, 0.0)] + prompt + [(0, 2.5, 0.0)])
        p = audio.analyze(good)
        check(f"lead-in and trailing silence are excluded (got {p.duration_sec:.2f}s)",
              4.8 <= p.duration_sec <= 5.2)
        ok, reason = reference.compare(_capture(p), ref, tolerance_pct=10)
        check(f"a clean capture passes: {reason}", ok)

        # Was: threshold set by the noise itself, so the whole file read as
        # content and reported the hangup ceiling.
        noise = tmp / "noise.wav"
        _write_wav(noise, [(60, 10.0, 0.002)])
        p = audio.analyze(noise)
        check("comfort noise is not mistaken for content", not p.audible)
        ok, reason = reference.compare(_capture(p), ref, tolerance_pct=10)
        check(f"a noise-only capture fails with a real reason: {reason[:52]}…", not ok)

        # Was: the click became the peak, so the threshold rose above the tone
        # and only the click got measured.
        clicky = tmp / "click.wav"
        _write_wav(clicky, [(3000, 0.04, 1.0), (0, 1.5, 0.0)] + prompt + [(0, 1.0, 0.0)])
        p = audio.analyze(clicky)
        check(f"a loud click no longer defines the threshold (got {p.duration_sec:.2f}s)",
              4.8 <= p.duration_sec <= 5.2)
        check("the prompt after a click still passes",
              reference.compare(_capture(p), ref, tolerance_pct=10)[0])

        silent = tmp / "silent.wav"
        _write_wav(silent, [(0, 6.0, 0.0)])
        p = audio.analyze(silent)
        check("pure silence is reported as silence, not as 0.00s of content",
              not p.audible and "no audible audio" in p.error)

        empty = tmp / "empty.wav"
        empty.write_bytes(b"")
        check("an empty file is a reason, not a crash", not audio.analyze(empty).audible)

        # The false POSITIVE that duration-only comparison could never catch.
        wrong = tmp / "wrong.wav"
        _write_wav(wrong, [(425, 5.0, 0.5)])
        p = audio.analyze(wrong)
        check(f"wrong audio of the right length is still ~5s (got {p.duration_sec:.2f}s)",
              4.8 <= p.duration_sec <= 5.2)
        ok, reason = reference.compare(_capture(p), ref, tolerance_pct=10)
        check(f"…but is rejected on content: {reason[:46]}…", not ok)

        # A capture that ran to the force-hangup ceiling.
        long_tone = tmp / "long.wav"
        _write_wav(long_tone, [(350, 10.0, 0.5)])
        ok, reason = reference.compare(_capture(audio.analyze(long_tone)), ref, tolerance_pct=10)
        check(f"an over-long capture names the likely cause: {reason[-38:]}", not ok)

        # Media counted but nothing audible arrived — one-way audio.
        ok, reason = reference.compare(_capture(audio.analyze(silent), rx_pkt=289), ref, tolerance_pct=10)
        check(f"RTP with no audio is distinguished from no RTP: {reason[:44]}…",
              not ok and "289 RTP packets arrived" in reason)

        check("no RTP at all still reports that",
              reference.compare(_capture(audio.analyze(good), rx_pkt=0), ref, 10)[1] == "no RTP media received")
        check("an unanswered call still reports signalling",
              "signalling" in reference.compare(_capture(audio.analyze(good), answered=False), ref, 10)[1])


def main() -> None:
    sha = hashlib.sha256(REFERENCE.read_bytes()).hexdigest()

    print("branch validation")
    branches = config.validate_branches(BRANCHES)
    check("sip_port coerced to int", branches[1]["sip_port"] == 5061)
    rejects("a one-branch mesh is rejected", [BRANCHES[0]])
    rejects("a missing password is rejected", [{**BRANCHES[0], "sip_pass": ""}, BRANCHES[1]])
    rejects("a duplicate branch name is rejected", [BRANCHES[0], {**BRANCHES[1], "name": "CAI"}])
    rejects("a CHANGEME password is rejected", [{**BRANCHES[0], "sip_pass": "CHANGEME"}, BRANCHES[1]])

    payload = {
        "runner_name": "noc-voice-mesh", "interval_minutes": 30, "duration": 10,
        "tolerance_pct": 10, "local_port": 5080, "reference_sha256": sha,
        "branches": BRANCHES,
    }

    with tempfile.TemporaryDirectory() as tmp:
        state = Path(tmp)

        print("\nNOC config fetch")
        with mock.patch.object(noc.urllib.request, "urlopen", return_value=FakeResponse(payload)):
            fetched = noc.fetch_config("http://noc/config", "s", state)
        check("config fetched", fetched["reference_sha256"] == sha)
        check("config cached to disk", (state / noc.CONFIG_CACHE_NAME).exists())

        with mock.patch.object(noc.urllib.request, "urlopen", side_effect=urllib.error.URLError("down")):
            cached = noc.fetch_config("http://noc/config", "s", state)
        check("unreachable NOC falls back to the cache", cached["branches"][0]["name"] == "CAI")

        unauthorized = urllib.error.HTTPError("http://noc", 401, "Unauthorized", {}, None)
        with mock.patch.object(noc.urllib.request, "urlopen", side_effect=unauthorized):
            try:
                noc.fetch_config("http://noc/config", "s", state)
                sys.exit("FAIL: a 401 must not fall back to the cache")
            except SystemExit as e:
                check("a rejected secret refuses to run on stale credentials", "401" in str(e))

        print("\nreport POST")
        report = {"runner_name": "t", "timestamp": "2026-01-01T00:00:00", "ok": True, "results": []}
        with mock.patch.object(noc.urllib.request, "urlopen", side_effect=urllib.error.URLError("down")), \
             mock.patch.object(noc.time, "sleep"):
            sent = noc.post_report("http://noc/report", "s", report, state, attempts=2)
        check("a failed POST reports failure", sent is False)
        check("a failed POST keeps the sweep on disk",
              list((state / noc.UNSENT_DIR_NAME).glob("*.json")) != [])

        with mock.patch.object(noc.urllib.request, "urlopen", return_value=FakeResponse({"ok": True})):
            check("a good POST succeeds", noc.post_report("http://noc/report", "s", report, state) is True)

        print("\ninterval gate")
        check("the first sweep is always due", cli._due(state, 30) is True)
        cli._stamp(state)
        check("a sweep just now is not due again", cli._due(state, 30) is False)
        check("a sweep past its interval is due", cli._due(state, 0.001) is True)

    print("\nargument parsing")
    # A flag declared only on the top-level parser is rejected after the
    # subcommand, and vice versa. Both orders have to work — the docs use one,
    # muscle memory uses the other.
    parser = cli.build_parser()
    for argv in (["verify", "--force"], ["--force", "verify"]):
        parsed = parser.parse_args(argv)
        check(f"{' '.join(argv)} -> force={parsed.force}", parsed.force is True and parsed.command == "verify")
    for argv in (["verify", "--state-dir", "/tmp/x"], ["--state-dir", "/tmp/x", "verify"]):
        parsed = parser.parse_args(argv)
        # Compare Paths, not strings — the separator differs by platform.
        check(f"{' '.join(argv)} -> state_dir set", parsed.state_dir == Path("/tmp/x"))
    check("verify alone does not force", parser.parse_args(["verify"]).force is False)
    check("verify alone leaves state_dir unset", parser.parse_args(["verify"]).state_dir is None)

    print("\nsweep requests")
    # "Run a sweep now" and "Retry this leg" in the admin UI both arrive here as
    # a scope on the config the prober fetches.
    three = config.validate_branches(BRANCHES + [
        {"name": "RYD", "ext": "7072", "sip_server": "10.2.88.10", "sip_user": "2999", "sip_pass": "z"},
    ])
    check("no scope dials the whole mesh", len(list(cli._pairs(three))) == 6)
    scoped = list(cli._pairs(three, {"caller": "CAI", "dest": "JED"}))
    check("a scoped retry dials exactly one leg", len(scoped) == 1)
    check("...and it is the leg that was asked for",
          scoped[0][0]["name"] == "CAI" and scoped[0][1]["name"] == "JED")
    check("a scope naming a branch that is gone dials nothing",
          list(cli._pairs(three, {"caller": "CAI", "dest": "NOPE"})) == [])

    print("\nreference prompt path")
    # This is the layout bug that broke the first live deploy: config.conf moved
    # up a directory when the project was vendored into the app repo, but its
    # REFERENCE_WAV kept the upstream '../' and resolved one level too high.
    fake_conf = PROJECT / "config.conf"
    check("a plain filename resolves beside config.conf",
          config.resolve_reference(fake_conf, "reference.wav") == str(REFERENCE))
    check("a legacy '../' path still finds the prompt",
          config.resolve_reference(fake_conf, "../reference.wav") == str(REFERENCE))
    check("an absolute path is honoured",
          config.resolve_reference(fake_conf, str(REFERENCE)) == str(REFERENCE))
    try:
        config.resolve_reference(fake_conf, "no-such-prompt.wav")
        sys.exit("FAIL: a genuinely missing prompt must be an error")
    except SystemExit as e:
        check("a genuinely missing prompt is a clear error", "does not exist" in str(e))

    print("\nreference prompt")
    cfg = types.SimpleNamespace(REFERENCE_WAV=str(REFERENCE), REFERENCE_SHA256=sha)
    cli._assert_reference(cfg)
    check("the committed prompt matches its own checksum", True)
    cfg.REFERENCE_SHA256 = "0" * 64
    try:
        cli._assert_reference(cfg)
        sys.exit("FAIL: a mismatched prompt must refuse to dial")
    except SystemExit as e:
        check("a mismatched prompt refuses to dial", "mismatch" in str(e))

    print("\naudio measurement")
    _audio_checks()

    print("\nmesh expansion")
    check("2 branches make 2 legs", len(list(cli._pairs(branches))) == 2)
    four = config.validate_branches(BRANCHES + [
        {"name": "RYD", "ext": "7072", "sip_server": "10.2.88.10", "sip_user": "2999", "sip_pass": "z"},
        {"name": "KBR", "ext": "7073", "sip_server": "10.3.0.10", "sip_user": "3999", "sip_pass": "w"},
    ])
    check("4 branches make 12 legs", len(list(cli._pairs(four))) == 12)
    check("nobody calls themselves", all(c["name"] != d["name"] for c, d in cli._pairs(four)))

    print(f"\nreference.wav sha256: {sha}")
    print(f"{passed} checks passed.")


if __name__ == "__main__":
    main()
