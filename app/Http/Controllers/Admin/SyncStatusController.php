<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ServiceSyncLog;
use App\Models\Setting;
use App\Models\SophosFirewall;
use App\Models\SophosFirewallRule;
use App\Models\SophosInterface;
use App\Models\SophosNetworkObject;
use Illuminate\Http\Request;
use Symfony\Component\Process\PhpExecutableFinder;

class SyncStatusController extends Controller
{
    private const SERVICES = ['identity', 'gdms', 'meraki', 'sophos', 'ucm'];

    public function index()
    {
        $settings = Setting::get();
        $status   = [];

        foreach (self::SERVICES as $service) {
            $last    = ServiceSyncLog::lastFor($service);
            $success = ServiceSyncLog::lastSuccessFor($service);

            $status[$service] = [
                'last'       => $last,
                'lastOk'     => $success,
                'isRunning'  => $last && $last->status === 'running'
                                   && $last->started_at?->gt(now()->subMinutes(30)),
            ];
        }

        // Sophos counts for the status card
        $status['sophos']['counts'] = [
            'firewalls'  => SophosFirewall::where('sync_enabled', true)->count(),
            'interfaces' => SophosInterface::count(),
            'objects'    => SophosNetworkObject::count(),
            'rules'      => SophosFirewallRule::count(),
        ];

        // Resolve intervals (from settings, falling back to defaults)
        $intervals = [
            'identity' => $settings->identity_sync_interval ?? 720,  // minutes — default 12h
            'gdms'     => $settings->gdms_sync_interval     ?? 5,
            'meraki'   => $settings->meraki_polling_interval ?? 5,
            'sophos'   => $settings->sophos_sync_interval   ?? 15,
        ];

        // Recent history (last 20 across all services)
        $history = ServiceSyncLog::orderByDesc('created_at')->limit(20)->get();

        return view('admin.sync-status', compact('status', 'intervals', 'settings', 'history'));
    }

    public function updateIntervals(Request $request)
    {
        $request->validate([
            'identity_sync_interval'  => 'required|integer|min:5|max:10080',
            'gdms_sync_interval'      => 'required|integer|min:5|max:10080',
            'meraki_polling_interval' => 'required|integer|min:5|max:10080',
            'sophos_sync_interval'    => 'required|integer|min:5|max:10080',
        ]);

        $settings = Setting::get();
        $settings->identity_sync_interval  = (int) $request->identity_sync_interval;
        $settings->gdms_sync_interval      = (int) $request->gdms_sync_interval;
        $settings->meraki_polling_interval = (int) $request->meraki_polling_interval;
        $settings->sophos_sync_interval    = (int) $request->sophos_sync_interval;
        $settings->save();

        return redirect()->route('admin.sync-status')
            ->with('success', 'Sync intervals updated. Changes will take effect on the next scheduler run.');
    }

    public function triggerSync(Request $request)
    {
        $request->validate(['service' => 'required|in:identity,gdms,meraki,sophos']);

        $service = $request->service;

        $commandMap = [
            'identity' => 'identity:sync',
            'gdms'     => 'gdms:sync-contacts',
            'meraki'   => 'meraki:sync',
            'sophos'   => 'sophos:sync',
        ];

        // Find CLI php binary (not FPM) using Symfony
        $phpCli = (new PhpExecutableFinder)->find() ?: 'php';
        $artisan = base_path('artisan');
        $logFile = storage_path("logs/{$service}-sync.log");

        $cmd = sprintf('nohup %s %s %s >> %s 2>&1 &',
            escapeshellarg($phpCli),
            escapeshellarg($artisan),
            escapeshellarg($commandMap[$service]),
            escapeshellarg($logFile)
        );

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open('/bin/bash -c ' . escapeshellarg($cmd), $descriptors, $pipes);
        if (is_resource($proc)) {
            foreach ($pipes as $pipe) { @fclose($pipe); }
            proc_close($proc);
        }

        return redirect()->route('admin.sync-status')
            ->with('info', ucfirst($service) . ' sync started in the background.');
    }
}
