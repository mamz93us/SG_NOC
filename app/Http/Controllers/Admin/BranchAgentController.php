<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\BranchAgent;
use App\Models\DnsAccount;
use App\Models\VpnTunnel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin CRUD + enrollment for branch agents (sg-branch-agent VMs).
 *
 * Workflow: operator adds a branch here → clicks "Generate enrollment code"
 * → runs the one-line installer on the VM → pastes the code into the agent's
 * setup wizard. The agent enrolls (Api\BranchAgentController::enroll), gets a
 * token, and starts sending heartbeats — which turn the row green here.
 */
class BranchAgentController extends Controller
{
    public function index(): View
    {
        $agents = BranchAgent::orderBy('code')->get();

        return view('admin.branch-agents.index', compact('agents'));
    }

    public function create(): View
    {
        return view('admin.branch-agents.form', [
            'agent' => new BranchAgent([
                'port' => 8080,
                'enabled' => true,
                'dns_domain' => config('branch_agents.dns_domain'),
            ]),
            'dnsAccounts' => DnsAccount::orderBy('label')->get(),
            'tunnels' => VpnTunnel::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, null);
        $agent = BranchAgent::create($data + ['status' => 'pending']);

        $this->issueEnrollmentCode($agent);

        ActivityLog::log('BRANCH_AGENT', "Created branch agent '{$agent->code}' and issued enrollment code", 'success', $agent->id);

        return redirect()
            ->route('admin.branch-agents.show', $agent)
            ->with('success', "Branch agent '{$agent->code}' created. Use the enrollment code below to link the VM.");
    }

    public function show(BranchAgent $branchAgent): View
    {
        $branchAgent->load(['wanIpHistory', 'vpnTunnel', 'dnsAccount']);

        return view('admin.branch-agents.show', [
            'agent' => $branchAgent,
            'history' => $branchAgent->wanIpHistory()->limit(50)->get(),
        ]);
    }

    public function edit(BranchAgent $branchAgent): View
    {
        return view('admin.branch-agents.form', [
            'agent' => $branchAgent,
            'dnsAccounts' => DnsAccount::orderBy('label')->get(),
            'tunnels' => VpnTunnel::orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, BranchAgent $branchAgent): RedirectResponse
    {
        $data = $this->validated($request, $branchAgent);
        $branchAgent->update($data);

        ActivityLog::log('BRANCH_AGENT', "Updated branch agent '{$branchAgent->code}'", 'info', $branchAgent->id);

        return redirect()
            ->route('admin.branch-agents.show', $branchAgent)
            ->with('success', "Branch agent '{$branchAgent->code}' updated.");
    }

    public function destroy(BranchAgent $branchAgent): RedirectResponse
    {
        $code = $branchAgent->code;
        $branchAgent->delete();

        ActivityLog::log('BRANCH_AGENT', "Deleted branch agent '{$code}'", 'danger');

        return redirect()
            ->route('admin.branch-agents.index')
            ->with('success', "Branch agent '{$code}' removed. (The VM itself is untouched.)");
    }

    /**
     * (Re)issue a one-time enrollment code. Invalidates any existing token,
     * forcing the agent to re-link — use when re-provisioning a VM.
     */
    public function regenerateCode(BranchAgent $branchAgent): RedirectResponse
    {
        $this->issueEnrollmentCode($branchAgent);

        ActivityLog::log('BRANCH_AGENT', "Issued new enrollment code for '{$branchAgent->code}'", 'warning', $branchAgent->id);

        return redirect()
            ->route('admin.branch-agents.show', $branchAgent)
            ->with('success', 'New enrollment code generated. The previous code (if any) no longer works.');
    }

    /**
     * Revoke the issued token. The agent immediately loses NOC access and the
     * NOC can no longer query its logs until it re-enrolls.
     */
    public function revokeToken(BranchAgent $branchAgent): RedirectResponse
    {
        $branchAgent->forceFill([
            'api_token' => null,
            'status' => 'pending',
        ])->save();

        // Also clear the linked log-collector token so log search fails closed.
        if ($collector = $branchAgent->logCollector()) {
            $collector->forceFill(['api_token' => null])->save();
        }

        ActivityLog::log('BRANCH_AGENT', "Revoked token for branch agent '{$branchAgent->code}'", 'danger', $branchAgent->id);

        return redirect()
            ->route('admin.branch-agents.show', $branchAgent)
            ->with('success', "Token revoked for '{$branchAgent->code}'. Generate an enrollment code to re-link.");
    }

    // ─── Helpers ─────────────────────────────────────────────────────

    private function issueEnrollmentCode(BranchAgent $agent): void
    {
        $ttl = (int) config('branch_agents.enrollment_ttl_minutes', 60);

        $agent->forceFill([
            'enrollment_code' => strtoupper(bin2hex(random_bytes(4))), // 8 hex chars
            'enrollment_expires_at' => now()->addMinutes($ttl),
        ])->save();
    }

    private function validated(Request $request, ?BranchAgent $existing): array
    {
        return $request->validate([
            'code' => [
                'required', 'string', 'lowercase', 'min:2', 'max:8',
                'regex:/^[a-z][a-z0-9]+$/',
                Rule::unique('branch_agents', 'code')->ignore($existing?->id),
            ],
            'name' => ['required', 'string', 'max:100'],
            'hostname' => ['nullable', 'string', 'max:255'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'enabled' => ['nullable', 'boolean'],
            'dns_domain' => ['nullable', 'string', 'max:255'],
            'dns_subdomain' => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9-]+$/i'],
            'dns_account_id' => ['nullable', 'integer', 'exists:dns_accounts,id'],
            'vpn_tunnel_id' => ['nullable', 'integer', 'exists:vpn_tunnels,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [
            'code.regex' => 'Branch code must start with a letter and contain only lowercase letters and digits.',
        ]) + ['enabled' => $request->boolean('enabled')];
    }
}
