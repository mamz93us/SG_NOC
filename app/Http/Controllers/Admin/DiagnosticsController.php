<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MonitoredHost;
use App\Models\HostCheck;
use App\Services\PingService;
use Illuminate\Http\Request;

class DiagnosticsController extends Controller
{
    protected PingService $pingService;

    public function __construct(PingService $pingService)
    {
        $this->pingService = $pingService;
    }

    public function index()
    {
        $hosts = MonitoredHost::with(['branch', 'vpnTunnel'])->orderBy('name')->get();
        return view('admin.network.diagnostics.index', compact('hosts'));
    }

    public function ping(Request $request)
    {
        $request->validate([
            'host' => 'required|ip',
        ]);

        $result = $this->pingService->ping($request->host);

        // If it's a known host, save the check
        $hostModel = MonitoredHost::where('ip', $request->host)->first();
        if ($hostModel) {
            HostCheck::create([
                'host_id' => $hostModel->id,
                'check_type' => 'ping',
                'latency_ms' => $result['latency'],
                'packet_loss' => $result['packet_loss'],
                'success' => $result['success'],
            ]);
        }

        return response()->json($result);
    }

    public function tcpCheck(Request $request)
    {
        $request->validate([
            'host' => 'required|string',
            'port' => 'required|integer|min:1|max:65535',
        ]);

        $result = $this->pingService->tcpCheck($request->host, $request->port);

        return response()->json($result);
    }
}
