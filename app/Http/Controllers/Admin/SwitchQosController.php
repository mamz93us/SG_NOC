<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\SwitchQosStat;
use App\Models\VqAlertEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SwitchQosController extends Controller
{
    /**
     * `total_drops` and the per-queue counters are cumulative since last clear on the switch,
     * so SUMming across polls inflates numbers. Everywhere in this controller we take the
     * most recent poll per (device, interface) via a MAX(id) subquery.
     */
    public function dashboard()
    {
        $today = today();

        $latestIds = SwitchQosStat::selectRaw('MAX(id) as id')
            ->whereDate('polled_at', $today)
            ->groupBy('device_ip', 'interface_name')
            ->pluck('id');

        $latest = SwitchQosStat::whereIn('id', $latestIds);

        $interfacesWithDrops = (clone $latest)->where('total_drops', '>', 0)->count();
        $switchesPolled      = (clone $latest)->distinct('device_ip')->count('device_ip');
        $policerOutOfProfile = (clone $latest)->sum('policer_out_of_profile');

        $topDropInterfaces = (clone $latest)
            ->where('total_drops', '>', 0)
            ->orderByDesc('total_drops')
            ->limit(10)
            ->get(['device_name','device_ip','interface_name','branch_id',
                'q0_t1_drop','q0_t2_drop','q0_t3_drop',
                'q1_t1_drop','q1_t2_drop','q1_t3_drop',
                'q2_t1_drop','q2_t2_drop','q2_t3_drop',
                'q3_t1_drop','q3_t2_drop','q3_t3_drop',
                'total_drops','policer_out_of_profile','polled_at']);

        $topDropSwitches = (clone $latest)
            ->select('device_name', 'device_ip', 'branch_id',
                DB::raw('SUM(total_drops) as total_drops'),
                DB::raw('SUM(policer_out_of_profile) as total_policer'))
            ->groupBy('device_name', 'device_ip', 'branch_id')
            ->orderByDesc('total_drops')
            ->limit(10)
            ->get();

        // Per-queue drop breakdown across all interfaces (latest poll only)
        $queueBreakdown = (clone $latest)
            ->selectRaw('
                SUM(q0_t1_drop + q0_t2_drop + q0_t3_drop) as q0,
                SUM(q1_t1_drop + q1_t2_drop + q1_t3_drop) as q1,
                SUM(q2_t1_drop + q2_t2_drop + q2_t3_drop) as q2,
                SUM(q3_t1_drop + q3_t2_drop + q3_t3_drop) as q3
            ')
            ->first();

        $activeAlerts = VqAlertEvent::unresolved()
            ->where('source_type', 'switch-qos')
            ->whereDate('created_at', $today)
            ->orderByDesc('created_at')
            ->get();

        return view('admin.switch_qos.dashboard', compact(
            'interfacesWithDrops', 'switchesPolled', 'policerOutOfProfile',
            'topDropInterfaces', 'topDropSwitches', 'queueBreakdown', 'activeAlerts'
        ));
    }

    public function index(Request $request)
    {
        $query = SwitchQosStat::query();

        if ($request->filled('device_name')) {
            $query->where('device_name', 'like', '%' . $request->device_name . '%');
        }
        if ($request->filled('device_ip')) {
            $query->where('device_ip', $request->device_ip);
        }
        if ($request->filled('interface')) {
            $query->where('interface_name', 'like', '%' . $request->interface . '%');
        }
        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('polled_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('polled_at', '<=', $request->date_to);
        }
        if ($request->boolean('drops_only')) {
            $query->where('total_drops', '>', 0);
        }

        $stats    = $query->orderByDesc('polled_at')->paginate(50)->withQueryString();
        $branches = Branch::orderBy('name')->get(['id', 'name']);

        return view('admin.switch_qos.index', compact('stats', 'branches'));
    }

    public function device(string $deviceIp)
    {
        $latestSnapshot = SwitchQosStat::where('device_ip', $deviceIp)
            ->latest('polled_at')
            ->first();

        if (!$latestSnapshot) {
            abort(404);
        }

        $interfaces = SwitchQosStat::where('device_ip', $deviceIp)
            ->whereIn('id', function ($sub) use ($deviceIp) {
                $sub->selectRaw('MAX(id)')
                    ->from('switch_qos_stats')
                    ->where('device_ip', $deviceIp)
                    ->groupBy('interface_name');
            })
            ->orderBy('interface_name')
            ->get();

        $trend = SwitchQosStat::where('device_ip', $deviceIp)
            ->where('polled_at', '>=', now()->subDay())
            ->where('total_drops', '>', 0)
            ->select('interface_name', 'total_drops',
                DB::raw('DATE_FORMAT(polled_at, "%H:%i") as label'))
            ->orderBy('polled_at')
            ->get()
            ->groupBy('interface_name');

        return view('admin.switch_qos.device', compact('latestSnapshot', 'interfaces', 'trend', 'deviceIp'));
    }

    public function exportCsv(Request $request)
    {
        $query = SwitchQosStat::query();

        if ($request->filled('device_name')) {
            $query->where('device_name', 'like', '%' . $request->device_name . '%');
        }
        if ($request->filled('device_ip')) {
            $query->where('device_ip', $request->device_ip);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('polled_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('polled_at', '<=', $request->date_to);
        }

        $rows    = $query->orderByDesc('polled_at')->limit(10000)->get();
        $headers = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="switch_qos_export.csv"',
        ];

        $callback = function () use ($rows) {
            $fh = fopen('php://output', 'w');
            fputcsv($fh, [
                'Device', 'IP', 'Interface',
                'Q0_T1_drop','Q0_T2_drop','Q0_T3_drop',
                'Q1_T1_drop','Q1_T2_drop','Q1_T3_drop',
                'Q2_T1_drop','Q2_T2_drop','Q2_T3_drop',
                'Q3_T1_drop','Q3_T2_drop','Q3_T3_drop',
                'Policer InProfile','Policer OutOfProfile',
                'Total Drops','Polled At',
            ]);
            foreach ($rows as $r) {
                fputcsv($fh, [
                    $r->device_name, $r->device_ip, $r->interface_name,
                    $r->q0_t1_drop,$r->q0_t2_drop,$r->q0_t3_drop,
                    $r->q1_t1_drop,$r->q1_t2_drop,$r->q1_t3_drop,
                    $r->q2_t1_drop,$r->q2_t2_drop,$r->q2_t3_drop,
                    $r->q3_t1_drop,$r->q3_t2_drop,$r->q3_t3_drop,
                    $r->policer_in_profile, $r->policer_out_of_profile,
                    $r->total_drops, $r->polled_at,
                ]);
            }
            fclose($fh);
        };

        return response()->stream($callback, 200, $headers);
    }
}
