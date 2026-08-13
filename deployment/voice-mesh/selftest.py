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
import sys
import tempfile
import types
import urllib.error
from pathlib import Path
from unittest import mock

PROJECT = Path(__file__).resolve().parent
sys.path.insert(0, str(PROJECT))

from voice_mesh import cli, config, noc  # noqa: E402

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
