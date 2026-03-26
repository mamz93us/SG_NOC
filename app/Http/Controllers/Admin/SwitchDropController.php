<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\SwitchDropStat;
use App\Models\VqAlertEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SwitchDropController extends Controller
{
    public function dashboard()
    {
        $today = today();

        $totalDropsToday = SwitchDropStat::whereDate('polled_at', $today)
            ->selectRaw('SUM(in_discards+out_discards+in_errors+out_errors) as total')
            ->value('total') ?? 0;

        $totalErrorsToday = SwitchDropStat::whereDate('polled_at', $today)
            ->selectRaw('SUM(in_errors+out_errors) as total')->value('total') ?? 0;

        $topDropSwitches = SwitchDropStat::whereDate('polled_at', $today)
            ->select('device_name','device_ip','branch',
                DB::raw('SUM(in_discards+out_discards+in_errors+out_errors) as total_drops'),
                DB::raw('SUM(in_errors+out_errors) as total_errors'))
            ->groupBy('device_name','device_ip','branch')
            ->orderByDesc('total_drops')->limit(10)->get();

        $topDropInterfaces = SwitchDropStat::whereDate('polled_at', $today)
            ->select('device_name','interface_name','branch',
                DB::raw('SUM(in_discards+out_discards) as total_discards'),
                DB::raw('SUM(in_errors+out_errors) as total_errors'),
                DB::raw('SUM(crc_errors) as total_crc'),
                DB::raw('SUM(in_discards+out_discards+in_errors+out_errors) as total_drops'))
            ->groupBy('device_name','interface_name','branch')
            ->orderByDesc('total_drops')->limit(10)->get();

        $byBranch = SwitchDropStat::whereDate('polled_at', $today)
            ->select('branch', DB::raw('SUM(in_discards+out_discards+in_errors+out_errors) as total_drops'))
            ->whereNotNull('branch')->groupBy('branch')->orderByDesc('total_drops')->get();

        $hourlyTrend = SwitchDropStat::whereDate('polled_at', $today)
            ->select(DB::raw('HOUR(polled_at) as hour'),
                DB::raw('SUM(in_discards+out_discards+in_errors+out_errors) as total_drops'))
            ->groupBy('hour')->orderBy('hour')->get();

        $activeAlerts = VqAlertEvent::unresolved()
            ->where('source_type', 'switch')
            ->whereDate('created_at', $today)
            ->orderByDesc('created_at')->get();

        $switchesWithDrops = SwitchDropStat::whereDate('polled_at', $today)
            ->select('device_ip', DB::raw('SUM(in_discards+out_discards+in_errors+out_errors) as t'))
            ->groupBy('device_ip')->havingRaw('t >= 100')->count();

        return view('admin.switch_drops.dashboard', compact(
            'totalDropsToday','totalErrorsToday','topDropSwitches','topDropInterfaces',
            'byBranch','hourlyTrend','activeAlerts','switchesWithDrops'
        ));
    }

    public function index(Request $request)
    {
        $query = SwitchDropStat::query();
        if ($request->filled('branch'))      $query->where('branch', $request->branch);
        if ($request->filled('device_name')) $query->where('device_name', 'like', '%'.$request->device_name.'%');
        if ($request->filled('interface'))   $query->where('interface_name', 'like', '%'.$request->interface.'%');
        if ($request->filled('date_from'))   $query->whereDate('polled_at', '>=', $request->date_from);
        if ($request->filled('date_to'))     $query->whereDate('polled_at', '<=', $request->date_to);

        $stats    = $query->orderByDesc('polled_at')->paginate(50)->withQueryString();
        $branches = Branch::orderBy('name')->pluck('name');

        return view('admin.switch_drops.index', compact('stats','branches'));
    }

    public function device(string $deviceIp)
    {
        $latest = SwitchDropStat::where('device_ip', $deviceIp)->latest('polled_at')->first();
        if (!$latest) abort(404);

        // SNMP counters are cumulative — SUM across all historical rows inflates counts.
        // Use only the latest row per interface (MAX id = most recent poll).
        $interfaces = SwitchDropStat::where('device_ip', $deviceIp)
            ->whereIn('id', function ($sub) use ($deviceIp) {
                $sub->selectRaw('MAX(id)')
                    ->from('switch_drop_stats')
                    ->where('device_ip', $deviceIp)
                    ->groupBy('interface_name');
            })
            ->orderBy('interface_index')->get();

        $trend = SwitchDropStat::where('device_ip', $deviceIp)
            ->where('polled_at', '>=', now()->subDay())
            ->select('interface_name', DB::raw('DATE_FORMAT(polled_at,"%H:%i") as label'),
                DB::raw('in_discards+out_discards+in_errors+out_errors as drops'))
            ->orderBy('polled_at')->get()
            ->groupBy('interface_name');

        return view('admin.switch_drops.device', compact('latest','interfaces','trend','deviceIp'));
    }

    public function statistics(Request $request)
    {
        $from = now()->subDays(30);

        $dailyTrend = SwitchDropStat::where('polled_at', '>=', $from)
            ->select(DB::raw('DATE(polled_at) as date'),
                DB::raw('SUM(in_discards+out_discards+in_errors+out_errors) as total_drops'))
            ->groupBy('date')->orderBy('date')->get();

        $branchComparison = SwitchDropStat::where('polled_at', '>=', $from)
            ->select('branch', DB::raw('SUM(in_discards+out_discards+in_errors+out_errors) as total_drops'))
            ->whereNotNull('branch')->groupBy('branch')->orderByDesc('total_drops')->get();

        $worstDevices = SwitchDropStat::where('polled_at', '>=', now()->subDays(7))
            ->select('device_name','device_ip','branch',
                DB::raw('SUM(in_discards+out_discards+in_errors+out_errors) as total_drops'),
                DB::raw('SUM(in_errors+out_errors) as total_errors'))
            ->groupBy('device_name','device_ip','branch')
            ->orderByDesc('total_drops')->limit(10)->get();

        $worstInterfaces = SwitchDropStat::where('polled_at', '>=', now()->subDays(7))
            ->select('device_name','device_ip','interface_name',
                DB::raw('SUM(in_discards+out_discards+in_errors+out_errors) as total_drops'),
                DB::raw('SUM(in_errors+out_errors) as total_errors'),
                DB::raw('SUM(crc_errors) as total_crc'))
            ->groupBy('device_name','device_ip','interface_name')
            ->orderByDesc('total_drops')->limit(10)->get();

        $errorBreakdown = SwitchDropStat::where('polled_at', '>=', $from)
            ->select(DB::raw('DATE(polled_at) as date'),
                DB::raw('SUM(in_discards) as in_discards'),
                DB::raw('SUM(out_discards) as out_discards'),
                DB::raw('SUM(in_errors) as in_errors'),
                DB::raw('SUM(out_errors) as out_errors'))
            ->groupBy('date')->orderBy('date')->get();

        return view('admin.switch_drops.statistics', compact(
            'dailyTrend','branchComparison','worstDevices','worstInterfaces','errorBreakdown'
        ));
    }

    public function exportCsv(Request $request)
    {
        $query = SwitchDropStat::query();
        if ($request->filled('branch'))      $query->where('branch', $request->branch);
        if ($request->filled('device_name')) $query->where('device_name', 'like', '%'.$request->device_name.'%');
        if ($request->filled('date_from'))   $query->whereDate('polled_at', '>=', $request->date_from);
        if ($request->filled('date_to'))     $query->whereDate('polled_at', '<=', $request->date_to);

        $rows = $query->orderByDesc('polled_at')->limit(10000)->get();
        $headers = ['Content-Type' => 'text/csv', 'Content-Disposition' => 'attachment; filename="switch_drops_export.csv"'];

        $callback = function () use ($rows) {
            $fh = fopen('php://output', 'w');
            fputcsv($fh, ['Device','IP','Branch','Interface','In Discards','Out Discards','In Errors','Out Errors','CRC','In Octets','Out Octets','Polled At']);
            foreach ($rows as $r) {
                fputcsv($fh, [$r->device_name,$r->device_ip,$r->branch,$r->interface_name,$r->in_discards,$r->out_discards,$r->in_errors,$r->out_errors,$r->crc_errors,$r->in_octets,$r->out_octets,$r->polled_at]);
            }
            fclose($fh);
        };
        return response()->stream($callback, 200, $headers);
    }
}
