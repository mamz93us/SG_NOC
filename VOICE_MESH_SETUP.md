# Voice Mesh — synthetic branch-to-branch call testing

## What this is

Every 30 minutes, the NOC host registers to **each branch's own UCM** with **that
branch's own SIP credentials** and dials **every other branch's test IVR**. It
records what comes back, trims the silence, and compares the prompt's duration
against a known-good reference. One combined report covering all N×(N−1) legs is
POSTed back to the NOC, which rolls it up onto a matrix at
**Admin → Network → Voice Mesh** and raises alerts.

The tunnel watchdog already proves a packet crosses the tunnel. This proves a
call completes — the two fail independently. A UCM can answer ICMP and TCP/8089
while a dead trunk, a broken IVR, a codec mismatch or one-way audio means not one
call goes through.

## What this does NOT measure

Read this before trusting the board.

Because the calls originate on the NOC rather than from a box at each site,
"CAI → JED" really means *NOC → CAI's UCM (signalling)* plus *CAI's UCM → JED's
UCM (media)*. So:

- a fault between a branch's own phones and its own UCM is **invisible** here;
- a problem on the NOC or its tunnels paints the **whole board red** while every
  branch is fine — check the NOC before you ring seven branches.

An all-red board is a NOC-side symptom until proven otherwise. A single red row
or column is a branch-side one.

---

## 1. Open SIP from the NOC on each UCM — do this first

**This is the blocker. Nothing else in this document produces a green cell until
it is done.**

The tunnel watchdog measured, on 2026-08-11, every one of the seven UCMs staying
**silent on UDP/5060 from 172.16.8.11** while answering ICMP port-unreachable on
every other port. That means SIP is bound and the UCM is *declining requests from
the NOC* — which is why `TunnelProbeSeeder` ships the SIP probe paused. A SIP
REGISTER will hit exactly the same wall.

Prove it on one branch before going further. From the NOC host:

```bash
sudo tcpdump -ni any udp port 5060
```

and in another shell, once pjsua is installed (section 2):

```bash
pjsua --null-audio --id=sip:6150@10.9.8.10 --registrar=sip:10.9.8.10:5060 --realm='*' --username=6150 --password='...' --app-log-level=4
```

Look for `registration success`. If it times out, fix it on that branch's
Grandstream UCM — the probe extension's *"Allowed to register from"* ACL, plus the
global SIP ACL / static defence, must permit `172.16.8.11`. That is a per-device
change on every branch firewall and needs scheduling with whoever owns the UCMs.

Also confirm the tunnel actually carries the voice VLAN — JED, RYD and WH-JED have
had traffic selectors that omitted it. Check the Tunnel Watchdog page first.

## 2. Build pjsua

There is no apt package. Build it once on the NOC host:

```bash
sudo apt install build-essential libasound2-dev uuid-dev pkg-config
curl -LO https://github.com/pjsip/pjproject/archive/refs/tags/2.14.1.tar.gz
tar xf 2.14.1.tar.gz && cd pjproject-2.14.1
./configure --disable-video --disable-sound --disable-opencore-amr
make dep && make
sudo install -m0755 pjsip-apps/bin/pjsua-* /usr/local/bin/pjsua
pjsua --version
```

It must be installed **system-wide**, not into a user's `~/.local/bin` — the
service may run as a locked-down account. `deployment/voice-mesh/install.sh` does
all of this for you.

## 3. Per branch: a probe extension and a test IVR

Repeat on each branch's own UCM admin.

**Probe extension** — a dedicated extension with its own password, no voicemail,
no forwarding. It must **not** be a real desk phone: the prober REGISTERs as it
every 30 minutes and would steal that phone's registration.

**Test IVR** — this is what other branches dial, and it has to answer
deterministically or the duration comparison means nothing:

1. **PBX → Basic/Call Features → System Recordings** (or **Custom Prompts**) →
   upload `deployment/voice-mesh/reference.wav`. Every branch must use **the exact
   same file** — that is what the recorded durations are judged against.
