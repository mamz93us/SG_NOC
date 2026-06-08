<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BranchAgent;
use App\Models\BranchLogCollector;
use App\Services\BranchAgent\BranchDdnsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Machine-to-machine endpoints the branch agent (sg-branch-agent) calls.
 *
 *   POST /api/branch-agents/enroll      one-time handshake → issues token
 *   POST /api/branch-agents/heartbeat   periodic health report (Bearer)
 *   GET  /api/branch-agents/config      runtime config the agent pulls (Bearer)
 *   POST /api/branch-agents/ddns        WAN-IP report (Bearer) — added in Phase 5
 *
 * Auth (except enroll) mirrors BranchConfigController::resolveBranch():
 * Bearer token compared via hash_equals against the encrypted api_token.
 * No session middleware → these routes are CSRF-exempt.
 */
class BranchAgentController extends Controller
{
    /**
     * POST /api/branch-agents/enroll
     * Body: { code: "<enrollment_code>", hostname, agent_version? }
     *
     * Validates the one-time enrollment code, issues a long-lived token,
     * and auto-provisions the matching branch_log_collectors row so the
     * NOC's log search can reach the agent immediately.
     */
    public function enroll(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32'],
            'hostname' => ['nullable', 'string', 'max:255'],
            'agent_version' => ['nullable', 'string', 'max:32'],
        ]);

        $agent = BranchAgent::where('enrollment_code', $data['code'])->first();

        if (! $agent || ! $agent->enrollmentPending()) {
            return response()->json([
                'ok' => false,
                'error' => 'invalid or expired enrollment code',
            ], 401);
        }

        $token = bin2hex(random_bytes(32));

        // Prefer the hostname the agent reports (its tunnel-side IP). Fall
        // back to whatever the operator pre-set.
        $hostname = $data['hostname'] ?: $agent->hostname;

        $agent->forceFill([
            'api_token' => $token,
            'hostname' => $hostname,
            'agent_version' => $data['agent_version'] ?? $agent->agent_version,
            'enrollment_code' => null,
            'enrollment_expires_at' => null,
            'status' => 'pending',
        ])->save();

        // Auto-provision / refresh the log-collector row (same code + token)
        // so /admin/logs/branches can query this agent with no extra setup.
        BranchLogCollector::updateOrCreate(
            ['code' => $agent->code],
            [
                'name' => $agent->name,
                'host' => $hostname ?: '0.0.0.0',
                'port' => $agent->port,
                'api_token' => $token,
                'enabled' => true,
            ]
        );

        return response()->json([
            'ok' => true,
            'token' => $token,
            'branch' => ['code' => $agent->code, 'name' => $agent->name],
            'config' => $this->runtimeConfig($agent),
        ]);
    }

    /**
     * POST /api/branch-agents/heartbeat
     * Body: { agent_version?, health: {disk_pct, ram_pct, log_rate, db_size_gb,
     *         dropped, devices_up, devices_down, ...} }
     */
    public function heartbeat(Request $request): JsonResponse
    {
        $agent = $this->resolveAgent($request);
        if (! $agent) {
            return response()->json(['ok' => false, 'error' => 'unauthorized'], 401);
        }

        $data = $request->validate([
            'agent_version' => ['nullable', 'string', 'max:32'],
            'health' => ['nullable', 'array'],
        ]);

        $agent->forceFill([
            'agent_version' => $data['agent_version'] ?? $agent->agent_version,
            'last_health' => $data['health'] ?? null,
            'last_heartbeat_at' => now(),
        ]);
        $agent->status = $agent->computeStatus();
        $agent->save();

        return response()->json([
            'ok' => true,
            'config' => $this->runtimeConfig($agent),
        ]);
    }

    /**
     * POST /api/branch-agents/ddns
     * Body: { wan_ip }
     *
     * The agent reports its current public WAN IP. On a change we update the
     * GoDaddy A record + the linked VPN tunnel and audit it (BranchDdnsService).
     * Unchanged IPs just refresh the timestamp — cheap dedup so the agent can
     * report freely.
     */
    public function ddns(Request $request, BranchDdnsService $ddns): JsonResponse
    {
        $agent = $this->resolveAgent($request);
        if (! $agent) {
            return response()->json(['ok' => false, 'error' => 'unauthorized'], 401);
        }

        $data = $request->validate([
            'wan_ip' => ['required', 'ip'],
        ]);
        $newIp = $data['wan_ip'];

        if ($agent->wan_ip === $newIp) {
            $agent->forceFill(['wan_ip_updated_at' => now()])->save();

            return response()->json(['ok' => true, 'changed' => false]);
        }

        $result = $ddns->apply($agent, $newIp);

        return response()->json(['ok' => true, 'changed' => true] + $result);
    }

    /**
     * GET /api/branch-agents/config
     * The agent polls this for its runtime config (intervals, retention).
     */
    public function config(Request $request): JsonResponse
    {
        $agent = $this->resolveAgent($request);
        if (! $agent) {
            return response()->json(['ok' => false, 'error' => 'unauthorized'], 401);
        }

        return response()->json([
            'ok' => true,
            'config' => $this->runtimeConfig($agent),
        ]);
    }

    /**
     * Runtime config payload the agent merges over its local settings.
     */
    private function runtimeConfig(BranchAgent $agent): array
    {
        return array_merge(
            config('branch_agents.defaults', []),
            [
                'branch_code' => $agent->code,
                'fqdn' => $agent->fqdn(),
                'ddns_enabled' => $agent->dns_subdomain !== null && $agent->dns_domain !== null,
            ]
        );
    }

    /**
     * Match the request's Bearer token against branch_agents. O(N) over
     * enrolled agents — fine for the branch count. Mirrors
     * BranchConfigController::resolveBranch().
     */
    private function resolveAgent(Request $request): ?BranchAgent
    {
        $bearer = $request->bearerToken();
        if (! $bearer) {
            return null;
        }

        foreach (BranchAgent::ready()->get() as $agent) {
            if (hash_equals((string) $agent->api_token, $bearer)) {
                return $agent;
            }
        }

        return null;
    }
}
