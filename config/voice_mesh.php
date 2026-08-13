<?php

/*
|--------------------------------------------------------------------------
| Voice Mesh — synthetic branch-to-branch call testing
|--------------------------------------------------------------------------
|
| The prober itself is a Python service under systemd on this host (see
| deployment/voice-mesh/). It fetches these tunables, plus the branch list,
| from /api/voice-mesh/config at run time, so changing the interval here (or
| the branch list in the admin UI) takes effect without touching the box.
|
| The branch list is NOT here — it lives in the voice_mesh_nodes table and is
| managed at /admin/network/voice-mesh, because it carries SIP credentials.
|
*/

return [

    'runner_name' => env('VOICE_MESH_RUNNER_NAME', 'noc-voice-mesh'),

    // How often the prober runs a full sweep. The systemd timer fires more
    // often than this and the prober gates itself on the value it reads back
    // from the NOC — otherwise changing the interval here would do nothing
    // until someone re-ran deploy.py on the box.
    'interval_minutes' => (int) env('VOICE_MESH_INTERVAL', 30),

    // Force-hangup ceiling per call, in seconds. NOT the expected call length:
    // the IVR is meant to hang up on its own once its prompt finishes, and the
    // recorded duration should come from that. This is only the safety net for
    // a call that gets stuck.
    'duration' => (int) env('VOICE_MESH_DURATION', 10),

    // How far a recorded prompt's duration may drift from the reference before
    // the leg is failed. Loosen if the tunnels are jittery enough to cause
    // false positives.
    'tolerance_pct' => (float) env('VOICE_MESH_TOLERANCE', 10),

    'local_port' => (int) env('VOICE_MESH_LOCAL_PORT', 5080),

    // sha256 of the reference prompt wav. The prober refuses to dial if the
    // file on its disk doesn't match — see deployment/voice-mesh/reference.wav
    // and the "replacing the prompt" procedure in VOICE_MESH_SETUP.md.
    'reference_sha256' => env('VOICE_MESH_REFERENCE_SHA256', ''),

    // No report within this many minutes means the prober itself is in trouble
    // (timer stopped, pjsua missing, secret rejected) — 2.5x the interval.
    'stale_after_minutes' => (int) env('VOICE_MESH_STALE_MINUTES', 75),

    // A leg must fail this many consecutive sweeps before it alerts on its own.
    // Node-level failures alert on the first sweep: "every leg failed at once"
    // is not a fluke.
    'alert_after_failures' => 2,

    // Re-open a recently-resolved event rather than minting a new one. The
    // tunnel watchdog uses 60 minutes at a 1-minute cadence (60 cycles); the
    // honest translation at a 30-minute cadence is ~3 cycles.
    'flap_window_minutes' => (int) env('VOICE_MESH_FLAP_WINDOW', 180),

];