2. **PBX → Call Features → IVR → Create New IVR**:
   - Extension: e.g. `7071` — this goes in the branch's `IVR extension` field in
     the NOC.
   - Prompt: the file from step 1.
   - Key Pressing Events: all unset (no menu).
   - Event on No Digits: **Hang Up** — so the call ends by itself the moment the
     prompt finishes. This is what makes the duration deterministic.
   - Repeat times if no digits: **1** (no looping).
3. Dial it from a phone on that UCM. You should hear the prompt once and the call
   should drop on its own.

## 4. Configure the branches in the NOC

```bash
php artisan migrate
php artisan db:seed --class=VoiceMeshNodeSeeder   # optional: CAI/JED/RYD/KBR skeletons
```

Then **Admin → Network → Voice Mesh** → *Add branch* for each site:

| Field | Meaning |
|---|---|
| Code | Must match what the prober reports — `CAI`, `JED`, … |
| UCM address | That branch's UCM as reached over the tunnel, e.g. `10.1.8.10` |
| Probe extension + password | From section 3 — what we register as |
| IVR extension | From section 3 — what others dial |

Finally, **Rotate ingest secret** and copy the value.

## 5. Install the prober

```bash
sudo bash deployment/voice-mesh/install.sh
```

Then put the secret into `NOC_SECRET` in `deployment/voice-mesh/config.conf` and:

```bash
sudo python3 deployment/voice-mesh/deploy.py
```

`deploy.py` refuses to install unless it can actually fetch a valid config with
that secret, so a wrong value fails here rather than as a quietly stale board an
hour later.

Check the wiring without dialling anything:

```bash
python3 deployment/voice-mesh/selftest.py
sudo -u "$(stat -c %U deployment/voice-mesh)" python3 -m voice_mesh.cli show-config
```

`show-config` prints the branch list the NOC handed back, passwords redacted.

## 6. First sweep

```bash
cd deployment/voice-mesh && sudo -u "$(stat -c %U .)" python3 -m voice_mesh.cli verify --force
journalctl -u voice-mesh-verify -f
```

`--force` bypasses the interval gate. Each leg logs its own line. For any failure,
the per-call pjsua log is the real diagnostic:

```bash
ls /var/lib/voice-mesh/last-verify-*.log
```

Then confirm the timer:

```bash
systemctl list-timers voice-mesh-verify.timer
```

The timer wakes every minute; the prober itself only sweeps when the interval
configured in the NOC has elapsed. That is deliberate — it is what lets you change
the interval from the admin UI without redeploying anything here, and it is what
makes **Run a sweep now** on the board start within about a minute.

## Running a sweep on demand

The prober is a systemd unit, so the web user cannot start it and a full mesh
takes minutes — a synchronous "check now" button was never possible. Instead:

- **Run a sweep now** (board header) records a request. The prober collects it on
  its next wake and runs in place of waiting out its interval.
