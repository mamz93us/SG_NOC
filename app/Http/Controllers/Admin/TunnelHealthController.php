<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\VpnTunnel;
use Illuminate\Support\Facades\Artisan;

/**
 * Read-only "is each branch reachable" view. Surfaces the ICMP ping that
 * vpn:ping-tunnels already records on every tunnel row each minute. Because
 * it's a plain ping to the branch firewall (10.x.0.1), it reports the truth
 * regardless of how the tunnel is carried — on-VM strongSwan OR the Azure VPN
 * gateway. No strongSwan/swanctl calls here, so it stays green even when the
 * VM isn't the IPsec terminator.
 */
class TunnelHealthController extends Controller
{
    public function index()
    {
        return view('admin.network.tunnel-health', [
            'rows' => $this->snapshot(),
        ]);
    }

    /** JSON snapshot for the page's auto-refresh poll. Read-only, cheap. */
    public function data()
    {
        return response()->json([
            'rows' => $this->snapshot(),
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Ping every branch right now, then return the fresh snapshot. Reuses the
     * scheduled command so the logic (and the vpn_logs history) lives in one
     * place. Synchronous — roughly 2s per unreachable branch, so ~15-20s worst
     * case for all sites, well within the request timeout.
     */
    public function pingNow()
    {
        Artisan::call('vpn:ping-tunnels');

        return response()->json([
            'rows' => $this->snapshot(),
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * One row per configured tunnel: branch, the firewall IP we ping, and the
     * latest reachability written by vpn:ping-tunnels.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function snapshot(): array
    {
        return VpnTunnel::with('branch')->orderBy('name')->get()->map(function (VpnTunnel $t) {
            return [
                'id' => $t->id,
                'name' => $t->name,
                'branch' => $t->branch?->name ?? '—',
                'target' => $t->pingTarget() ?? '—',
                'status' => $t->ping_status ?? 'unknown',
                'latency_ms' => $t->ping_latency_ms,
                'checked' => $t->last_ping_at?->diffForHumans(short: true),
            ];
        })->all();
    }
}
