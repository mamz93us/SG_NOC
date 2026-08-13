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
  prober.py           one pjsua call: register, dial, record, measure
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
python3 -m voice_mesh.cli verify        # what the service runs; exits 2 if any leg failed
python3 -m voice_mesh.cli verify --force  # ignore the interval gate
python3 -m voice_mesh.cli send-health   # same sweep and POST, always exits 0
python3 -m voice_mesh.cli show-config   # resolved branch list, passwords redacted
```

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