- **Retry this leg** (on a pair's page) does the same for one leg only — a single
  call rather than N*(N-1), for confirming a fix in seconds. It deliberately does
  not reset the full-sweep clock.

Both show as pending on the board until the resulting report clears them, and
expire on their own after `VOICE_MESH_SWEEP_TTL` minutes (default 15) so a
request made while the prober was down doesn't fire when it returns.

---

## Troubleshooting

Keyed on the exact `reason` strings the prober reports.

| Reason | What it means |
|---|---|
| `call setup failed: pjsua not found…` | pjsua isn't installed system-wide (section 2). |
| `call never reached CONFIRMED (signalling failure)` | The INVITE never got a call. Registration was refused (section 1), the IVR extension doesn't exist, or inbound routing from the trunks doesn't reach it. |
| `no RTP media received` | The call connected but no RTP arrived at all — one-way media, or the tunnel policy is dropping the RTP range while permitting SIP. |
| `N RTP packets arrived but no audible audio` | Packets came back but carried nothing. A codec both ends nominally agreed on but neither produced, or an IVR that answered and played silence. Distinct from the row above: the media path works, the audio doesn't. |
| `prompt ran Xs vs reference Ys … IVR may not be hanging up on its own` | The audio matched but ran long — check *Event on No Digits: Hang Up* and *Repeat: 1* on that branch's IVR. If it ran to the hangup ceiling exactly, the IVR is looping or waiting for DTMF. |
| `prompt ran Xs vs reference Ys … prompt may be truncated or the wrong file` | Cut short. Usually a shorter prompt was uploaded to that IVR. |
| `audio does not match the reference prompt (pitch match N%) — right length, wrong audio` | Something of roughly the right duration answered, but it isn't the prompt: ringback, hold music, an announcement, or a different recording. Duration-only comparison used to score these OK. |
| Whole row red | That branch can't place calls — almost always its UCM refusing our registration. |
| Whole column red | That branch's IVR is unreachable from branches that are otherwise fine. |
| Board goes stale, no new runs | The timer or the secret. `systemctl status voice-mesh-verify.timer`, then check `NOC_SECRET` still matches the one in Settings. The NOC raises `voice_mesh_stale` for exactly this. |

**Durations tracking the hangup ceiling instead of the reference** means the
IVR-hangup detection isn't firing — the call is running to `VOICE_MESH_DURATION`
and being cut off by us. Check the per-call `.log` for what pjsua printed around
hangup.

**How a leg is judged.** Four things must hold: the call reached CONFIRMED, RTP
came back, the recording holds audible signal of the right length, and that
signal's pitch contour matches the reference's. The audio checks are deliberate
— an earlier version compared length alone, which meant ringback or hold music
of roughly the right duration scored OK, and which measured silence and noise as
though they were content. `TOLERANCE_PCT` governs the length comparison only;
the content match is a fixed threshold in `voice_mesh/audio.py`.

## Operating notes

- **Capacity is quadratic.** N branches is N×(N−1) legs, run sequentially on one
  local port: 4 → 12 legs (~4 min), 7 → 42 (~14 min), 10 → 90 (~30 min). The
  interval must exceed the sweep time; `deploy.py` warns when it doesn't.
- **These are real calls into production PBXs** — 42 every 30 minutes is ~2,000
  CDR entries a day. Worth mentioning to whoever reads billing reports.
- **Replacing the reference prompt** is a three-step change: replace
  `deployment/voice-mesh/reference.wav`, re-upload it to **every** branch's IVR,
  and set `VOICE_MESH_REFERENCE_SHA256` in the NOC `.env` to the new checksum.
  Miss the third and the prober refuses to dial; miss the second and every leg to
  the stale branch fails on duration.
- **Anyone with root on the NOC host can read every branch's SIP password** — the
  config endpoint hands them out in plaintext. They are encrypted at rest and
  audited, but that is the honest boundary.
- **Retention** is 30 days of per-leg history, enforced by
  `voice-mesh:check-stale --prune` daily at 03:25 (offset from the tunnel prune
  at 03:20). Change the window in Settings (`voice_mesh_retention_days`) or with
  `--retain=N`.

## Tunables

`config/voice_mesh.php`, all env-overridable:

| Env | Default | |
|---|---|---|
| `VOICE_MESH_INTERVAL` | 30 | Minutes between sweeps |
| `VOICE_MESH_DURATION` | 10 | Force-hangup ceiling per call, seconds |
| `VOICE_MESH_TOLERANCE` | 10 | Allowed duration drift, % |
| `VOICE_MESH_STALE_MINUTES` | 75 | No report for this long → alert |
| `VOICE_MESH_FLAP_WINDOW` | 180 | Re-open a recently-resolved event rather than duplicating |
| `VOICE_MESH_SECRET` | — | Fallback if the Settings value is unset |

## Alerts

Four event types, routable at **Admin → Notifications**:

| Type | Severity | Fires when |
|---|---|---|
| `voice_mesh_caller_down` | critical | Every outbound leg from a branch failed |
| `voice_mesh_dest_down` | critical | Every inbound leg to a branch failed, from callers that are themselves healthy |
| `voice_mesh_pair_failed` | warning | One leg failed twice running while both ends are otherwise fine |
| `voice_mesh_stale` | warning | The prober stopped reporting |

Leg alerts are **suppressed** whenever a node-level event already explains them,
so one dead UCM produces one alert rather than twelve.
