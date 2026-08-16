# voice-mesh prober

The calling half of the NOC's Voice Mesh. Runs as a systemd timer on the NOC
host, which reaches every branch's UCM over the Azure tunnels.

Each sweep it registers to **every branch's own UCM** with **that branch's own
SIP credentials**, dials **every other branch's test IVR**, records what comes
back, and compares the trimmed prompt duration against `reference.wav`. One
combined report covering all N×(N−1) legs is POSTed to the NOC.

**Setup, troubleshooting and the UCM prerequisites live in
[VOICE_MESH_SETUP.md](../../VOICE_MESH_SETUP.md).** This file is just the map.

```
config.conf.example   copy to config.conf; NOC URL + ingest secret only
reference.wav         the prompt uploaded to every branch's IVR (5.8s tone sequence)
deploy.py             installs the systemd service + timer
install.sh            builds pjsua, then calls deploy.py
selftest.py           dependency-free checks; no NOC, UCM or pjsua needed
voice_mesh/
  cli.py              subcommands, the sweep loop, the interval gate
  config.py           config.conf parsing + validation of the NOC's branch list
  noc.py              fetch config / post report, with cache fallback and spill
  prober.py           one pjsua call: register, dial, record
  audio.py            what a recording actually contains (level, length, pitch)
  reference.py        the pass/fail comparison
```

## Where the configuration lives

The branch list used to be a `BRANCHES = [...]` block in `config.conf` with every
branch's SIP password in it. It now lives in the NOC database and is fetched at
the start of every sweep, so adding a branch or changing an extension is a UI
edit rather than an SSH session.

What stayed here is only what's needed to reach the NOC: the two URLs, the ingest
secret, the state directory and the reference prompt.

The per-branch *validation* did not move away with the list — it now runs against
whatever the NOC returns (`config.validate_branches`), so a half-configured node
in the admin UI fails loudly at startup instead of quietly producing a screen of
meaningless FAILs.

## Subcommands

```sh
python3 -m voice_mesh.cli verify        # what the service runs; exits 2 only if the report could not be delivered
python3 -m voice_mesh.cli verify --force  # ignore the interval gate
python3 -m voice_mesh.cli send-health   # same sweep and POST, always exits 0
python3 -m voice_mesh.cli show-config   # resolved branch list, passwords redacted
```

## A leg is never failed on one call

One call is not evidence — a UCM mid-reload, a lost INVITE or one glitched RTP
stream fails a leg that is fine, and the matrix then shows red for something
nobody can reproduce by hand. So each leg is re-tested until the answer holds:

| attempt 1 | attempt 2 | attempt 3 | verdict |
|---|---|---|---|
| pass | — | — | **OK** (the healthy case is still one call) |
| fail | fail | — | **FAIL** |
| fail | pass | pass | **OK** |
| fail | pass | fail | **FAIL** |

**The report is binary.** A leg is `ok: true` or `ok: false`, with the deciding
attempt's reason quoted verbatim — a leg that passed on the third try is
indistinguishable in the report from one that passed on the first. There is no
retry count and no third state on the wire.

The retries are recorded in the **log** instead. Every attempt logs its own
line, and any leg that took more than one gets a summary from `_log_retries`:

```
WARNING [CAI -> JED (ext 7071)] took 3 attempts (#1 FAIL, #2 OK, #3 OK) — reported as OK
```

`journalctl -u voice-mesh` is where you find the legs that are quietly working
harder than they should be — usually the ones about to go properly red.

Retries cost time only on legs that are misbehaving: a green sweep is unchanged,
a fully red mesh dials twice as many calls. `RETRY_DELAY_SEC` (5s, in `cli.py`)
is the pause between attempts. If a red mesh starts overrunning the sweep
interval, that constant and `MAX_ATTEMPTS` are the dials.

`PROBE_VERSION` is `2.1`. The report schema is unchanged from `2.0` — the retry
policy added no fields to it.

Each attempt keeps its own recording and `.log`: attempt 1 at the usual
`last-verify-<caller>-<ext>.wav`, retries alongside it as
`…-<ext>.attempt2.wav` / `.attempt3.wav`. Retry files are cleared at the start
of each leg, so anything with `.attempt` in the name is from the current sweep.

## How close a recording has to be

`TOLERANCE_PCT` from the NOC is a percentage of the reference length, but a leg
never fails on a difference under **1 second** regardless (`DURATION_GRACE_SEC`
in `reference.py`). On the 5.8s prompt, 10% is 0.58s — tighter than normal IVR
timing and a trimmed RTP tail move the measurement on a healthy leg. With the
grace floor the effective tolerance is ~17%, and the percentage takes over above
1s. Raise `tolerance_pct` in the admin UI if you need more than that; the reason
strings quote plain seconds off, not percentages.

Content matching is separate and deliberately **not** loosened: correct audio
scores 91–100% pitch match even with the tones 8% off, while ringback, a flat
tone or hold music score 33–59%. `MIN_PITCH_MATCH` (60%, in `audio.py`) sits
just above that band — dropping it further would let hold music pass, which is
the exact false positive the check exists for.

## Two things that aren't obvious

**The timer fires more often than the interval.** It wakes every 5 minutes and
the prober decides whether a sweep is actually due, from the interval the NOC
reports. That indirection is the whole reason changing the interval in the admin
UI takes effect without redeploying anything here.

**`DURATION` is not the expected call length.** It's the force-hangup ceiling —
how long we wait for the IVR to hang up on its own before doing it ourselves. The
duration that gets compared should come from the IVR's own hangup. If you see
recorded durations tracking `DURATION` rather than the reference, that detection
isn't firing: check the per-call `.log` next to each recording under the state
dir for what pjsua printed around hangup.

## Credentials

`config.conf` holds the ingest secret; `state/noc-config.json` caches every
branch's SIP password. `deploy.py` chowns both to whichever account the service
runs as, at `0600`/`0750`, and both are gitignored. Credentials reach pjsua through a
`chmod 600` temp file consumed via `--config-file`, never as a `--password`
argument, so they never appear in `ps aux`.
