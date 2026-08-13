<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\VoiceMeshNode;
use App\Services\Voice\VoiceMeshMonitor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The two endpoints the voice mesh prober talks to. It runs as a systemd
 * service on this same host (deployment/voice-mesh/), so both routes are
 * localhost-only in practice.
 *
 * Auth is deliberately doubled — `internal.ip` middleware on the route AND a
 * shared secret checked here:
 *
 *   - internal.ip alone is not enough, because GET /config hands back every
 *     branch's plaintext SIP password. Without a secret, any local process
 *     (www-data, the Node telnet proxy, anyone who can curl localhost) could
 *     read them.
 *   - the secret alone is not enough either, because a leaked header value
 *     would then be usable from anywhere.
 *
 * Both are cheap and they fail in different directions. Empty configured
 * secret fails closed, same as the Graylog webhook.
 */
class VoiceMeshController extends Controller
{
    /**
     * The branch list and tunables, fetched by the prober at the start of every
     * sweep. This is what replaced the hand-edited BRANCHES list in its
     * config.conf, so an extension change is a UI edit rather than an SSH
     * session.
     */
    public function config(Request $request): JsonResponse
    {
        if (! $this->authorized($request)) {
            return $this->unauthorized();
        }

        $nodes = VoiceMeshNode::active()->ordered()->get();

        $payload = [
            'runner_name' => config('voice_mesh.runner_name'),
            'interval_minutes' => (int) config('voice_mesh.interval_minutes'),
            'duration' => (int) config('voice_mesh.duration'),
            'tolerance_pct' => (float) config('voice_mesh.tolerance_pct'),
            'local_port' => (int) config('voice_mesh.local_port'),
            'reference_sha256' => (string) config('voice_mesh.reference_sha256'),
            'branches' => $nodes->map->toProberEntry()->values(),
        ];

        // A mesh needs at least two participants. Answer 200 with an empty list
        // and a warning rather than an error: the prober should log and no-op,
        // not crash-loop its timer because someone deactivated a branch.
        if ($nodes->count() < 2) {
            $payload['branches'] = [];
            $payload['warning'] = 'Fewer than 2 active voice mesh nodes are configured — nothing to dial.';
        }

        return response()
            ->json($payload)
            ->header('Cache-Control', 'no-store')
            ->header('Pragma', 'no-cache');
    }

    /**
     * One sweep's combined health report — every (caller, dest) leg the prober
     * attempted, pass or fail.
     */
    public function report(Request $request, VoiceMeshMonitor $monitor): JsonResponse
    {
        if (! $this->authorized($request)) {
            return $this->unauthorized();
        }

        $data = $request->validate([
            'runner_name' => 'nullable|string|max:64',
            'probe_version' => 'nullable|string|max:32',
            'timestamp' => 'nullable|date',
            'ok' => 'required|boolean',
            // 400 legs is a 20-branch mesh — far beyond anything planned, but a
            // bound so a malformed post can't try to insert unbounded rows.
            'results' => 'required|array|min:1|max:400',
            'results.*.caller' => 'required|string|max:16',
            'results.*.dest' => 'required|string|max:16',
            'results.*.dest_ext' => 'nullable|string|max:16',
            'results.*.ok' => 'required|boolean',
            'results.*.rx_pkt' => 'nullable|integer|min:0|max:100000000',
            'results.*.duration_sec' => 'nullable|numeric|min:0|max:3600',
            'results.*.reference_duration_sec' => 'nullable|numeric|min:0|max:3600',
            'results.*.reason' => 'nullable|string|max:255',
        ]);

        $run = $monitor->ingest($data, $request->ip());

        if ($run->unknown_nodes) {
            Log::info('Voice mesh report named unknown branch codes', [
                'run_id' => $run->id,
                'codes' => $run->unknown_nodes,
            ]);
        }

        return response()->json([
            'ok' => true,
            'run_id' => $run->id,
            'pairs_recorded' => $run->pairs_total,
            'pairs_ok' => $run->pairs_ok,
            'unknown' => $run->unknown_nodes ?? [],
        ]);
    }

    private function authorized(Request $request): bool
    {
        $expected = $this->secret();

        return $expected !== ''
            && hash_equals($expected, (string) $request->header('X-Voice-Mesh-Secret', ''));
    }

    private function unauthorized(): JsonResponse
    {
        return response()->json(['ok' => false, 'error' => 'unauthorized'], 401);
    }

    /** Settings first so the secret is rotatable from the UI; env as fallback. */
    private function secret(): string
    {
        try {
            $fromSettings = (string) (Setting::get()->voice_mesh_secret ?? '');
        } catch (\Throwable) {
            $fromSettings = '';
        }

        return $fromSettings !== '' ? $fromSettings : (string) config('services.voice_mesh.secret', '');
    }
}
