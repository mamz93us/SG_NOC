"""Local configuration for the voice-mesh prober.

config.conf used to carry the whole BRANCHES list with every branch's SIP
password. It doesn't any more — the branch list now lives in the NOC database
and is fetched at run time (see noc.fetch_config), so changing an extension is
a UI edit rather than an SSH session. What's left here is only what the prober
needs in order to *reach* the NOC.

The per-branch validation did NOT go away with the list, though: it moved onto
whatever the NOC hands back (see validate_branches). A half-configured node in
the admin UI should fail loudly at startup, not quietly produce a screen of
meaningless FAILs.
"""
import sys
from pathlib import Path

CONFIG_PATH = Path(__file__).resolve().parent.parent / "config.conf"

PLACEHOLDER = "CHANGEME"
REQUIRED_FIELDS = ("NOC_CONFIG_URL", "NOC_REPORT_URL", "NOC_SECRET", "REFERENCE_WAV")
_DEFAULTS = {
    "NOC_CONFIG_URL": "http://127.0.0.1/api/voice-mesh/config",
    "NOC_REPORT_URL": "http://127.0.0.1/api/voice-mesh/report",
    "REFERENCE_WAV": "../reference.wav",
    "STATE_DIR": "/var/lib/voice-mesh",
    "PJSUA_BIN": "pjsua",
}

_BRANCH_REQUIRED = ("name", "ext", "sip_server", "sip_user", "sip_pass")
_BRANCH_DEFAULT_SIP_PORT = 5060


def _parse_lines(path: Path, text: str) -> dict:
    values = dict(_DEFAULTS)
    for lineno, raw in enumerate(text.splitlines(), start=1):
        line = raw.strip()
        if not line or line.startswith("#"):
            continue
        if "=" not in line:
            sys.exit(f"{path}:{lineno}: invalid line (expected KEY = value): {raw!r}")
        key, _, value = line.partition("=")
        values[key.strip()] = value.strip()
    return values


def load_local(path: Path = CONFIG_PATH) -> dict:
    """Read config.conf: plain `KEY = value` lines, nothing else."""
    if not path.exists():
        sys.exit(
            f"{path} not found — copy config.conf.example to config.conf and fill in "
            "NOC_SECRET (generate one at /admin/network/voice-mesh)."
        )

    values = _parse_lines(path, path.read_text())

    missing = [f for f in REQUIRED_FIELDS if not values.get(f)]
    if missing:
        sys.exit(f"{path}: missing required field(s): {', '.join(missing)}")

    placeholders = [f for f in REQUIRED_FIELDS if values.get(f) == PLACEHOLDER]
    if placeholders:
        sys.exit(
            f"{path} still has placeholder value(s) for: {', '.join(placeholders)} "
            "— edit that file with real values."
        )

    # REFERENCE_WAV is written relative to the project dir for readability.
    values["REFERENCE_WAV"] = str((path.parent / values["REFERENCE_WAV"]).resolve())

    return values


def validate_branches(entries, source: str = "the NOC") -> list:
    """Check and normalise the branch list.

    Applied to whatever /api/voice-mesh/config returns, so the same guarantees
    that used to protect a hand-edited file now protect the database.
    """
    if not isinstance(entries, list):
        sys.exit(f"{source}: branches must be a list, got {type(entries).__name__}")

    if len(entries) < 2:
        sys.exit(
            f"{source}: only {len(entries)} active branch(es) configured — a mesh needs "
            "at least 2. Add or re-activate branches at /admin/network/voice-mesh."
        )

    branches = []
    seen_names = set()

    for i, entry in enumerate(entries, start=1):
        if not isinstance(entry, dict):
            sys.exit(f"{source}: branch #{i} must be an object")

        missing = [f for f in _BRANCH_REQUIRED if not entry.get(f)]
        if missing:
            label = entry.get("name") or f"#{i}"
            sys.exit(f"{source}: branch {label} is missing field(s): {', '.join(missing)}")

        branch = {f: str(entry[f]) for f in _BRANCH_REQUIRED}

        if any(v == PLACEHOLDER for v in branch.values()):
            sys.exit(f"{source}: branch {branch['name']} still has a placeholder CHANGEME value")

        sip_port = entry.get("sip_port", _BRANCH_DEFAULT_SIP_PORT)
        try:
            branch["sip_port"] = int(sip_port)
        except (TypeError, ValueError):
            sys.exit(f"{source}: branch {branch['name']}: sip_port must be an integer, got {sip_port!r}")

        if branch["name"] in seen_names:
            sys.exit(f"{source}: duplicate branch name {branch['name']!r}")
        seen_names.add(branch["name"])

        branches.append(branch)

    return branches
