<?php

namespace App\Services\BranchAgent;

use App\Models\ActivityLog;
use App\Models\BranchAgent;
use App\Models\BranchAgentWanIpHistory;
use App\Models\NocEvent;
use App\Services\Dns\GoDaddyService;
use App\Services\VpnControlService;
use Illuminate\Support\Facades\Log;

/**
 * Applies a branch agent's reported WAN-IP change: updates the GoDaddy A
 * record, re-points/refreshes the linked IPsec tunnel, records history and
 * audits the whole thing.
 *
 * Tunnel strategy (safe by design): we point the tunnel's remote endpoint at
 * the branch FQDN, not a literal IP. strongSwan then re-resolves the name on
 * reload, so future IP changes need only a DNS update — and we never touch the
 * child-SA traffic selectors (the thing that, per ops history, can hijack all
 * VPS egress if widened). We only ever change `remote_addrs`.
 */
class BranchDdnsService
{
    public function __construct(private readonly VpnControlService $vpn) {}

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
        // SAFETY: we deliberately do NOT regenerate the swanctl config here.
        // generateConfig() rebuilds the traffic selectors from the DB's
        // local_subnet/remote_subnet (and falls back to 0.0.0.0/0 if blank) —
        // a stale/blank record would break the tunnel (TS_UNACCEPTABLE) or
        // hijack VPS egress. Instead the operator points the tunnel's remote
        // endpoint at the branch FQDN ONCE; on a WAN-IP change we only refresh
        // DNS (above) and reload so strongSwan re-resolves the existing config.
        if ($agent->vpn_tunnel_id && $agent->vpnTunnel) {
            try {
                if (! $agent->fqdn()) {
                    // No DDNS name → the tunnel must use a literal IP, which a
                    // reload can't refresh. Leave it to a human rather than
                    // silently rewriting the config.
                    $errors[] = 'Tunnel: no FQDN configured; skipped (point the tunnel at the FQDN to enable auto-refresh).';
                } else {
                    $this->vpn->reload(); // re-resolve the FQDN-based remote_addrs
                    $appliedTunnel = true;
                }
            } catch (\Throwable $e) {
                $errors[] = 'Tunnel: '.$e->getMessage();
                Log::error('BranchDdns: tunnel reload failed', ['agent' => $agent->code, 'e' => $e->getMessage()]);
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
