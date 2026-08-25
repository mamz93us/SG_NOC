<?php

namespace App\Console\Commands;

use App\Models\AgwAllowlist;
use App\Models\AgwIpHistory;
use App\Models\BranchAgent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Reflects live branch WAN IPs (branch_agents.wan_ip, kept fresh by the
 * agent-push DDNS flow) into the Access-Gateway allowlist as source='dynamic'
 * rows, one per branch code. Manual entries are never touched. Each change is
 * recorded in agw_ip_history for troubleshooting.
 *
 * agw_allowlist.cidr is globally unique across manual and dynamic rows, so a
 * branch cannot simply claim whatever address it reports: another branch behind
 * the same ISP egress, or a manual entry someone already added, may hold it.
 * Every branch is therefore handled independently — one collision used to throw
 * and abort the run, which meant every branch after it silently stopped syncing.
 */
class SyncAgwAllowlist extends Command
{
    protected $signature = 'agw:sync-allowlist';

    protected $description = 'Sync branch WAN IPs into the Access Gateway IP allowlist';

    public function handle(): int
    {
        $changed = 0;
        $skipped = 0;
        $failed = 0;

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

            // One branch must never be able to stop the rest from syncing.
            try {
                $result = $this->syncBranch($agent->code, $agent->wan_ip, $cidr);
                $changed += $result['changed'];
                $skipped += $result['skipped'];
            } catch (\Throwable $e) {
                $failed++;
                $this->error("{$agent->code}: {$e->getMessage()}");
                Log::warning("agw:sync-allowlist failed for {$agent->code}: ".$e->getMessage(), [
                    'branch' => $agent->code,
                    'cidr' => $cidr,
                ]);
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

        $this->info(sprintf(
            'AGW allowlist sync complete: %d branch IPs, %d change(s), %d skipped, %d failed.',
            $agents->count(),
            $changed,
            $skipped,
            $failed
        ));

        // A collision is a normal, reportable state — the run still did its job.
        // Only a genuine error is worth failing the scheduled task over.
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Bring one branch's dynamic row in line with the WAN IP it reports.
     *
     * @return array{changed:int, skipped:int}
     */
    private function syncBranch(string $branch, string $wanIp, string $cidr): array
    {
        $row = AgwAllowlist::dynamic()->where('branch', $branch)->first();

        // Already pointing at the right address — just make sure it is enabled.
        if ($row && $row->cidr === $cidr) {
            if (! $row->active) {
                $row->update(['active' => true]);

                return ['changed' => 1, 'skipped' => 0];
            }

            return ['changed' => 0, 'skipped' => 0];
        }

        // Someone else already holds this address. Writing it anyway is what
        // threw the duplicate-key error that killed the whole sync.
        $holder = AgwAllowlist::where('cidr', $cidr)
            ->when($row, fn ($q) => $q->whereKeyNot($row->getKey()))
            ->first();

        if ($holder) {
            return [
                'changed' => $this->standDown($branch, $cidr, $row, $holder),
                'skipped' => 1,
            ];
        }

        if (! $row) {
            AgwAllowlist::create([
                'cidr' => $cidr,
                'branch' => $branch,
                'source' => 'dynamic',
                'active' => true,
                'note' => 'Auto-synced from branch WAN IP',
            ]);
            AgwIpHistory::create([
                'branch' => $branch,
                'old_ip' => null,
                'new_ip' => $wanIp,
            ]);

            return ['changed' => 1, 'skipped' => 0];
        }

        AgwIpHistory::create([
            'branch' => $branch,
            'old_ip' => explode('/', $row->cidr)[0],
            'new_ip' => $wanIp,
        ]);
        $row->update(['cidr' => $cidr, 'active' => true]);

        return ['changed' => 1, 'skipped' => 0];
    }

    /**
     * The address this branch reports is held by another row. Report it, and
     * retire this branch's own row if it is still allowing a previous address —
     * that address is no longer the branch's WAN IP and should stop being
     * permitted just because we could not move the row forward.
     *
     * @return int number of rows changed
     */
    private function standDown(string $branch, string $cidr, ?AgwAllowlist $row, AgwAllowlist $holder): int
    {
        if ($holder->source === 'manual') {
            // Benign and common: staff added the range by hand. The address is
            // permitted either way, so this is information, not a problem.
            $this->line("  {$branch}: {$cidr} already covered by a manual entry — leaving it alone.");
        } else {
            // Two branches reporting the same public address. Usually a shared
            // ISP egress, sometimes an agent reporting a stale or wrong IP.
            $this->warn("  {$branch}: {$cidr} is already held by branch '{$holder->branch}'. "
                .'Both branches appear to share one public address — check that is expected.');
            Log::info("agw:sync-allowlist: {$branch} and {$holder->branch} share {$cidr}");
        }

        if ($row && $row->active) {
            $row->update(['active' => false]);

            return 1;
        }

        return 0;
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
