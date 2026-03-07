<?php

namespace App\Jobs;

use App\Services\LicenseMonitorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CheckLicenseMonitorsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries   = 1;

    public function handle(LicenseMonitorService $service): void
    {
        Log::info('CheckLicenseMonitorsJob: starting license monitor check.');
        $service->checkAllMonitors();
        Log::info('CheckLicenseMonitorsJob: check complete.');
    }
}
