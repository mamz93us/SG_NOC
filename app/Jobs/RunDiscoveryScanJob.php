<?php

namespace App\Jobs;

use App\Models\DiscoveryScan;
use App\Services\NetworkDiscoveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunDiscoveryScanJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600; // 10 minutes max
    public int $tries   = 1;

    public function __construct(public readonly int $scanId) {}

    public function handle(NetworkDiscoveryService $service): void
    {
        $scan = DiscoveryScan::find($this->scanId);

        if (! $scan || $scan->status === 'completed') {
            return;
        }

        $service->runScan($scan);
    }
}
