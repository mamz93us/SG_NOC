<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\Voice\VoiceMeshMonitor;
use Illuminate\Console\Command;

/**
 * The voice mesh has two independent schedulers: Laravel's (kept alive by
 * supervisor) and the systemd timer that runs the pjsua prober. Nothing in the
 * app can tell that the systemd side has died — the matrix would simply stop
 * changing and keep showing its last green.
 *
 * This is the watcher for that. It is the reason the mesh can be trusted at a
 * glance, so don't drop it as nice-to-have.
 */
class CheckStaleVoiceMesh extends Command
{
    protected $signature = 'voice-mesh:check-stale
                            {--prune : Drop history past the retention window instead of checking}
                            {--retain= : Days of history to keep when pruning (defaults to the Settings value)}';

    protected $description = 'Alert when the voice mesh prober stops reporting, or prune its history';

    public function handle(VoiceMeshMonitor $monitor): int
    {
        if ($this->option('prune')) {
            return $this->prune($monitor);
        }

        $message = $monitor->checkStale();

        if ($message === null) {
            $this->info('[voice-mesh] reporting normally.');

            return self::SUCCESS;
        }

        $this->warn("[voice-mesh] {$message}");

        return self::SUCCESS;
    }

    private function prune(VoiceMeshMonitor $monitor): int
    {
        $days = (int) ($this->option('retain')
            ?: (Setting::get()->voice_mesh_retention_days ?: 30));

        $deleted = $monitor->prune($days);

        $this->info(sprintf(
            '[voice-mesh] pruned %s result rows and %s runs older than %d days.',
            number_format($deleted['results']),
            number_format($deleted['runs']),
            $days
        ));

        return self::SUCCESS;
    }
}
