<?php

namespace App\Console\Commands\Ai;

use App\Models\AiConversation;
use App\Models\AiSetting;
use Illuminate\Console\Command;

/** Deletes AI Assistant conversations (and their messages, via cascade) older than the configured retention window. */
class PruneAiConversationsCommand extends Command
{
    protected $signature = 'ai:prune-conversations';

    protected $description = 'Delete AI Assistant conversations older than ai_settings.retention_days.';

    public function handle(): int
    {
        $days = (int) (AiSetting::get()->retention_days ?: 180);

        if ($days <= 0) {
            $this->warn('Retention disabled (days <= 0); nothing pruned.');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays($days);

        // Conversations with no messages yet (last_message_at null) are
        // judged on when they were created — a chat someone opened and
        // abandoned is still stale after the same window.
        $deleted = AiConversation::query()
            ->where(function ($q) use ($cutoff) {
                $q->where('last_message_at', '<', $cutoff)
                    ->orWhere(fn ($w) => $w->whereNull('last_message_at')->where('created_at', '<', $cutoff));
            })
            ->delete();

        $this->info("Pruned {$deleted} AI Assistant conversation(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
