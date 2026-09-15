<?php

namespace App\Services\Recruitment;

use App\Models\AiSetting;
use App\Models\Recruitment\RecruitmentJob;
use App\Models\Recruitment\RecruitmentScreening;

/**
 * One run of recruitment:screen: for every job with screening switched on,
 * refresh what the applicants are judged against, then screen queued
 * applicants one at a time until the time budget is spent.
 *
 * Each applicant is saved before the next one starts, so a run cut short by
 * the budget, a deploy or Azure throttling loses nothing: the next run takes
 * the next queued applicant. An applicant is tried at most once per run, so a
 * flaky download spends its retries across runs, not within one minute.
 */
class ScreeningRunner
{
    /** A job's applicant list is re-read from Teamtailor at most this often. */
    public const SYNC_EVERY_MINUTES = 30;

    private ?string $model = null;

    public function __construct(
        private ApplicantSync $teamtailor,
        private CvReader $cvs,
        private CandidateScreener $screener,
    ) {}

    /**
     * @param  callable(string): void|null  $log
     * @return array{screened: int, failed: int, throttled: bool}
     */
    public function run(float $deadline, ?string $onlyJobId = null, ?callable $log = null): array
    {
        $log ??= static function (string $line): void {};
        $totals = ['screened' => 0, 'failed' => 0, 'throttled' => false];

        $jobs = RecruitmentJob::where('screening_enabled', true)
            ->when($onlyJobId, fn ($query) => $query->where('teamtailor_job_id', $onlyJobId))
            ->orderBy('applicants_synced_at')
            ->get();

        foreach ($jobs as $job) {
            if ($totals['throttled'] || microtime(true) >= $deadline) {
                break;
            }

            $log("Job {$job->teamtailor_job_id} ".($job->title ?? ''));

            try {
                [$ad, $hash] = $this->prepare($job, $log);
            } catch (\Throwable $e) {
                $job->forceFill(['sync_error' => mb_substr($e->getMessage(), 0, 1000)])->save();
                $log('  could not read the job from Teamtailor: '.$e->getMessage());

                continue;
            }

            $mustHaves = $job->mustHaveList();
            $tried = [];
            $screenedHere = 0;

            while (microtime(true) < $deadline) {
                $screening = $job->screenings()
                    ->where('status', RecruitmentScreening::STATUS_PENDING)
                    ->when($tried !== [], fn ($query) => $query->whereNotIn('id', $tried))
                    ->orderBy('attempts')
                    ->orderBy('id')
                    ->first();

                if (! $screening) {
                    break;
                }

                $tried[] = $screening->id;
                $outcome = $this->screenOne($screening, $ad, $mustHaves, $hash);
                $log("  #{$screening->id} {$outcome}".($screening->error ? " - {$screening->error}" : ''));

                if ($outcome === 'throttled') {
                    $totals['throttled'] = true;
                    break;
                }

                if ($outcome === 'screened') {
                    $totals['screened']++;
                    $screenedHere++;
                } elseif ($outcome === 'failed') {
                    $totals['failed']++;
                }
            }

            if ($screenedHere > 0) {
                $job->forceFill(['last_screened_at' => now()])->save();
            }
        }

        return $totals;
    }

    /**
     * Reads the ad, re-reads the applicant list when it is due, and queues again
     * every screening made against other criteria.
     *
     * @return array{0: JobAd, 1: string} the ad and the criteria hash
     */
    private function prepare(RecruitmentJob $job, callable $log): array
    {
        $fetched = $this->teamtailor->fetchAd($job);
        $ad = $fetched['ad'];
        $hash = RecruitmentJob::criteriaHash($ad->text, $job->mustHaveList(), CandidateScreener::INSTRUCTIONS_VERSION);

        $job->forceFill([
            'title' => $ad->title !== '' ? $ad->title : $job->title,
            'job_status' => $fetched['status'] ?? $job->job_status,
            'criteria_hash' => $hash,
        ])->save();

        $due = ! $job->applicants_synced_at
            || $job->applicants_synced_at->lt(now()->subMinutes(self::SYNC_EVERY_MINUTES));

        if ($due) {
            $counts = $this->teamtailor->syncApplicants($job);
            $log("  applicants {$counts['applicants']}, new {$counts['new']}, CV changed {$counts['rescreen']}");
        }

        $stale = $job->screenings()
            ->where('status', RecruitmentScreening::STATUS_SCREENED)
            ->where(fn ($query) => $query->whereNull('criteria_hash')->orWhere('criteria_hash', '!=', $hash))
            ->update(['status' => RecruitmentScreening::STATUS_PENDING, 'attempts' => 0, 'error' => null]);

        if ($stale > 0) {
            $log("  {$stale} screened against other criteria, queued again");
        }

        return [$ad, $hash];
    }

