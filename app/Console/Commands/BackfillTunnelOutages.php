<?php

namespace App\Console\Commands;

use App\Models\BranchTunnel;
use App\Models\TunnelHealthCheck;
use App\Models\TunnelOutage;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Reconstructs incident rows from the per-cycle check history.
 *
 * The outage log starts empty the day it ships, but tunnel_health_checks
 * already holds the last week at one row per tunnel per minute — enough to
 * rebuild every incident in that window so the ISP report is useful
 * immediately instead of a week from now.
 *
 * Idempotent: only rows this command wrote (source = backfill) are cleared and
 * rebuilt, and a reconstructed incident is skipped if a live watchdog row
 * already covers the same stretch. Safe to re-run.
 */
class BackfillTunnelOutages extends Command
{
    protected $signature = 'tunnel-health:backfill-outages
                            {--days=7 : How far back to reconstruct}
                            {--tunnel= : Limit to one tunnel ID}
                            {--dry-run : Report what would be written without writing it}';

    protected $description = 'Rebuild tunnel outage incidents from the watchdog check history';

    /**
     * A pause longer than this between consecutive checks means the watchdog
     * was not running. An incident is cut at the gap rather than assumed to
     * have continued across it — we do not know, and guessing high is the one
     * mistake an ISP report must not make.
     */
    private const GAP_MINUTES = 5;

    /** The watchdog cadence — used to end an incident that runs to the last known check. */
    private const CYCLE_MINUTES = 1;

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $since = now()->subDays($days);
        $dryRun = (bool) $this->option('dry-run');

        $tunnels = BranchTunnel::query()
            ->when($this->option('tunnel'), fn ($q) => $q->where('id', (int) $this->option('tunnel')))
            ->ordered()
            ->get();

        if ($tunnels->isEmpty()) {
            $this->warn('No tunnels found.');

            return self::SUCCESS;
        }

        $written = 0;
        $skipped = 0;

        foreach ($tunnels as $tunnel) {
            $incidents = $this->reconstruct($tunnel, $since);

            if (! $dryRun) {
                TunnelOutage::where('branch_tunnel_id', $tunnel->id)
                    ->where('source', 'backfill')
                    ->where('started_at', '>=', $since)
                    ->delete();
            }

            $tunnelWritten = 0;

            foreach ($incidents as $incident) {
                if ($this->coveredByWatchdog($tunnel, $incident)) {
                    $skipped++;

                    continue;
                }

                if (! $dryRun) {
                    TunnelOutage::create($incident + [
                        'branch_tunnel_id' => $tunnel->id,
                        'source' => 'backfill',
                    ]);
                }

                $tunnelWritten++;
            }

            $written += $tunnelWritten;

            $this->line(sprintf('  %-14s %d incident(s) reconstructed', $tunnel->name, $tunnelWritten));
        }

        $this->info(sprintf(
            '%s %d incident(s) from the last %d day(s)%s.',
            $dryRun ? 'Would write' : 'Wrote',
            $written,
            $days,
            $skipped > 0 ? ", skipped {$skipped} already recorded live" : ''
        ));

        return self::SUCCESS;
    }

    /**
     * Walk one tunnel's checks in time order and cut them into incidents.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function reconstruct(BranchTunnel $tunnel, Carbon $since): array
    {
        $incidents = [];
        $current = null;
        $previous = null;   // the previous row's checked_at, for gap detection

        $checks = TunnelHealthCheck::where('branch_tunnel_id', $tunnel->id)
            ->where('checked_at', '>=', $since)
            ->orderBy('checked_at')
            ->cursor();

        foreach ($checks as $check) {
            $at = $check->checked_at;

            // A gap means we stopped observing. Close whatever was open at the
            // last check we actually have, not at the far side of the gap.
            if ($current && $previous && $previous->diffInMinutes($at) > self::GAP_MINUTES) {
                $incidents[] = $this->close($current, $previous->copy()->addMinutes(self::CYCLE_MINUTES));
                $current = null;
            }

            $bad = in_array($check->state, [TunnelOutage::STATE_DOWN, TunnelOutage::STATE_DEGRADED], true);

            if (! $bad) {
                if ($current) {
                    $incidents[] = $this->close($current, $at);
                    $current = null;
                }
                $previous = $at;

                continue;
            }

            // Down to degraded (or back) is a new incident, same as live.
            if ($current && $current['state'] !== $check->state) {
                $incidents[] = $this->close($current, $at);
                $current = null;
            }

            if (! $current) {
                $current = [
                    'state' => $check->state,
                    'started_at' => $at->copy(),
                    'checks' => 0,
                    'probes_down' => 0,
                ];
            }

            $current['checks']++;
            $current['probes_down'] = max(
                $current['probes_down'],
                max(0, $check->probes_total - $check->probes_up)
            );
            $current['last_at'] = $at->copy();

            $previous = $at;
        }

        // An incident still running at the end of the history is closed at the
        // last check rather than left open — the live watchdog owns open rows,
        // and two open incidents for one tunnel would double-count.
        if ($current) {
            $incidents[] = $this->close($current, $current['last_at']->copy()->addMinutes(self::CYCLE_MINUTES));
        }

        return $incidents;
    }

    /** @param  array<string, mixed>  $current */
    protected function close(array $current, Carbon $endedAt): array
    {
        $started = $current['started_at'];
        $probesDown = $current['probes_down'];

        return [
            'state' => $current['state'],
            'started_at' => $started,
            'ended_at' => $endedAt,
            'duration_seconds' => max(0, $started->diffInSeconds($endedAt)),
            'checks' => $current['checks'],
            'probes_down' => $probesDown,
            'reason' => $current['state'] === TunnelOutage::STATE_DOWN
                ? 'Gateway did not answer ICMP. (Reconstructed from check history.)'
                : $probesDown.' carried '.($probesDown === 1 ? 'subnet was' : 'subnets were')
                    .' unreachable through the tunnel. (Reconstructed from check history — '
                    .'per-probe detail is not kept in the check log.)',
        ];
    }

    /** @param  array<string, mixed>  $incident */
    protected function coveredByWatchdog(BranchTunnel $tunnel, array $incident): bool
    {
        return TunnelOutage::where('branch_tunnel_id', $tunnel->id)
            ->where('source', 'watchdog')
            ->overlapping($incident['started_at'], $incident['ended_at'])
            ->exists();
    }
}
