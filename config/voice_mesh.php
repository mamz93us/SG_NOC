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

    // sha256 of the reference prompt wav. The prober refuses to dial if the file
    // on its disk doesn't match, so a prompt that has drifted from the one every
    // IVR plays fails loudly instead of failing every leg for a reason that has
    // nothing to do with the network.
    //
    // The default is the checksum of the committed deployment/voice-mesh/
    // reference.wav. Only override it if you replace the prompt — which means
    // re-uploading it to every branch's IVR too (see VOICE_MESH_SETUP.md).
    'reference_sha256' => env(
        'VOICE_MESH_REFERENCE_SHA256',
        'c4c8cda84ad5372bbf944749de56c82bc0923fcc0aab46b108a623242098f3b7'
    ),

    // No report within this many minutes means the prober itself is in trouble
    // (timer stopped, pjsua missing, secret rejected) — 2.5x the interval.
    'stale_after_minutes' => (int) env('VOICE_MESH_STALE_MINUTES', 75),

    // How long a "run a sweep now" request from the admin UI stays live. The
    // prober wakes every minute, so this only matters when it has been down —
    // a request from an hour ago should not fire the moment it comes back.
    'sweep_request_ttl_minutes' => (int) env('VOICE_MESH_SWEEP_TTL', 15),

    // A leg must fail this many consecutive sweeps before it alerts on its own.
    // Node-level failures alert on the first sweep: "every leg failed at once"
    // is not a fluke.
    'alert_after_failures' => 2,

    // Re-open a recently-resolved event rather than minting a new one. The
    // tunnel watchdog uses 60 minutes at a 1-minute cadence (60 cycles); the
    // honest translation at a 30-minute cadence is ~3 cycles.
    'flap_window_minutes' => (int) env('VOICE_MESH_FLAP_WINDOW', 180),

];
