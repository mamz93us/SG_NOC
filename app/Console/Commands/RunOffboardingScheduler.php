<?php

namespace App\Console\Commands;

use App\Services\Workflow\OffboardingScheduler;
use Illuminate\Console\Command;

class RunOffboardingScheduler extends Command
{
    protected $signature = 'offboarding:run-scheduler {--date= : Override "today" (YYYY-MM-DD) for replay/testing}';

    protected $description = 'Drive the offboarding lifecycle: auto-disable on last day, reminders, escalation, final delete.';

    public function handle(OffboardingScheduler $scheduler): int
    {
        $today = $this->option('date')
            ? \Carbon\Carbon::parse($this->option('date'))
            : now();

        $this->info("Running offboarding scheduler for " . $today->toDateString());
        $summary = $scheduler->run($today);

        foreach ($summary as $key => $count) {
            $this->line("  · {$key}: {$count}");
        }

        return Command::SUCCESS;
    }
}
