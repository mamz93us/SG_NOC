<?php

namespace App\Jobs;

use App\Models\Setting;
use App\Models\SyslogMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Delete syslog_messages older than the configured retention.
 * Mirrors the chunked-delete pattern of PruneOldMetricsJob to keep
 * deletes from holding row locks too long on a hot table.
 */
class PruneOldSyslogJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $settings = Setting::first();
        $days = max(1, (int) ($settings->syslog_retention_days ?? 30));
        $cutoff = Carbon::now()->subDays($days);

        $totalDeleted = 0;

        while (true) {
            $deleted = SyslogMessage::where('received_at', '<', $cutoff)
                ->limit(5000)
                ->delete();

            if ($deleted === 0) {
                break;
            }

            $totalDeleted += $deleted;
        }

        if ($totalDeleted > 0) {
            Log::info("PruneOldSyslogJob: Deleted {$totalDeleted} syslog rows older than {$days} days.");
        }
    }
}
