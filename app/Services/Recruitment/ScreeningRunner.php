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

        $jobs = RecruitmentJob::with('office')
            ->where('screening_enabled', true)
            ->when($onlyJobId, fn ($query) => $query->where('teamtailor_job_id', $onlyJobId))
            ->orderBy('applicants_synced_at')
            ->get();

        foreach ($jobs as $job) {
            if ($totals['throttled'] || microtime(true) >= $deadline) {
                break;
            }

            $log("Job {$job->teamtailor_job_id} ".($job->title ?? ''));

            try {
                $plan = $this->prepare($job, $log);
            } catch (\Throwable $e) {
                $job->forceFill(['sync_error' => mb_substr($e->getMessage(), 0, 1000)])->save();
                $log('  could not read the job from Teamtailor: '.$e->getMessage());

                continue;
            }

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
                $outcome = $this->screenOne($screening, $plan);
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
     * every screening made against other criteria. Returns what every applicant
     * of the job is read against.
     *
     * @return array{ad: JobAd, must_haves: list<string>, hash: string, picked: list<string>, office: ?string, office_name: ?string, budget: ?string, currency: ?string}
     */
    private function prepare(RecruitmentJob $job, callable $log): array
    {
        $fetched = $this->teamtailor->fetchAd($job);
        $ad = $fetched['ad'];
        $mustHaves = $job->mustHaveList();
        $hash = RecruitmentJob::criteriaHash($ad->text, $mustHaves, CandidateScreener::INSTRUCTIONS_VERSION, $job->contextText());

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

        // Applicants without a CV are queued again too: reading them costs no AI
        // call, and it fills in what new criteria read, such as their salary.
        $stale = $job->screenings()
            ->whereIn('status', [RecruitmentScreening::STATUS_SCREENED, RecruitmentScreening::STATUS_NO_CV])
            ->where(fn ($query) => $query->whereNull('criteria_hash')->orWhere('criteria_hash', '!=', $hash))
            ->update(['status' => RecruitmentScreening::STATUS_PENDING, 'attempts' => 0, 'error' => null]);

        if ($stale > 0) {
            $log("  {$stale} read against other criteria, queued again");
        }

        return [
            'ad' => $ad,
            'must_haves' => $mustHaves,
            'hash' => $hash,
            'picked' => $this->teamtailor->pickedQuestionIds($job->teamtailor_job_id),
            'office' => $job->officeText(),
            'office_name' => $job->office?->name,
            'budget' => $job->budgetText(),
            'currency' => $job->salary_currency,
        ];
    }

    /**
     * @param  array{ad: JobAd, must_haves: list<string>, hash: string, picked: list<string>, office: ?string, office_name: ?string, budget: ?string, currency: ?string}  $plan  from prepare()
     * @return string screened | no_cv | failed | retry | throttled
     */
    private function screenOne(RecruitmentScreening $screening, array $plan): string
    {
        try {
            $candidate = $this->teamtailor->fetchCandidate($screening->teamtailor_candidate_id, $plan['picked'], $plan['currency']);
        } catch (\Throwable $e) {
            return $this->retryLater($screening, 'Teamtailor: '.$e->getMessage());
        }

        $answers = $candidate['answers'] !== '' ? $candidate['answers'] : null;

        if (! $candidate['resume']) {
            $screening->forceFill([
                'status' => RecruitmentScreening::STATUS_NO_CV,
                'error' => null,
                'answers_text' => $answers,
                'facts' => ['salary' => $candidate['salary'], 'location' => null],
                'criteria_hash' => $plan['hash'],
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
            $evaluated = $this->screener->evaluate($plan['ad'], $plan['must_haves'], $cv['text'], $cv['images'], $candidate['answers'], [
                'office' => $plan['office'],
                'budget' => $plan['budget'],
                'salary' => $candidate['salary'],
                'profile_location' => $screening->candidate_location,
            ]);
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'HTTP 429')) {
                return 'throttled';
            }

            return str_contains($e->getMessage(), 'content filter')
                ? $this->fail($screening, $e->getMessage())
                : $this->retryLater($screening, $e->getMessage());
        }

        $result = $evaluated['result'];
        $location = $result->location;

        // With no office there is nothing to measure to, whatever the reply says.
        if ($plan['office'] === null) {
            $location['distance_km'] = null;
            $location['relocation_needed'] = null;
        }

        $screening->forceFill([
            'status' => RecruitmentScreening::STATUS_SCREENED,
            'attempts' => 0,
            'error' => null,
            'cv_text' => $cv['text'] !== '' ? $cv['text'] : null,
            'cv_pages' => $cv['pages'],
            'cv_read_as' => $cv['images'] !== [] ? 'images' : 'text',
            'answers_text' => $answers,
            'score' => $result->score,
            'fit' => $result->fit,
            'must_haves_met' => $result->mustHavesMet,
            'must_haves_total' => $result->mustHavesTotal,
            'evaluation' => $result->evaluation,
            'facts' => ['salary' => $candidate['salary'], 'location' => $location + ['office' => $plan['office_name']]],
            'criteria_hash' => $plan['hash'],
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
