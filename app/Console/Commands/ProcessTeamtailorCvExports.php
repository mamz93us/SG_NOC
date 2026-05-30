<?php

namespace App\Console\Commands;

use App\Models\TeamtailorCvExport;
use App\Services\Teamtailor\TeamtailorCvExportService;
use Illuminate\Console\Command;

class ProcessTeamtailorCvExports extends Command
{
    protected $signature = 'teamtailor:process-cv-exports {--limit=3 : Max pending exports to drain in one run}';

    protected $description = 'Drain pending Teamtailor CV export requests: fetch every applicant résumé, zip it, upload to Azure Blob.';

    public function handle(TeamtailorCvExportService $service): int
    {
        // Each export can pull hundreds of remote files; the scheduler invokes
        // this in the background, so lift the CLI time limit defensively.
        @set_time_limit(0);

        $limit = max(1, (int) $this->option('limit'));

        $pending = TeamtailorCvExport::query()->pending()->limit($limit)->get();

        if ($pending->isEmpty()) {
            $this->info('No pending Teamtailor CV exports.');

            return Command::SUCCESS;
        }

        foreach ($pending as $export) {
            $this->info("Processing CV export #{$export->id} (job {$export->job_id})…");
            $service->process($export);

            $export->refresh();
            if ($export->isCompleted()) {
                $this->line("  · done: {$export->cv_count} CV(s), {$export->failed_count} failed, {$export->humanSize()}");
            } else {
                $this->warn("  · {$export->status}: ".($export->error ?: 'unknown error'));
            }
        }

        return Command::SUCCESS;
    }
}
