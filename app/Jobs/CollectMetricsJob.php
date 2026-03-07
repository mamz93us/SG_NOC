<?php

namespace App\Jobs;

use App\Models\MonitoredHost;
use App\Services\PingService;
use App\Services\SnmpMonitorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CollectMetricsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Execute the job.
     */
    public function handle(SnmpMonitorService $snmpService, PingService $pingService): void
    {
        $hosts = MonitoredHost::all();

        foreach ($hosts as $host) {
            // 1. Perform Ping Check
            $pingResult = $pingService->ping($host->ip, 2);
            
            // 2. Perform SNMP Poll (if enabled)
            if ($host->snmp_enabled) {
                $snmpService->pollHost($host);
            }

            // Update status based on ping result if SNMP wasn't the master status
            $newStatus = $pingResult['success'] ? 'up' : 'down';
            if ($host->status !== $newStatus) {
                $host->update(['status' => $newStatus]);
            }
            
            $host->update(['last_checked_at' => now()]);
        }
    }
}
