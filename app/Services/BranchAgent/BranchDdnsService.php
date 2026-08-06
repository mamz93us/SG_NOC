<?php

namespace App\Services\BranchAgent;

use App\Models\ActivityLog;
use App\Models\BranchAgent;
use App\Models\BranchAgentWanIpHistory;
use App\Models\NocEvent;
use App\Services\Dns\GoDaddyService;
use Illuminate\Support\Facades\Log;

/**
 * Applies a branch agent's reported WAN-IP change: updates the GoDaddy A
 * record, records history and audits the whole thing.
 *
 * Tunnel strategy: the tunnel's remote endpoint is pointed at the branch FQDN,
 * not a literal IP, so a WAN-IP change needs only the DNS update below — the
 * gateway re-resolves the name itself. The NOC no longer runs strongSwan (the
 * tunnels moved to the Azure VPN gateway), so there is nothing local to reload;
 * a tunnel pinned to a literal IP needs a human on the Azure side. The Branch
 * Tunnel Watchdog is what tells you if that hasn't happened.
 */
class BranchDdnsService
{
    /**
     * Apply a changed WAN IP. Returns a result array with per-step outcomes.
     */
    public function apply(BranchAgent $agent, string $newIp): array
    {
        $old = $agent->wan_ip;

        $agent->forceFill([
            'wan_ip' => $newIp,
            'wan_ip_updated_at' => now(),
        ])->save();

        $appliedDns = false;
        $appliedTunnel = false;
        $errors = [];

        // ── DNS (GoDaddy A record) ──────────────────────────────────
        if ($agent->dns_account_id && $agent->dns_subdomain && $agent->dns_domain && $agent->dnsAccount) {
            try {
                (new GoDaddyService($agent->dnsAccount))->replaceRecordsByTypeAndName(
                    $agent->dns_domain,
                    'A',
                    $agent->dns_subdomain,
                    [[
                        'type' => 'A',
                        'data' => $newIp,
                        'ttl' => (int) config('branch_agents.dns_record_ttl', 600),
                    ]],
                );
                $appliedDns = true;
            } catch (\Throwable $e) {
                $errors[] = 'DNS: '.$e->getMessage();
                Log::error('BranchDdns: GoDaddy update failed', ['agent' => $agent->code, 'e' => $e->getMessage()]);
            }
        }

        // ── VPN tunnel ──────────────────────────────────────────────
        // Nothing to do locally: the tunnels live on the Azure VPN gateway, which
        // re-resolves an FQDN remote endpoint on its own. A tunnel pinned to a
        // literal IP cannot follow a WAN-IP change without a human, so say so
        // rather than pretending the tunnel side was handled.
        if ($agent->vpn_tunnel_id && $agent->vpnTunnel) {
            if ($agent->fqdn()) {
                $appliedTunnel = true;
            } else {
                $errors[] = 'Tunnel: no FQDN configured; the Azure gateway cannot follow the new IP '
                    .'(point the tunnel at the FQDN, or update the remote endpoint by hand).';
            }
        }

        // ── History + audit ─────────────────────────────────────────
        BranchAgentWanIpHistory::create([
            'branch_agent_id' => $agent->id,
            'ip' => $newIp,
            'previous_ip' => $old,
            'applied_dns' => $appliedDns,
            'applied_tunnel' => $appliedTunnel,
            'note' => $errors ? implode('; ', $errors) : null,
            'changed_at' => now(),
        ]);

        ActivityLog::log(
            'BRANCH_AGENT',
            sprintf(
                'DDNS %s: %s→%s (dns=%s, tunnel=%s)',
                $agent->code,
                $old ?: '∅',
                $newIp,
                $appliedDns ? 'ok' : '-',
                $appliedTunnel ? 'ok' : '-',
            ),
            $errors ? 'warning' : 'info',
            $agent->id,
        );

        if ($errors) {
            $this->raiseEvent($agent, $newIp, $errors);
        }

        return [
            'applied_dns' => $appliedDns,
            'applied_tunnel' => $appliedTunnel,
            'errors' => $errors,
        ];
    }

    private function raiseEvent(BranchAgent $agent, string $newIp, array $errors): void
    {
        try {
            NocEvent::create([
                'module' => 'branch_agent',
                'entity_type' => 'branch_agent_ddns',
                'entity_id' => (string) $agent->id,
                'source_type' => 'branch_agent',
                'source_id' => $agent->id,
                'severity' => 'warning',
                'title' => "DDNS apply issues for {$agent->code} ({$newIp})",
                'message' => substr(implode('; ', $errors), 0, 1000),
                'first_seen' => now(),
                'last_seen' => now(),
                'status' => 'open',
            ]);
        } catch (\Throwable $e) {
            Log::warning('BranchDdns: could not raise NocEvent', ['e' => $e->getMessage()]);
        }
    }
}
