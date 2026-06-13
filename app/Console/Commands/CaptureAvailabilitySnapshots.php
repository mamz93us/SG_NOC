<?php

namespace App\Console\Commands;

use App\Models\AccessPoint;
use App\Models\AvailabilitySnapshot;
use App\Models\MonitoredHost;
use App\Models\VpnTunnel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshots current up/down state of access points, VPN tunnels and monitored
 * hosts into availability_snapshots so the NOC overview can chart uptime over
 * time (these subsystems otherwise store only current state). Runs hourly.
 * Also prunes snapshots older than the retention window.
 */
class CaptureAvailabilitySnapshots extends Command
{
    protected $signature = 'noc:snapshot-availability {--retain=120 : Days of snapshot history to keep}';

    protected $description = 'Capture hourly up/down snapshots of APs, VPN tunnels and hosts for uptime charts';

    public function handle(): int
    {
        $now = now();
        $rows = [];

        // Access points — up when status = up
        if (Schema::hasTable('access_points')) {
            foreach (AccessPoint::query()->get(['id', 'branch_id', 'status', 'ping_latency_ms']) as $ap) {
                $rows[] = $this->row('access_point', $ap->id, $ap->branch_id, $ap->status === 'up', $ap->ping_latency_ms, $now);
            }
        }

        // VPN tunnels — prefer ping_status, fall back to status
        if (Schema::hasTable('vpn_tunnels')) {
            foreach (VpnTunnel::query()->get(['id', 'branch_id', 'status', 'ping_status', 'ping_latency_ms']) as $t) {
                $up = ($t->ping_status ?? $t->status) === 'up';
                $rows[] = $this->row('vpn_tunnel', $t->id, $t->branch_id, $up, $t->ping_latency_ms, $now);
            }
        }

        // Monitored hosts — up when status = up
        if (Schema::hasTable('monitored_hosts')) {
            foreach (MonitoredHost::query()->get(['id', 'branch_id', 'status']) as $h) {
                $rows[] = $this->row('monitored_host', $h->id, $h->branch_id, $h->status === 'up', null, $now);
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            AvailabilitySnapshot::insert($chunk);
        }

        $retain = max(7, (int) $this->option('retain'));
        $deleted = AvailabilitySnapshot::where('captured_at', '<', $now->copy()->subDays($retain))->delete();

        $this->info('Captured '.count($rows)." availability snapshots; pruned {$deleted} old rows.");

        return 0;
    }

    protected function row(string $type, int $id, ?int $branchId, bool $up, ?int $latency, $now): array
    {
        return [
            'entity_type' => $type,
            'entity_id' => $id,
            'branch_id' => $branchId,
            'up' => $up,
            'latency_ms' => $latency,
            'captured_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
