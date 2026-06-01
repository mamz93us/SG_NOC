<?php

namespace App\Jobs\BrowserPortal;

use App\Services\BrowserPortal\SessionManager;
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;

class CleanupIdleSessionsJob
{
    use Dispatchable, Queueable;

    public int $idleMinutes;

    public function __construct(?int $idleMinutes = null)
    {
        $this->idleMinutes = $idleMinutes ?? (int) env('BROWSER_PORTAL_IDLE_MINUTES', 240);
    }

    public function handle(SessionManager $sessions): void
    {
        $cutoff = now()->subMinutes($this->idleMinutes);
        $stopped = $sessions->stopIdleSessions($cutoff);
        if ($stopped > 0) {
            Log::info("CleanupIdleSessionsJob: stopped $stopped idle session(s) (cutoff = {$this->idleMinutes}m).");
        }

        // Reclaim containers Docker still runs but the DB has forgotten — these
        // squat on UDP chunks and make new launches fail with "port already
        // allocated" (most often on 52000).
        $reclaimed = $sessions->reconcileOrphans();
        if ($reclaimed > 0) {
            Log::warning("CleanupIdleSessionsJob: reclaimed $reclaimed orphan neko container(s) holding UDP ports.");
        }
    }
}
