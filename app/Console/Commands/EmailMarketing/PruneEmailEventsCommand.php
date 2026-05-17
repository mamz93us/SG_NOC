<?php

namespace App\Console\Commands\EmailMarketing;

use App\Models\EmailMarketing\EmailEvent;
use App\Models\Setting;
use Illuminate\Console\Command;

class PruneEmailEventsCommand extends Command
{
    protected $signature = 'email-marketing:prune-events';

    protected $description = 'Delete email_events rows older than the configured retention window.';

    public function handle(): int
    {
        $settings = Setting::get();
        $days = (int) ($settings->email_marketing_event_retention_days
            ?: config('email_marketing.event_retention_days_default', 365));
        if ($days <= 0) {
            $this->warn('Retention disabled (days <= 0); nothing pruned.');

            return Command::SUCCESS;
        }

        $cutoff = now()->subDays($days);
        $deleted = EmailEvent::where('created_at', '<', $cutoff)->delete();

        $this->info("Pruned {$deleted} email_events rows older than {$days} days.");

        return Command::SUCCESS;
    }
}
