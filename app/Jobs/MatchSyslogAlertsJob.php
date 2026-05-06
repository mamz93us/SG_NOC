<?php

namespace App\Jobs;

use App\Models\NocEvent;
use App\Models\SyslogAlertRule;
use App\Models\SyslogMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Walk recent unprocessed syslog rows, run them through every enabled
 * SyslogAlertRule, and surface matches as NocEvents (so the existing
 * notification routing picks them up).
 *
 * Each rule has a cooldown so a flood of identical messages collapses
 * into a single open NocEvent (with last_seen advanced) instead of
 * spamming the alert feed.
 */
class MatchSyslogAlertsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $rules = SyslogAlertRule::where('enabled', true)->get();

        if ($rules->isEmpty()) {
            // No rules — still mark recent rows processed so we don't
            // re-scan them every minute forever.
            $this->markProcessedSince(now()->subHours(2));
            return;
        }

        // Look at unprocessed rows from the last 2 hours. The 2h window
        // protects against gaps if this job was paused.
        $messages = SyslogMessage::query()
            ->whereNull('processed_at')
            ->where('received_at', '>=', now()->subHours(2))
            ->orderBy('received_at')
            ->limit(2000)
            ->get();

        if ($messages->isEmpty()) return;

        $matched = 0;
        foreach ($messages as $msg) {
            foreach ($rules as $rule) {
                if (!$rule->matches($msg)) continue;
                $this->fire($rule, $msg);
                $matched++;
            }
        }

        // Mark every row we examined as processed.
        SyslogMessage::whereIn('id', $messages->pluck('id'))
            ->update(['processed_at' => now()]);

        if ($matched > 0) {
            Log::info("MatchSyslogAlertsJob: {$matched} matches across {$messages->count()} rows.");
        }
    }

    /**
     * Open or refresh the NocEvent for a (rule, host) pair, honoring
     * the rule's cooldown so identical alerts don't multiply.
     */
    private function fire(SyslogAlertRule $rule, SyslogMessage $msg): void
    {
        $cooldownAgo = Carbon::now()->subMinutes($rule->cooldown_minutes ?: 15);

        $existing = NocEvent::where('module', $rule->event_module)
            ->where('source_type', 'syslog_rule')
            ->where('source_id', $rule->id)
            ->where('entity_id', $msg->host)
            ->where('status', '!=', 'resolved')
            ->first();

        if ($existing && $existing->last_seen && $existing->last_seen->gt($cooldownAgo)) {
            // Within cooldown — just bump last_seen and message.
            $existing->update([
                'last_seen' => now(),
                'message'   => $this->truncate($msg->message, 500),
            ]);
        } else {
            NocEvent::create([
                'module'           => $rule->event_module,
                'entity_type'      => 'syslog_host',
                'entity_id'        => $msg->host,
                'source_type'      => 'syslog_rule',
                'source_id'        => $rule->id,
                'cooldown_minutes' => $rule->cooldown_minutes,
                'severity'         => $rule->event_severity,
                'title'            => "Syslog alert: {$rule->name} on {$msg->host}",
                'message'          => $this->truncate($msg->message, 500),
                'first_seen'       => now(),
                'last_seen'        => now(),
                'status'           => 'open',
            ]);
        }

        $rule->increment('match_count');
        $rule->update(['last_matched_at' => now()]);
    }

    private function markProcessedSince(Carbon $since): void
    {
        SyslogMessage::whereNull('processed_at')
            ->where('received_at', '>=', $since)
            ->update(['processed_at' => now()]);
    }

    private function truncate(?string $s, int $max): string
    {
        $s = (string) $s;
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
    }
}
