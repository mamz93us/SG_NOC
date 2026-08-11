<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BranchTunnel;
use App\Models\TunnelProbe;
use App\Services\Network\TunnelWatchdog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Branch Tunnel Watchdog — the live board for the Azure VPN gateway tunnels.
 * Replaces the retired strongSwan VPN Hub, which could start and stop tunnels
 * the NOC no longer owns.
 *
 * Each tunnel is a gateway (branch firewall) plus probes for the subnets it is
 * supposed to carry. Viewing needs view-network; editing needs
 * manage-network-settings.
 */
class TunnelHealthController extends Controller
{
    /** How many recent checks the strip chart shows per tunnel. */
    private const STRIP_LENGTH = 60;

    public function index()
    {
        $tunnels = BranchTunnel::with(['probes', 'branch'])->ordered()->get();

        return view('admin.network.tunnel-health', [
            'tunnels' => $tunnels,
            'rows' => $this->snapshot(),
            'branches' => Branch::orderBy('name')->get(['id', 'name']),
            'checkTypes' => TunnelWatchdog::checkTypes(),
        ]);
    }

    /** JSON snapshot for the page's auto-refresh poll. Read-only, cheap. */
    public function data()
    {
        return response()->json([
            'rows' => $this->snapshot(),
            'summary' => $this->summary(),
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Probe everything right now and return the fresh snapshot. Synchronous —
     * a full sweep is a couple of seconds because probes run in parallel.
     */
    public function checkNow(TunnelWatchdog $watchdog)
    {
        $watchdog->run();

        return response()->json([
            'rows' => $this->snapshot(),
            'summary' => $this->summary(),
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // Tunnels
    // ─────────────────────────────────────────────────────────────

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'firewall_ip' => 'required|ip',
            'branch_id' => 'nullable|exists:branches,id',
        ]);

        $tunnel = BranchTunnel::create($data);

        return back()->with('success', "Added tunnel '{$tunnel->name}'. Add probes for the subnets it should carry.");
    }

    public function update(Request $request, BranchTunnel $tunnel)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'firewall_ip' => 'required|ip',
            'branch_id' => 'nullable|exists:branches,id',
            'is_active' => 'sometimes|boolean',
        ]);

        // Re-baseline if the gateway moved — the old ping state describes a
        // different host and would misreport until the next sweep.
        if ($data['firewall_ip'] !== $tunnel->firewall_ip) {
            $data += [
                'state' => BranchTunnel::STATE_UNKNOWN,
                'ping_status' => 'unknown',
                'ping_latency_ms' => null,
                'last_ping_at' => null,
                'consecutive_failures' => 0,
            ];
        }

        $tunnel->update($data);

        return back()->with('success', "Updated tunnel '{$tunnel->name}'.");
    }

    public function destroy(BranchTunnel $tunnel)
    {
        $name = $tunnel->name;
        $tunnel->delete();   // probes + history cascade

        return back()->with('success', "Removed tunnel '{$name}'.");
    }

    // ─────────────────────────────────────────────────────────────
    // Probes
    // ─────────────────────────────────────────────────────────────

    public function storeProbe(Request $request, BranchTunnel $tunnel)
    {
        $data = $this->validateProbe($request);

        $tunnel->probes()->create($data);

        return back()->with('success', "Added probe '{$data['label']}' to {$tunnel->name}.");
    }

    public function updateProbe(Request $request, TunnelProbe $probe)
    {
        $data = $this->validateProbe($request);

        // Reset state when the target changes, same reasoning as the gateway.
        if ($data['target'] !== $probe->target || ($data['port'] ?? null) !== $probe->port) {
            $data += [
                'status' => 'unknown',
                'latency_ms' => null,
                'last_checked_at' => null,
                'consecutive_failures' => 0,
            ];
        }

        $probe->update($data);

        return back()->with('success', "Updated probe '{$probe->label}'.");
    }

    public function destroyProbe(TunnelProbe $probe)
    {
        $label = $probe->label;
        $probe->delete();

        return back()->with('success', "Removed probe '{$label}'.");
    }

    protected function validateProbe(Request $request): array
    {
        $data = $request->validate([
            'label' => 'required|string|max:255',
            'target' => 'required|ip',
            'check_type' => 'required|in:'.implode(',', array_keys(TunnelWatchdog::checkTypes())),
            'port' => 'nullable|integer|min:1|max:65535|required_unless:check_type,icmp',
            'is_active' => 'sometimes|boolean',
        ], [
            'port.required_unless' => 'A port is required for anything other than an ICMP probe.',
        ]);

        // An ICMP probe has no port; drop any leftover value so the row is clean.
        if ($data['check_type'] === TunnelProbe::TYPE_ICMP) {
            $data['port'] = null;
        }

        return $data;
    }

