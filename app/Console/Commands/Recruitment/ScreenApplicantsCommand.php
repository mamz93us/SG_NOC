<?php

namespace App\Console\Commands\Recruitment;

use App\Models\AiSetting;
use App\Models\Recruitment\RecruitmentJob;
use App\Services\Recruitment\ScreeningRunner;
use App\Services\Teamtailor\TeamtailorApiService;
use Illuminate\Console\Command;

/**
 * Recruitment AI's worker: reads and scores the applicants of every job a
 * recruiter switched screening on for. One chat call per CV, so never in a web
 * request — the Recruitment AI page only queues.
 */
class ScreenApplicantsCommand extends Command
{
    protected $signature = 'recruitment:screen
        {--max-seconds=240 : Start no new applicant after this long}
        {--job= : Only this Teamtailor job id}';

    protected $description = 'Read and score the applicants of the jobs Recruitment AI screening is switched on for';

    public function handle(ScreeningRunner $runner, TeamtailorApiService $teamtailor): int
    {
        if (! RecruitmentJob::where('screening_enabled', true)->exists()) {
            $this->comment('No job has AI screening switched on.');

            return self::SUCCESS;
        }

        $settings = AiSetting::get();

        // Waiting rather than failing: fixing the configuration is the fix.
        if (! $settings->isConfigured()) {
            $this->warn('Screening is waiting: '.$settings->configurationIssue());

            return self::SUCCESS;
        }

        if (! $teamtailor->isConfigured()) {
            $this->warn('Screening is waiting: Teamtailor is not configured.');

            return self::SUCCESS;
        }

        @set_time_limit(0);

        $deadline = microtime(true) + max(1, (int) $this->option('max-seconds'));
        $totals = $runner->run($deadline, $this->option('job') ?: null, fn (string $line) => $this->line($line));

        $this->info("Screened {$totals['screened']}, failed {$totals['failed']}"
            .($totals['throttled'] ? '; stopped early: Azure OpenAI is throttling (HTTP 429)' : ''));

        return self::SUCCESS;
    }
}
