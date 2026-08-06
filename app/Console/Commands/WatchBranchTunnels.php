<?php

namespace App\Console\Commands;

use App\Models\BranchTunnel;
use App\Services\Network\TunnelWatchdog;
use Illuminate\Console\Command;

/**
 * Runs one watchdog sweep across every active branch tunnel: gateway ping plus
 * every per-subnet probe, rolled up into up / degraded / down, with NocEvents
 * raised and resolved on transitions.
 *
 * Scheduled every minute; also invoked by the "Check now" button on
 * /admin/network/tunnel-health. Replaces the old gateway-only tunnel-health:ping.
 */
class WatchBranchTunnels extends Command
{
    protected $signature = 'tunnel-health:watch
                            {--id= : Check a single tunnel by ID}
                            {--prune : Drop history past the retention window instead of probing}
                            {--retain=7 : Days of check history to keep when pruning}';

    protected $description = 'Probe each branch tunnel gateway and its carried subnets; alert on down/degraded';

    public function handle(TunnelWatchdog $watchdog): int
    {
        if ($this->option('prune')) {
            $deleted = $watchdog->prune((int) $this->option('retain'));
            $this->info("Pruned {$deleted} tunnel health checks.");

            return self::SUCCESS;
        }

        $id = $this->option('id') !== null ? (int) $this->option('id') : null;
        $tunnels = $watchdog->run($id);

        if ($tunnels->isEmpty()) {
            $this->warn('No active branch tunnels configured.');

            return self::SUCCESS;
        }

        $counts = ['up' => 0, 'degraded' => 0, 'down' => 0, 'unknown' => 0];

        foreach ($tunnels as $tunnel) {
            $counts[$tunnel->state] = ($counts[$tunnel->state] ?? 0) + 1;

            $this->line(sprintf(
                '  %-12s %-15s %-9s %s',
                $tunnel->name,
                $tunnel->firewall_ip,
                strtoupper($tunnel->state),
                $tunnel->ping_latency_ms !== null ? $tunnel->ping_latency_ms.' ms' : ''
            ));

            // Name the failing probes — this is the line that would have caught JED.
            foreach ($tunnel->activeProbes as $probe) {
                if ($probe->status !== 'up') {
                    $this->line(sprintf(
                        '      <fg=red>✗ %s</> %s',
                        $probe->label,
                        $probe->targetLabel()
                    ));
                }
            }
        }

        $this->info(sprintf(
            'Checked %d tunnels — %d up, %d degraded, %d down.',
            $tunnels->count(),
            $counts[BranchTunnel::STATE_UP],
            $counts[BranchTunnel::STATE_DEGRADED],
            $counts[BranchTunnel::STATE_DOWN]
        ));

        return self::SUCCESS;
    }
}
