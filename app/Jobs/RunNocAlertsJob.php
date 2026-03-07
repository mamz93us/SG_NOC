<?php

namespace App\Jobs;

use App\Services\NocAlertEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RunNocAlertsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries   = 1;

    public function handle(NocAlertEngine $engine): void
    {
        Log::info('RunNocAlertsJob: starting alert detection.');
        $engine->detectAll();
        Log::info('RunNocAlertsJob: detection complete.');
    }
}
