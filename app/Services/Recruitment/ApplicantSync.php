<?php

namespace App\Services\Recruitment;

use App\Models\Recruitment\RecruitmentJob;
use App\Models\Recruitment\RecruitmentScreening;
use App\Services\Teamtailor\TeamtailorAnswers;
use App\Services\Teamtailor\TeamtailorApiService;
use App\Services\Teamtailor\TeamtailorJobLookups;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Everything Recruitment AI reads from Teamtailor: a job's ad, its applicants,
 * and one applicant's CV link, application answers and the salary those
 * answers state. Read-only.
 *
 * Applicants become screening rows. A new applicant is queued; one whose CV
 * changed in Teamtailor (`resume-updated-at`) is queued again; everyone else
 * keeps their screening and only has their stage and name refreshed.
 */
class ApplicantSync
{
    /** Teamtailor caps page[size] at 30. */
    private const PAGE_SIZE = 30;

    /** Hard ceiling so a pathological meta.page-count can't loop forever. */
    private const MAX_PAGES = 200;

    /** Heads, in the screening prompt, the answers not given to this job's own questions. */
    public const OTHER_ANSWERS_HEADING = 'Given in other applications, or to questions no longer on this job:';

    private TeamtailorJobLookups $jobs;

    public function __construct(private TeamtailorApiService $api)
    {
        $this->jobs = new TeamtailorJobLookups($api);
    }

    /** @return array{ad: JobAd, status: ?string} */
    public function fetchAd(RecruitmentJob $job): array
    {
        $data = $this->api->getJob($job->teamtailor_job_id)['data'] ?? [];

        return [
            'ad' => JobAd::fromTeamtailor($data),
            'status' => TeamtailorApiService::jobStatus($data['attributes'] ?? []),
        ];
    }

    /** @return list<string> the job's application questions (picked question ids), which tell its answers from a candidate's others */
    public function pickedQuestionIds(string $jobId): array
    {
        return $this->jobs->pickedQuestionIds($jobId);
    }

    /** @return array{applicants: int, new: int, rescreen: int} */
    public function syncApplicants(RecruitmentJob $job): array
    {
        $stageNames = $this->jobs->stageNames($job->teamtailor_job_id);
        $seen = [];
        $new = 0;
        $rescreen = 0;
        $page = 1;
        $pageCount = null;

        do {
            $body = $this->api->listJobApplicants($job->teamtailor_job_id, $page, self::PAGE_SIZE);
            $rows = $body['data'] ?? [];
            $included = collect($body['included'] ?? [])
                ->keyBy(fn ($resource) => ($resource['type'] ?? '').':'.($resource['id'] ?? ''));

            foreach ($rows as $candidate) {
                $applicant = self::applicant($candidate, $included, $job->teamtailor_job_id, $stageNames);

                if ($applicant === null || isset($seen[$applicant['teamtailor_candidate_id']])) {
                    continue;
                }

                $seen[$applicant['teamtailor_candidate_id']] = true;

                match ($this->upsert($job, $applicant)) {
                    'new' => $new++,
                    'rescreen' => $rescreen++,
                    default => null,
                };
            }

            $pageCount ??= (int) Arr::get($body, 'meta.page-count', 0);
            $page++;
            $more = $pageCount > 0 ? $page <= $pageCount : count($rows) === self::PAGE_SIZE;
        } while ($more && $page <= self::MAX_PAGES);

        $job->forceFill([
            'applicant_count' => count($seen),
            'applicants_synced_at' => now(),
            'sync_error' => null,
        ])->save();

        return ['applicants' => count($seen), 'new' => $new, 'rescreen' => $rescreen];
    }

    /**
     * One applicant's CV link (signed and short-lived, so fetched right before
     * reading), their application answers as text, and the salary they state.
     *
     * @param  list<string>  $pickedQuestionIds  the job's questions (pickedQuestionIds()); empty counts every answer as this job's
     * @param  ?string  $currency  the job's budget currency, for a salary that names none
     * @return array{resume: ?string, answers: string, salary: array{expected: ?array, current: ?array}} salary as SalaryAnswers::extract()
     */
    public function fetchCandidate(string $candidateId, array $pickedQuestionIds = [], ?string $currency = null): array
    {
        // `answers.question`, not `questions`: with include=answers,questions an
        // answer's question relationship carries only a link, so no answer can
        // be matched to its question (checked against the live API on
        // 2026-09-15: 15 answers, 0 matched). The nested include adds the id.
        // The includes are undocumented, so a param rejection falls back to the
        // answers alone, then to none — it must never stop the CV being read.
        foreach ([['answers', 'answers.question'], ['answers'], []] as $include) {
            try {
                $body = $this->api->getCandidate($candidateId, $include);
                break;
            } catch (\RuntimeException $e) {
                if ($include === [] || preg_match('/\((400|422)\)/', $e->getMessage()) !== 1) {
                    throw $e;
                }
            }
        }

        $attributes = $body['data']['attributes'] ?? [];
        $answers = self::answers($body, $pickedQuestionIds);

        return [
            'resume' => ($attributes['resume'] ?? null) ?: (($attributes['original-resume'] ?? null) ?: null),
            'answers' => self::answersText($answers),
            // This job's answers first: someone who applied twice may have asked two salaries.
            'salary' => SalaryAnswers::extract(array_merge(
                $answers['own'],
                array_map(fn (array $pair) => $pair + ['from' => 'another application'], $answers['other']),
            ), $currency),
        ];
    }

