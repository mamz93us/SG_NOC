<?php

namespace App\Jobs\Ticketing;

use App\Services\Ticketing\TicketVisitRecorder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Async path for persisting a ticket visit. Only used when
 * config('ticket_tracking.async_logging') is true.
 *
 * NOTE: production has no dedicated queue worker (scheduler-as-worker), so a
 * queued visit lands on the next queue drain, not instantly. The inline path
 * in TicketForwardController is the default for that reason.
 */
class LogTicketVisitJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @param array{ip:?string,user_agent:?string,referrer:?string,session_id:?string,visited_at:?string} $raw */
    public function __construct(public array $raw) {}

    public function handle(TicketVisitRecorder $recorder): void
    {
        try {
            $recorder->record($this->raw);
        } catch (\Throwable $e) {
            // Never let a logging failure surface anywhere user-facing.
            Log::error('[LogTicketVisitJob] failed to record visit: '.$e->getMessage());
        }
    }
}
