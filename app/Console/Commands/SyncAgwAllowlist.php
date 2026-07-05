<?php

namespace App\Console\Commands;

use App\Models\AgwAllowlist;
use App\Models\AgwIpHistory;
use App\Models\BranchAgent;
use Illuminate\Console\Command;

/**
 * Reflects live branch WAN IPs (branch_agents.wan_ip, kept fresh by the
 * agent-push DDNS flow) into the Access-Gateway allowlist as source='dynamic'
 * rows, one per branch code. Manual entries are never touched. Each change is
 * recorded in agw_ip_history for troubleshooting.
 */
class SyncAgwAllowlist extends Command
{
    protected $signature = 'agw:sync-allowlist';

    protected $description = 'Sync branch WAN IPs into the Access Gateway IP allowlist';

    public function handle(): int
    {
        $changed = 0;

        $agents = BranchAgent::ready()
            ->whereNotNull('wan_ip')
            ->get(['code', 'wan_ip']);

        $seenBranches = [];

        foreach ($agents as $agent) {
            $cidr = $this->toCidr($agent->wan_ip);
            if ($cidr === null) {
                continue;
            }
            $seenBranches[] = $agent->code;

            $row = AgwAllowlist::dynamic()->where('branch', $agent->code)->first();

            if (! $row) {
                AgwAllowlist::create([
                    'cidr' => $cidr,
                    'branch' => $agent->code,
                    'source' => 'dynamic',
                    'active' => true,
                    'note' => 'Auto-synced from branch WAN IP',
                ]);
                AgwIpHistory::create([
                    'branch' => $agent->code,
                    'old_ip' => null,
                    'new_ip' => $agent->wan_ip,
                ]);
                $changed++;

                continue;
            }

            if ($row->cidr !== $cidr) {
                $oldIp = explode('/', $row->cidr)[0];
                AgwIpHistory::create([
                    'branch' => $agent->code,
                    'old_ip' => $oldIp,
                    'new_ip' => $agent->wan_ip,
                ]);
                $row->update(['cidr' => $cidr, 'active' => true]);
                $changed++;
            } elseif (! $row->active) {
                $row->update(['active' => true]);
                $changed++;
            }
        }

        // Deactivate dynamic rows for branches no longer reporting a WAN IP,
        // so a decommissioned/disabled branch stops being allowed. History is
        // kept; the row is not deleted.
        $stale = AgwAllowlist::dynamic()
            ->where('active', true)
            ->when($seenBranches, fn ($q) => $q->whereNotIn('branch', $seenBranches))
            ->get();

        foreach ($stale as $row) {
            $row->update(['active' => false]);
            $changed++;
        }

        $this->info("AGW allowlist sync complete: {$agents->count()} branch IPs, {$changed} change(s).");

        return self::SUCCESS;
    }

    /** Append the host prefix (/32 or /128) to a bare WAN IP. */
    private function toCidr(string $ip): ?string
    {
        $ip = trim($ip);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $ip.'/32';
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return strtolower($ip).'/128';
        }

        return null;
    }
}
