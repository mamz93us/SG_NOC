<?php

namespace App\Http\Controllers\Admin;

use App\Events\PoorVoiceQualityDetected;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\VoiceQualityReport;
use App\Models\VqAlertEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VoiceQualityController extends Controller
{
    public function dashboard()
    {
        $today = today();

        // Base scope: today + must have a valid MOS reading
        $baseToday = fn() => VoiceQualityReport::today()->whereNotNull('mos_lq');

        $avgMos         = $baseToday()->avg('mos_lq');
        $totalCalls     = $baseToday()->count();
        $poorCalls      = $baseToday()->poor()->count();
        $excellentCalls = $baseToday()->where('mos_lq', '>=', 4.0)->count();

        // Worst calls = lowest MOS first (only records with actual MOS data)
        $worstCalls = $baseToday()
            ->orderBy('mos_lq')
            ->limit(10)
            ->get();

        $byBranch = $baseToday()
            ->select('branch', DB::raw('avg(mos_lq) as avg_mos'), DB::raw('count(*) as call_count'))
            ->whereNotNull('branch')
            ->groupBy('branch')
            ->orderBy('avg_mos')
            ->get();

        $hourlyTrend = $baseToday()
            ->select(DB::raw('HOUR(created_at) as hour'), DB::raw('avg(mos_lq) as avg_mos'), DB::raw('count(*) as calls'))
            ->groupBy('hour')
            ->orderBy('hour')
            ->get();

        $codecStats = $baseToday()
            ->select('codec', DB::raw('count(*) as calls'), DB::raw('avg(mos_lq) as avg_mos'), DB::raw('avg(jitter_avg) as avg_jitter'))
            ->whereNotNull('codec')
            ->groupBy('codec')
            ->orderByDesc('calls')
            ->get();

        $activeAlerts = VqAlertEvent::unresolved()
            ->where('source_type', 'voice')
            ->whereDate('created_at', $today)
            ->orderByDesc('created_at')
            ->get();

        $qualityDistribution = $baseToday()
            ->select('quality_label', DB::raw('count(*) as cnt'))
            ->whereNotNull('quality_label')
            ->groupBy('quality_label')
            ->pluck('cnt', 'quality_label');

        return view('admin.voice_quality.dashboard', compact(
            'avgMos','totalCalls','poorCalls','excellentCalls',
            'worstCalls','byBranch','hourlyTrend','codecStats',
            'activeAlerts','qualityDistribution'
        ));
    }

    public function index(Request $request)
    {
        $query = VoiceQualityReport::query();

        if ($request->filled('branch'))        $query->where('branch', $request->branch);
        if ($request->filled('extension'))     $query->where('extension', 'like', '%'.$request->extension.'%');
        if ($request->filled('quality_label')) $query->where('quality_label', $request->quality_label);
        if ($request->filled('codec'))         $query->where('codec', $request->codec);
        if ($request->filled('date_from'))     $query->whereDate('created_at', '>=', $request->date_from);
        if ($request->filled('date_to'))       $query->whereDate('created_at', '<=', $request->date_to);

        $sort = $request->get('sort', 'created_at');
        $dir  = $request->get('dir', 'desc');
        if (in_array($sort, ['mos_lq','jitter_avg','packet_loss','rtt','created_at'])) {
            $query->orderBy($sort, $dir === 'asc' ? 'asc' : 'desc');
        }

        $reports  = $query->paginate(50)->withQueryString();
        $branches = Branch::orderBy('name')->pluck('name');
        $codecs   = VoiceQualityReport::whereNotNull('codec')->distinct()->pluck('codec');

        return view('admin.voice_quality.index', compact('reports','branches','codecs'));
    }

    public function show(VoiceQualityReport $report)
    {
        return view('admin.voice_quality.show', compact('report'));
    }

    public function statistics(Request $request)
    {
        $days = 30;
        $from = now()->subDays($days);

        $dailyTrend = VoiceQualityReport::where('created_at', '>=', $from)
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('avg(mos_lq) as avg_mos'), DB::raw('count(*) as calls'))
            ->groupBy('date')->orderBy('date')->get();

        $branchComparison = VoiceQualityReport::where('created_at', '>=', $from)
            ->select('branch', DB::raw('avg(mos_lq) as avg_mos'), DB::raw('count(*) as calls'))
            ->whereNotNull('branch')->groupBy('branch')->orderBy('avg_mos')->get();

        $worstExtensions = VoiceQualityReport::where('created_at', '>=', now()->subDays(7))
            ->select('extension', DB::raw('avg(mos_lq) as avg_mos'), DB::raw('avg(jitter_avg) as avg_jitter'), DB::raw('count(*) as calls'), 'branch')
            ->groupBy('extension','branch')->orderBy('avg_mos')->limit(10)->get();

        $bestExtensions = VoiceQualityReport::where('created_at', '>=', now()->subDays(7))
            ->select('extension', DB::raw('avg(mos_lq) as avg_mos'), DB::raw('count(*) as calls'), 'branch')
            ->groupBy('extension','branch')->orderByDesc('avg_mos')->limit(10)->get();

        $codecComparison = VoiceQualityReport::where('created_at', '>=', $from)
            ->select('codec', DB::raw('avg(mos_lq) as avg_mos'), DB::raw('avg(jitter_avg) as avg_jitter'), DB::raw('avg(packet_loss) as avg_loss'), DB::raw('count(*) as calls'))
            ->whereNotNull('codec')->groupBy('codec')->orderByDesc('calls')->get();

        $peakHours = VoiceQualityReport::where('created_at', '>=', $from)
            ->select(DB::raw('HOUR(created_at) as hour'), DB::raw('avg(mos_lq) as avg_mos'), DB::raw('count(*) as calls'))
            ->groupBy('hour')->orderBy('hour')->get();

        $qualityDistribution = VoiceQualityReport::where('created_at', '>=', $from)
            ->select('quality_label', DB::raw('count(*) as cnt'))
            ->whereNotNull('quality_label')->groupBy('quality_label')
            ->pluck('cnt', 'quality_label');

        return view('admin.voice_quality.statistics', compact(
            'dailyTrend','branchComparison','worstExtensions','bestExtensions',
            'codecComparison','peakHours','qualityDistribution'
        ));
    }

    public function receive(Request $request)
    {
        $data = $request->validate([
            'extension'             => 'nullable|string|max:100',
            'remote_extension'      => 'nullable|string|max:100',
            'remote_ip'             => 'nullable|string|max:45',
            'codec'                 => 'nullable|string|max:50',
            'mos_lq'                => 'nullable|numeric|min:1|max:5',
            'mos_cq'                => 'nullable|numeric|min:1|max:5',
            'r_factor'              => 'nullable|numeric|min:0|max:100',
            'jitter_avg'            => 'nullable|numeric',
            'jitter_max'            => 'nullable|numeric',
            'packet_loss'           => 'nullable|numeric|min:0|max:100',
            'burst_loss'            => 'nullable|numeric',
            'rtt'                   => 'nullable|integer|min:0',
            'call_start'            => 'nullable|string',
            'call_end'              => 'nullable|string',
            'call_duration_seconds' => 'nullable|integer',
        ]);

        // Resolve branch from remote_ip
        $branchId = null;
        $branchName = null;
        if (!empty($data['remote_ip'])) {
            $branch = \App\Models\Branch::all()->first(function ($b) use ($data) {
                return !empty($b->ip_range) && $this->ipInRange($data['remote_ip'], $b->ip_range);
            });
            if ($branch) {
                $branchId   = $branch->id;
                $branchName = $branch->name;
            }
        }

        $data['branch_id'] = $branchId;
        $data['branch']    = $branchName;

        if ($data['mos_lq'] !== null && $data['mos_lq'] > 0) {
            $data['quality_label'] = VoiceQualityReport::mosLabel((float)$data['mos_lq']);
        }

        $report = VoiceQualityReport::create($data);

        if ($report->mos_lq !== null && $report->mos_lq < 3.0) {
            event(new PoorVoiceQualityDetected($report));
        }

        return response()->json(['status' => 'ok', 'id' => $report->id]);
    }

    public function chartData(Request $request)
    {
        $type = $request->get('type', 'hourly');
        $date = $request->get('date', today()->toDateString());

        $query = VoiceQualityReport::whereDate('created_at', $date);

        if ($type === 'hourly') {
            $data = $query->select(DB::raw('HOUR(created_at) as label'), DB::raw('avg(mos_lq) as value'), DB::raw('count(*) as calls'))
                ->groupBy('label')->orderBy('label')->get();
        } elseif ($type === 'branch') {
            $data = $query->select('branch as label', DB::raw('avg(mos_lq) as value'), DB::raw('count(*) as calls'))
                ->whereNotNull('branch')->groupBy('label')->orderBy('value')->get();
        } else {
            $data = $query->select('codec as label', DB::raw('avg(mos_lq) as value'), DB::raw('count(*) as calls'))
                ->whereNotNull('codec')->groupBy('label')->orderByDesc('calls')->get();
        }

        return response()->json($data);
    }

    public function exportCsv(Request $request)
    {
        $query = VoiceQualityReport::query();
        if ($request->filled('branch'))    $query->where('branch', $request->branch);
        if ($request->filled('extension')) $query->where('extension', 'like', '%'.$request->extension.'%');
        if ($request->filled('date_from')) $query->whereDate('created_at', '>=', $request->date_from);
        if ($request->filled('date_to'))   $query->whereDate('created_at', '<=', $request->date_to);

        $rows = $query->orderByDesc('created_at')->limit(10000)->get();

        $headers = ['Content-Type' => 'text/csv', 'Content-Disposition' => 'attachment; filename="voice_quality_export.csv"'];

        $callback = function () use ($rows) {
            $fh = fopen('php://output', 'w');
            fputcsv($fh, ['ID','Extension','Remote Ext','Branch','Codec','MOS-LQ','MOS-CQ','R-Factor','Jitter Avg','Jitter Max','Packet Loss','RTT','Quality','Duration(s)','Start','End','Created']);
            foreach ($rows as $r) {
                fputcsv($fh, [$r->id,$r->extension,$r->remote_extension,$r->branch,$r->codec,$r->mos_lq,$r->mos_cq,$r->r_factor,$r->jitter_avg,$r->jitter_max,$r->packet_loss,$r->rtt,$r->quality_label,$r->call_duration_seconds,$r->call_start,$r->call_end,$r->created_at]);
            }
            fclose($fh);
        };

        return response()->stream($callback, 200, $headers);
    }

    private function ipInRange(string $ip, string $range): bool
    {
        if (str_contains($range, '/')) {
            [$net, $mask] = explode('/', $range);
            $ipLong   = ip2long($ip);
            $netLong  = ip2long($net);
            $maskLong = ~((1 << (32 - (int)$mask)) - 1);
            return ($ipLong & $maskLong) === ($netLong & $maskLong);
        }
        return $ip === $range;
    }
}