    /**
     * Flattens a candidate from /v1/jobs/{id}/candidates into screening columns,
     * resolving its application FOR THIS JOB from the side-loaded job-applications
     * (the same matching as the Jobs page: job link, else the only application,
     * else the newest).
     *
     * @param  array<string,mixed>  $candidate
     * @param  Collection<string,array<string,mixed>>  $included  keyed "type:id"
     * @param  array<string,string>  $stageNames  stage id => name
     * @return array<string,mixed>|null
     */
    public static function applicant(array $candidate, Collection $included, string $jobId, array $stageNames = []): ?array
    {
        $id = (string) ($candidate['id'] ?? '');

        if ($id === '') {
            return null;
        }

        $attributes = $candidate['attributes'] ?? [];

        $applications = [];
        foreach (Arr::get($candidate, 'relationships.job-applications.data', []) as $ref) {
            if ($application = $included->get('job-applications:'.($ref['id'] ?? ''))) {
                $applications[] = $application;
            }
        }

        $chosen = collect($applications)->first(fn ($application) => (string) Arr::get($application, 'relationships.job.data.id') === $jobId)
            ?? (count($applications) === 1 ? $applications[0] : null)
            ?? collect($applications)->sortByDesc(fn ($application) => (string) Arr::get($application, 'attributes.created-at'))->first();

        $name = trim(($attributes['first-name'] ?? '').' '.($attributes['last-name'] ?? ''));
        $location = collect([$attributes['city'] ?? null, $attributes['country'] ?? null])->filter()->implode(', ');
        $appliedAt = Arr::get($chosen ?? [], 'attributes.created-at', $attributes['created-at'] ?? null);
        $stageId = (string) Arr::get($chosen ?? [], 'relationships.stage.data.id', '');

        return [
            'teamtailor_candidate_id' => $id,
            'teamtailor_application_id' => isset($chosen['id']) ? (string) $chosen['id'] : null,
            'candidate_name' => $name !== '' ? mb_substr($name, 0, 200) : null,
            'candidate_email' => isset($attributes['email']) ? mb_substr((string) $attributes['email'], 0, 200) : null,
            'candidate_location' => $location !== '' ? mb_substr($location, 0, 200) : null,
            'linkedin_url' => ! empty($attributes['linkedin-url']) ? mb_substr((string) $attributes['linkedin-url'], 0, 500) : null,
            'applied_at' => $appliedAt ? Carbon::parse($appliedAt) : null,
            'stage' => $stageNames[$stageId] ?? null,
            'rejected' => ! empty(Arr::get($chosen ?? [], 'attributes.rejected-at')),
            'resume_updated_at' => isset($attributes['resume-updated-at']) ? (string) $attributes['resume-updated-at'] : null,
        ];
    }

    /**
     * The applicant's answers, split into those to this job's own questions and
     * the rest. Answers belong to the candidate, not to one application: each
     * names the picked question it answers. Without the job's picked questions
     * they cannot be told apart, so all count as this job's.
     *
     * @param  array<string,mixed>  $body  a candidate fetched with include=answers,answers.question
     * @param  list<string>  $pickedQuestionIds
     * @return array{own: list<array{question: ?string, answer: string}>, other: list<array{question: ?string, answer: string}>}
     */
    public static function answers(array $body, array $pickedQuestionIds = []): array
    {
        $included = collect($body['included'] ?? [])
            ->keyBy(fn ($resource) => ($resource['type'] ?? '').':'.($resource['id'] ?? ''));
        $picked = array_flip($pickedQuestionIds);
        $split = ['own' => [], 'other' => []];

        foreach ($included->where('type', 'answers') as $answer) {
            if (! $described = TeamtailorAnswers::describe($answer, $included)) {
                continue;
            }

            $ours = $picked === [] || isset($picked[(string) $described['picked_question_id']]);
            $split[$ours ? 'own' : 'other'][] = ['question' => $described['question'], 'answer' => $described['answer']];
        }

        return $split;
    }

    /**
     * The answers as "Q: … / A: …" pairs for the screening prompt: this job's,
     * then the rest under a heading.
     *
     * @param  array{own: list<array{question: ?string, answer: string}>, other: list<array{question: ?string, answer: string}>}  $answers  from answers()
     */
    public static function answersText(array $answers): string
    {
        $block = fn (array $pairs) => implode("\n\n", array_map(
            fn (array $pair) => ($pair['question'] !== null ? "Q: {$pair['question']}\n" : '').'A: '.$pair['answer'],
            $pairs,
        ));

        $parts = array_filter([
            $block($answers['own'] ?? []),
            ($answers['other'] ?? []) !== [] ? self::OTHER_ANSWERS_HEADING."\n\n".$block($answers['other']) : '',
        ], fn (string $part) => $part !== '');

        return implode("\n\n", $parts);
    }

    /** @param  array<string,mixed>  $applicant */
    private function upsert(RecruitmentJob $job, array $applicant): string
    {
        $screening = RecruitmentScreening::firstOrNew([
            'recruitment_job_id' => $job->id,
            'teamtailor_candidate_id' => $applicant['teamtailor_candidate_id'],
        ]);

        $isNew = ! $screening->exists;
        $cvChanged = ! $isNew
            && $applicant['resume_updated_at'] !== null
            && $screening->resume_updated_at !== $applicant['resume_updated_at'];

        $screening->fill(Arr::except($applicant, ['teamtailor_candidate_id']));

        if ($isNew) {
            $screening->status = RecruitmentScreening::STATUS_PENDING;
        } elseif ($cvChanged && $screening->status !== RecruitmentScreening::STATUS_PENDING) {
            $screening->forceFill(['status' => RecruitmentScreening::STATUS_PENDING, 'attempts' => 0, 'error' => null]);
        }

        $screening->save();

        return $isNew ? 'new' : ($cvChanged ? 'rescreen' : 'unchanged');
    }
}
