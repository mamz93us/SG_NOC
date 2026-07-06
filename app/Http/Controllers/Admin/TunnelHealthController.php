<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BranchTunnel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

/**
 * Branch Tunnel Health — a self-contained "is each branch reachable" board.
 * You add branches + their firewall IP here; a scheduled ICMP ping (and the
 * "Ping now" button) records up/down + latency. Independent of the VPN Hub /
 * strongSwan — tunnels are created on the Azure VPN gateway now.
 */
class TunnelHealthController extends Controller
{
    public function index()
    {
        return view('admin.network.tunnel-health', [
            'targets' => BranchTunnel::orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }

    /** JSON snapshot for the page's auto-refresh poll. Read-only, cheap. */
    public function data()
    {
        return response()->json(['rows' => $this->snapshot()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'firewall_ip' => 'required|ip',
        ]);

        BranchTunnel::create($data);

        return back()->with('success', "Added branch '{$data['name']}'.");
    }

    public function update(Request $request, BranchTunnel $tunnel)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'firewall_ip' => 'required|ip',
            'is_active' => 'sometimes|boolean',
        ]);

        // Reset stale ping state if the IP changed, so the next probe re-baselines.
        if (isset($data['firewall_ip']) && $data['firewall_ip'] !== $tunnel->firewall_ip) {
            $data['ping_status'] = 'unknown';
            $data['ping_latency_ms'] = null;
            $data['last_ping_at'] = null;
        }

        $tunnel->update($data);

        return back()->with('success', "Updated branch '{$tunnel->name}'.");
    }

    public function destroy(BranchTunnel $tunnel)
    {
        $name = $tunnel->name;
        $tunnel->delete();

        return back()->with('success', "Removed branch '{$name}'.");
    }

    /**
     * Ping every branch right now, then return the fresh snapshot. Reuses the
     * scheduled command. Synchronous — ~2s per unreachable branch.
     */
    public function pingNow()
    {
        Artisan::call('tunnel-health:ping');

        return response()->json(['rows' => $this->snapshot()]);
    }

    /** @return array<int, array<string, mixed>> */
    protected function snapshot(): array
    {
        return BranchTunnel::orderBy('sort_order')->orderBy('name')->get()->map(function (BranchTunnel $t) {
            return [
                'id' => $t->id,
                'status' => $t->ping_status ?? 'unknown',
                'latency_ms' => $t->ping_latency_ms,
                'checked' => $t->last_ping_at?->diffForHumans(short: true),
            ];
        })->all();
    }
}
