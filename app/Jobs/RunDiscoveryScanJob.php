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

    public int $tries = 1;

    public function __construct(public readonly int $scanId) {}

    public function handle(NetworkDiscoveryService $service): void
    {
        $scan = DiscoveryScan::find($this->scanId);

        // Only start scans that are still pending. A long scan outlives the
        // queue's retry_after (90s), so the DB queue hands the same job to a
        // second worker mid-scan — without this guard every host gets probed
        // and inserted twice.
        if (! $scan || $scan->status !== 'pending') {
            return;
        }

        $service->runScan($scan);
    }
}
