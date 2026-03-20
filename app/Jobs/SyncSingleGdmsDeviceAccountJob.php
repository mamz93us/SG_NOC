<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;

class SyncSingleGdmsDeviceAccountJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 85; // slightly under the default 90s retry_after
    public int $tries   = 1; 
    
    protected string $mac;

    public function __construct(string $mac)
    {
        $this->mac = $mac;
    }

    public function handle(): void
    {
        Artisan::call('gdms:sync-device-accounts', ['--mac' => $this->mac]);
    }
}