    // ─────────────────────────────────────────────────────────────
    // Snapshot
    // ─────────────────────────────────────────────────────────────

    /** @return array<int, array<string, mixed>> */
    protected function snapshot(): array
    {
        $tunnels = BranchTunnel::with(['probes', 'branch'])->ordered()->get();

        $strips = $this->strips($tunnels->pluck('id')->all());
        $uptime = $this->uptime($tunnels->pluck('id')->all());

        return $tunnels->map(fn (BranchTunnel $t) => [
            'id' => $t->id,
            'name' => $t->name,
            'branch' => $t->branch?->name,
            'firewall_ip' => $t->firewall_ip,
            'is_active' => $t->is_active,
            'state' => $t->state ?? BranchTunnel::STATE_UNKNOWN,
            'state_label' => $t->stateLabel(),
            'latency_ms' => $t->ping_latency_ms,
            'checked' => $t->last_ping_at?->diffForHumans(short: true),
            'since' => $t->state_changed_at?->diffForHumans(short: true),
            'uptime_24h' => $uptime[$t->id] ?? null,
            'strip' => $strips[$t->id] ?? [],
            'probes' => $t->probes->map(fn (TunnelProbe $p) => [
                'id' => $p->id,
                'label' => $p->label,
                'target' => $p->target,
                'target_label' => $p->targetLabel(),
                'check_type' => $p->check_type,
                'port' => $p->port,
                'is_active' => $p->is_active,
                'status' => $p->is_active ? ($p->status ?? 'unknown') : 'paused',
                'latency_ms' => $p->latency_ms,
                'note' => $p->result_note,
                'checked' => $p->last_checked_at?->diffForHumans(short: true),
            ])->values()->all(),
            'probes_up' => $t->probes->where('is_active', true)->where('status', 'up')->count(),
            'probes_total' => $t->probes->where('is_active', true)->count(),
        ])->values()->all();
    }

    /** @return array{up:int, degraded:int, down:int, unknown:int, total:int} */
    protected function summary(): array
    {
        $counts = BranchTunnel::active()
            ->select('state', DB::raw('COUNT(*) as c'))
            ->groupBy('state')
            ->pluck('c', 'state');

        return [
            'up' => (int) ($counts[BranchTunnel::STATE_UP] ?? 0),
            'degraded' => (int) ($counts[BranchTunnel::STATE_DEGRADED] ?? 0),
            'down' => (int) ($counts[BranchTunnel::STATE_DOWN] ?? 0),
            'unknown' => (int) ($counts[BranchTunnel::STATE_UNKNOWN] ?? 0),
            'total' => (int) $counts->sum(),
        ];
    }

    /**
     * Last N check states per tunnel, oldest → newest, for the strip chart.
     * One query for every tunnel rather than one per row.
     *
     * @param  array<int, int>  $tunnelIds
     * @return array<int, array<int, string>>
     */
    protected function strips(array $tunnelIds): array
    {
        if ($tunnelIds === []) {
            return [];
        }

        // Pull a bounded recent window for all tunnels at once, then slice per
        // tunnel in PHP — cheaper than a window function per row and portable
        // across MySQL and the SQLite used in tests.
        $rows = DB::table('tunnel_health_checks')
            ->select('branch_tunnel_id', 'state', 'checked_at')
            ->whereIn('branch_tunnel_id', $tunnelIds)
            ->where('checked_at', '>=', now()->subHours(6))
            ->orderByDesc('checked_at')
            ->limit(count($tunnelIds) * self::STRIP_LENGTH * 2)
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $id = (int) $row->branch_tunnel_id;
            if (count($out[$id] ?? []) >= self::STRIP_LENGTH) {
                continue;
            }
            $out[$id][] = $row->state;
        }

        // Collected newest-first; the chart reads left-to-right as oldest-first.
        return array_map('array_reverse', $out);
    }

    /**
     * 24-hour "fully up" percentage per tunnel, in one grouped query.
     *
     * @param  array<int, int>  $tunnelIds
     * @return array<int, float|null>
     */
    protected function uptime(array $tunnelIds): array
    {
        if ($tunnelIds === []) {
            return [];
        }

        $rows = DB::table('tunnel_health_checks')
            ->select(
                'branch_tunnel_id',
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN state = 'up' THEN 1 ELSE 0 END) as up_count")
            )
            ->whereIn('branch_tunnel_id', $tunnelIds)
            ->where('checked_at', '>=', now()->subDay())
            ->groupBy('branch_tunnel_id')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $total = (int) $row->total;
            $out[(int) $row->branch_tunnel_id] = $total > 0
                ? round((int) $row->up_count / $total * 100, 1)
                : null;
        }

        return $out;
    }
}
