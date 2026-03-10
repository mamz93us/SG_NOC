<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\IspConnection;
use App\Models\LinkCheck;
use App\Services\SlaMonitorService;
use Illuminate\Http\Request;

class SlaController extends Controller
{
    public function __construct(private SlaMonitorService $sla) {}

    public function index()
    {
        $isps = IspConnection::with('branch')->get();

        $stats = $isps->map(function ($isp) {
            return [
                'isp'         => $isp,
                'uptime'      => $this->sla->monthlyUptime($isp->id),
                'avg_latency' => $this->sla->avgLatency($isp->id),
                'avg_loss'    => $this->sla->avgPacketLoss($isp->id),
                'last_check'  => LinkCheck::where('isp_id', $isp->id)->latest('checked_at')->first(),
                'checks_24h'  => LinkCheck::where('isp_id', $isp->id)->where('checked_at', '>=', now()->subDay())->count(),
            ];
        });

        return view('admin.network.sla.index', compact('stats'));
    }

    public function detail($ispId)
    {
        $isp = IspConnection::with('branch')->findOrFail($ispId);

        $uptime     = $this->sla->monthlyUptime($isp->id);
        $avgLatency = $this->sla->avgLatency($isp->id);
        $avgLoss    = $this->sla->avgPacketLoss($isp->id);

        // Last 24h checks for chart
        $checks = LinkCheck::where('isp_id', $isp->id)
            ->where('checked_at', '>=', now()->subDay())
            ->orderBy('checked_at')
            ->get();

        $chartLabels  = $checks->map(fn($c) => $c->checked_at->format('H:i'))->values();
        $chartLatency = $checks->map(fn($c) => $c->latency ?? 0)->values();
        $chartLoss    = $checks->map(fn($c) => $c->packet_loss)->values();

        return view('admin.network.sla.detail', compact(
            'isp', 'uptime', 'avgLatency', 'avgLoss',
            'chartLabels', 'chartLatency', 'chartLoss'
        ));
    }
}
