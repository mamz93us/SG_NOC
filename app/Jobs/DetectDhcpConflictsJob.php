<?php

namespace App\Jobs;

use App\Services\DhcpLeaseService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DetectDhcpConflictsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        Log::info('DetectDhcpConflictsJob: Running conflict detection');

        try {
            $service   = app(DhcpLeaseService::class);
            $conflicts = $service->detectConflicts();

            Log::info("DetectDhcpConflictsJob: Found {$conflicts} conflict(s)");
        } catch (\Throwable $e) {
            Log::error('DetectDhcpConflictsJob: Failed', ['error' => $e->getMessage()]);
        }
    }
}
