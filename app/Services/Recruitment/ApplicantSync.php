<?php

namespace App\Services\Recruitment;

use App\Models\Recruitment\RecruitmentJob;
use App\Models\Recruitment\RecruitmentScreening;
use App\Services\Teamtailor\TeamtailorApiService;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Everything Recruitment AI reads from Teamtailor: a job's ad, its applicants,
 * and one applicant's CV link and application answers. Read-only.
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

    public function __construct(private TeamtailorApiService $api) {}

    /** @return array{ad: JobAd, status: ?string} */
    public function fetchAd(RecruitmentJob $job): array
    {
        $data = $this->api->getJob($job->teamtailor_job_id)['data'] ?? [];

        return [
            'ad' => JobAd::fromTeamtailor($data),
            'status' => isset($data['attributes']['status']) ? (string) $data['attributes']['status'] : null,
        ];
    }

    /** @return array{applicants: int, new: int, rescreen: int} */
    public function syncApplicants(RecruitmentJob $job): array
    {
        $stageNames = $this->stageNames($job->teamtailor_job_id);
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
     * reading) and their application answers as text.
     *
     * @return array{resume: ?string, answers: string}
     */
    public function fetchCandidate(string $candidateId): array
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

        return [
            'resume' => ($attributes['resume'] ?? null) ?: (($attributes['original-resume'] ?? null) ?: null),
            'answers' => self::answersText($body),
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
     * The applicant's answers as "Q: … / A: …" pairs, questions from the
     * side-loaded `questions` matched to each answer.
     *
     * @param  array<string,mixed>  $body  a candidate fetched with include=answers,questions
     */
    public static function answersText(array $body): string
    {
        $included = collect($body['included'] ?? []);
        $questions = $included->where('type', 'questions')->keyBy('id');
        $pairs = [];

        foreach ($included->where('type', 'answers') as $answer) {
            $value = self::answerValue($answer['attributes'] ?? []);

            if ($value === '') {
                continue;
            }

            $questionId = (string) Arr::get($answer, 'relationships.question.data.id', '');
            $title = trim((string) Arr::get($questions->get($questionId, []), 'attributes.title', ''));

            $pairs[] = ($title !== '' ? "Q: {$title}\n" : '').'A: '.$value;
        }

        return implode("\n\n", $pairs);
    }

    /** @param  array<string,mixed>  $attributes  an answer resource's attributes; the field used depends on question-type */
    private static function answerValue(array $attributes): string
    {
        foreach (['text', 'answer'] as $key) {
            if (is_scalar($attributes[$key] ?? null) && trim((string) $attributes[$key]) !== '') {
                return trim((string) $attributes[$key]);
            }
        }

        if (is_bool($attributes['boolean'] ?? null)) {
            return $attributes['boolean'] ? 'Yes' : 'No';
        }

        if (is_numeric($attributes['number'] ?? null)) {
            return (string) $attributes['number'];
        }

        if (! empty($attributes['date']) && is_scalar($attributes['date'])) {
            return (string) $attributes['date'];
        }

        foreach (['choices', 'range', 'data'] as $key) {
            $value = $attributes[$key] ?? null;

            if (is_array($value) && $value !== []) {
                $parts = array_map(fn ($item) => is_array($item) ? (string) ($item['text'] ?? $item['title'] ?? json_encode($item)) : (string) $item, $value);

                return implode(', ', array_filter($parts, fn (string $part) => trim($part) !== ''));
            }

            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return '';
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

    /** @return array<string,string> stage id => name; empty when Teamtailor refuses (stages are optional) */
    private function stageNames(string $jobId): array
    {
        try {
            $names = [];

            foreach ($this->api->listJobStages($jobId)['data'] ?? [] as $stage) {
                if (isset($stage['attributes']['name'])) {
                    $names[(string) ($stage['id'] ?? '')] = (string) $stage['attributes']['name'];
                }
            }

            return $names;
        } catch (\Throwable) {
            return [];
        }
    }
}
