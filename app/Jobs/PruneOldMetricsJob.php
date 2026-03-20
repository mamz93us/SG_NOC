<?php

namespace App\Jobs;

use App\Models\SensorMetric;
use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class PruneOldMetricsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $settings = Setting::get();
        $retentionDays = $settings->metrics_retention_days ?? 90;
        $cutoff = Carbon::now()->subDays($retentionDays);

        $totalDeleted = 0;

        while (true) {
            $deleted = SensorMetric::where('recorded_at', '<', $cutoff)
                ->limit(1000)
                ->delete();

            if ($deleted === 0) {
                break;
            }

            $totalDeleted += $deleted;
        }

        Log::info("PruneOldMetricsJob: Deleted {$totalDeleted} sensor metric records older than {$retentionDays} days.");
    }
}
