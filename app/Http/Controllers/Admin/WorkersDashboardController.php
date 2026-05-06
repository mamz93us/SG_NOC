<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\CheckHostAvailabilityJob;
use App\Jobs\CollectSnmpMetricsJob;
use App\Jobs\DiscoverSnmpDeviceJob;
use App\Jobs\DiscoverSnmpInterfacesJob;
use App\Models\ActivityLog;
use App\Models\MonitoredHost;
use App\Services\PingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class WorkersDashboardController extends Controller
{
    public function index()
    {
        // Scheduled tasks status
        $tasks = [
            [
                'name'        => 'Host Ping Check',
                'description' => 'Pings all monitored hosts and updates their status.',
                'command'     => 'CheckHostAvailabilityJob',
                'schedule'    => 'Every Minute',
                'last_run'    => $this->lastPingCheck(),
                'color'       => 'primary',
                'icon'        => 'bi-activity',
            ],
            [
                'name'        => 'SNMP Metrics Collection',
                'description' => 'Polls SNMP sensors on all hosts and records metrics.',
                'command'     => 'CollectSnmpMetricsJob',
                'schedule'    => 'Every Minute',
                'last_run'    => $this->lastSnmpCheck(),
                'color'       => 'info',
                'icon'        => 'bi-bar-chart-fill',
            ],
        ];

        // Pending queue jobs
        $queueJobs = DB::table('jobs')->orderBy('created_at', 'desc')->limit(30)->get();
        $failedJobs = DB::table('failed_jobs')->orderBy('failed_at', 'desc')->limit(20)->get();

        // Host summary
        $hosts = MonitoredHost::withCount('hostChecks')->get();

        return view('admin.network.workers', compact('tasks', 'queueJobs', 'failedJobs', 'hosts'));
    }

    public function runPingAll(PingService $pingService)
    {
        $hosts = MonitoredHost::where('ping_enabled', true)->get();
        $results = [];

        foreach ($hosts as $host) {
            try {
                $count = $host->ping_packet_count ?? 3;
                $result = $pingService->ping($host->ip, $count);

                \App\Models\HostCheck::create([
                    'host_id'     => $host->id,
                    'check_type'  => 'ping',
                    'latency_ms'  => $result['latency'],
                    'packet_loss' => $result['packet_loss'],
                    'success'     => $result['success'],
                ]);

                if ($result['success']) {
                    $host->status = 'up';
                    $host->last_ping_at = now();
                } else {
                    $host->status = 'down';
                }
                $host->last_checked_at = now();
                $host->save();

                $results[] = ['host' => $host->name, 'status' => $host->status, 'latency' => $result['latency'] ?? null];
            } catch (\Exception $e) {
                $results[] = ['host' => $host->name, 'status' => 'error', 'error' => $e->getMessage()];
            }
        }

        return redirect()->route('admin.network.workers.index')
            ->with('success', 'Ping sweep completed for ' . count($hosts) . ' hosts.');
    }

    public function runSnmpAll()
    {
        $hosts = MonitoredHost::where('snmp_enabled', true)->get();
        foreach ($hosts as $host) {
            dispatch_sync(new CollectSnmpMetricsJob($host));
        }

        return redirect()->route('admin.network.workers.index')
            ->with('success', 'SNMP metrics collection triggered synchronously for ' . count($hosts) . ' hosts.');
    }

    public function runDiscoverHost(MonitoredHost $host)
    {
        dispatch_sync(new DiscoverSnmpDeviceJob($host));

        return redirect()->route('admin.network.workers.index')
            ->with('success', "Device discovery completed for {$host->name}.");
    }

    public function runDiscoverInterfaces(MonitoredHost $host)
    {
        dispatch_sync(new DiscoverSnmpInterfacesJob($host));

        return redirect()->route('admin.network.workers.index')
            ->with('success', "Interface discovery completed for {$host->name}.");
    }

    public function clearFailedJobs()
    {
        $count = DB::table('failed_jobs')->count();
        DB::table('failed_jobs')->truncate();

        ActivityLog::create([
            'model_type' => 'FailedJobs',
            'model_id'   => 0,
            'action'     => 'failed_jobs_cleared',
            'changes'    => ['rows_cleared' => $count],
            'user_id'    => Auth::id(),
        ]);

        return redirect()->route('admin.network.workers.index')
            ->with('success', 'Failed jobs queue cleared.');
    }

    private function lastPingCheck(): ?string
    {
        $last = \App\Models\HostCheck::where('check_type', 'ping')->latest('checked_at')->first();
        return $last ? $last->checked_at->diffForHumans() : 'Never';
    }

    private function lastSnmpCheck(): ?string
    {
        $last = MonitoredHost::whereNotNull('last_snmp_at')->latest('last_snmp_at')->first();
        return $last ? $last->last_snmp_at->diffForHumans() : 'Never';
    }
}