    /**
     * @param  list<string>  $mustHaves
     * @return string screened | no_cv | failed | retry | throttled
     */
    private function screenOne(RecruitmentScreening $screening, JobAd $ad, array $mustHaves, string $hash): string
    {
        try {
            $candidate = $this->teamtailor->fetchCandidate($screening->teamtailor_candidate_id);
        } catch (\Throwable $e) {
            return $this->retryLater($screening, 'Teamtailor: '.$e->getMessage());
        }

        if (! $candidate['resume']) {
            $screening->forceFill([
                'status' => RecruitmentScreening::STATUS_NO_CV,
                'error' => null,
                'answers_text' => $candidate['answers'] !== '' ? $candidate['answers'] : null,
                'criteria_hash' => $hash,
            ])->save();

            return 'no_cv';
        }

        try {
            $cv = $this->cvs->read($candidate['resume']);
        } catch (CvUnreadable $e) {
            return $this->fail($screening, $e->getMessage());
        } catch (\Throwable $e) {
            return $this->retryLater($screening, $e->getMessage());
        }

        try {
            $evaluated = $this->screener->evaluate($ad, $mustHaves, $cv['text'], $cv['images'], $candidate['answers']);
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'HTTP 429')) {
                return 'throttled';
            }

            return str_contains($e->getMessage(), 'content filter')
                ? $this->fail($screening, $e->getMessage())
                : $this->retryLater($screening, $e->getMessage());
        }

        $result = $evaluated['result'];

        $screening->forceFill([
            'status' => RecruitmentScreening::STATUS_SCREENED,
            'attempts' => 0,
            'error' => null,
            'cv_text' => $cv['text'] !== '' ? $cv['text'] : null,
            'cv_pages' => $cv['pages'],
            'cv_read_as' => $cv['images'] !== [] ? 'images' : 'text',
            'answers_text' => $candidate['answers'] !== '' ? $candidate['answers'] : null,
            'score' => $result->score,
            'fit' => $result->fit,
            'must_haves_met' => $result->mustHavesMet,
            'must_haves_total' => $result->mustHavesTotal,
            'evaluation' => $result->evaluation,
            'criteria_hash' => $hash,
            'model' => $this->model(),
            'tokens_in' => $evaluated['tokens_in'],
            'tokens_out' => $evaluated['tokens_out'],
            'screened_at' => now(),
        ])->save();

        return 'screened';
    }

    private function retryLater(RecruitmentScreening $screening, string $message): string
    {
        $attempts = $screening->attempts + 1;

        if ($attempts >= RecruitmentScreening::MAX_ATTEMPTS) {
            return $this->fail($screening, $message, $attempts);
        }

        $screening->forceFill(['attempts' => $attempts, 'error' => mb_substr($message, 0, 1000)])->save();

        return 'retry';
    }

    private function fail(RecruitmentScreening $screening, string $message, ?int $attempts = null): string
    {
        $screening->forceFill([
            'status' => RecruitmentScreening::STATUS_FAILED,
            'attempts' => $attempts ?? $screening->attempts + 1,
            'error' => mb_substr($message, 0, 1000),
        ])->save();

        return 'failed';
    }

    /** The chat deployment, recorded on each screening; a label only, so never a reason to fail. */
    private function model(): ?string
    {
        return $this->model ??= rescue(fn () => AiSetting::get()->chat_deployment, null, false);
    }
}
