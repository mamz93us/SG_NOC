<?php

namespace App\Services\Recruitment;

use App\Models\Recruitment\RecruitmentJob;
use App\Models\Recruitment\RecruitmentScreening;
use App\Models\User;

/**
 * Recruitment AI's chat tools, shared by the Samir AI Assistant (for people who
 * hold use-recruitment-ai) and the "Ask about these candidates" panel on a
 * job's Recruitment AI page (scoped to that job).
 *
 * Read-only, and only over what screening stored: nothing here calls Teamtailor
 * or Azure. The permission is checked on every call, not just when the tools
 * are offered, so a stale conversation cannot keep using them after access is
 * taken away.
 */
class RecruitmentToolbox
{
    public const PERMISSION = 'use-recruitment-ai';

    public const TOOLS = ['list_recruitment_jobs', 'get_job_shortlist', 'get_candidate_details', 'search_candidates'];

    private const SHORTLIST_MAX = 25;

    private const SEARCH_MAX = 25;

    /** A CV runs 3,000-10,000 characters; this keeps one call inside a chat turn. */
    private const CV_CHARS = 20000;

    public function __construct(
        private User $user,
        private ?RecruitmentJob $scope = null,
    ) {}

    public function enabled(): bool
    {
        return $this->user->hasPermission(self::PERMISSION);
    }

    public function handles(string $name): bool
    {
        return in_array($name, self::TOOLS, true);
    }

    /** @return list<array<string,mixed>> OpenAI-shaped function tools; none without the permission */
    public function definitions(): array
    {
        if (! $this->enabled()) {
            return [];
        }

        $job = ['job' => ['type' => 'string', 'description' => $this->scope
            ? 'Optional - defaults to the job this page is about. Otherwise a job title (or part of one) or Teamtailor job id.'
            : 'The job: its title (or part of it) or Teamtailor job id, from list_recruitment_jobs.']];

        return [
            $this->def('list_recruitment_jobs',
                'List the Teamtailor jobs set up in Recruitment AI: title, Teamtailor job id, whether AI screening is on, applicants, how many are screened or still waiting, and the best score. Call it first when the employee has not named a job.',
                [], []),
            $this->def('get_job_shortlist',
                'The best applicants for one job, ranked by the AI screening of each CV against the job ad and the recruiter\'s must-haves: score, fit, which must-haves each meets, a summary, strengths and concerns. Use it for "best 10", "top candidates", "who should we interview".',
                $job + [
                    'limit' => ['type' => 'integer', 'description' => 'How many, 1 to 25. Defaults to 10.'],
                    'min_score' => ['type' => 'integer', 'description' => 'Optional - only applicants scoring at least this (0-100).'],
                    'include_rejected' => ['type' => 'boolean', 'description' => 'Optional - also list applications already rejected in Teamtailor. Defaults to false.'],
                ],
                $this->scope ? [] : ['job']),
            $this->def('get_candidate_details',
                'Everything screened for one applicant of a job: the full AI evaluation (each must-have with evidence, strengths, concerns, skills, languages, education, suggested interview questions), their answers to the application questions, and the text of their CV. Use it to analyse, explain or compare specific candidates.',
                $job + ['candidate' => ['type' => 'string', 'description' => 'The applicant: the candidate_ref from get_job_shortlist or search_candidates (e.g. C42), or their name or email.']],
                $this->scope ? ['candidate'] : ['job', 'candidate']),
            $this->def('search_candidates',
                'Find a job\'s screened applicants whose CV, answers or evaluation mention something - a skill, tool, certificate, language, employer or title (e.g. "SAP", "IFRS", "forklift licence"). Every word must appear. Returns each match with a snippet and their score.',
                $job + ['query' => ['type' => 'string', 'description' => 'The words to look for.']],
                $this->scope ? ['query'] : ['job', 'query']),
        ];
    }

    /** @return array<string,mixed> */
    public function call(string $name, array $args): array
    {
        if (! $this->enabled()) {
            return ['error' => 'This employee may not use Recruitment AI. An administrator grants it on AI > AI Access in the NOC.'];
        }

        return match ($name) {
            'list_recruitment_jobs' => $this->listJobs(),
            'get_job_shortlist' => $this->shortlist($args),
            'get_candidate_details' => $this->candidateDetails($args),
            'search_candidates' => $this->search($args),
            default => ['error' => "Unknown tool: {$name}"],
        };
    }

    private function listJobs(): array
    {
        $jobs = RecruitmentJob::orderByDesc('screening_enabled')->orderBy('title')->get();

        if ($jobs->isEmpty()) {
            return ['jobs' => [], 'note' => 'No job is set up yet. AI screening is switched on per job in the NOC, under AI > Recruitment AI.'];
        }

        return ['jobs' => $jobs->map(fn (RecruitmentJob $job) => $this->jobHeader($job))->values()->all()];
    }

    private function shortlist(array $args): array
    {
        $job = $this->job($args);

        if (is_array($job)) {
            return $job;
        }

        $limit = (int) ($args['limit'] ?? 10);
        $limit = $limit < 1 ? 10 : min(self::SHORTLIST_MAX, $limit);
        $includeRejected = filter_var($args['include_rejected'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $minScore = is_numeric($args['min_score'] ?? null) ? max(0, min(100, (int) $args['min_score'])) : null;

        $ranked = $job->screenings()
            ->ranked($includeRejected)
            ->when($minScore !== null, fn ($query) => $query->where('score', '>=', $minScore))
            ->limit($limit)
            ->get();

        return [
            'job' => $this->jobHeader($job),
            'ranked_by' => 'The AI screening of each CV against the job ad and the recruiter\'s must-haves. A must-have the CV clearly does not meet caps the score at '.ScreeningResult::CAP_WHEN_MISSING.'.',
            'must_haves' => $job->mustHaveList(),
            'candidates' => $ranked->values()->map(fn (RecruitmentScreening $screening, int $i) => ['rank' => $i + 1] + $this->brief($screening))->all(),
            'notes' => $this->notes($job, $includeRejected),
        ];
    }

    private function candidateDetails(array $args): array
    {
        $job = $this->job($args);

        if (is_array($job)) {
            return $job;
        }

        $screening = $this->candidate($job, (string) ($args['candidate'] ?? ''));

        if (is_array($screening)) {
            return $screening;
        }

        $cv = (string) $screening->cv_text;

        return [
            'job' => ['job_id' => $job->teamtailor_job_id, 'title' => $job->title],
            'candidate' => $this->brief($screening) + [
                'email' => $screening->candidate_email,
                'location' => $screening->candidate_location,
                'linkedin' => $screening->linkedin_url,
            ],
            'evaluation' => $screening->status === RecruitmentScreening::STATUS_SCREENED ? $screening->evaluation : null,
            'not_screened_because' => match ($screening->status) {
                RecruitmentScreening::STATUS_PENDING => 'still waiting to be screened',
                RecruitmentScreening::STATUS_NO_CV => 'there is no CV in Teamtailor',
                RecruitmentScreening::STATUS_FAILED => 'the CV could not be read: '.$screening->error,
                default => null,
            },
            'application_answers' => $screening->answers_text,
            'cv_text' => $cv !== '' ? mb_substr($cv, 0, self::CV_CHARS) : null,
            'cv_note' => match (true) {
                $screening->cv_read_as === 'images' => 'This CV is a scan and was read from images of its pages, so its content is only in the evaluation.',
                mb_strlen($cv) > self::CV_CHARS => 'The CV text was cut to fit.',
                default => null,
            },
        ];
    }

    private function search(array $args): array
    {
        $job = $this->job($args);

        if (is_array($job)) {
            return $job;
        }

        $query = trim((string) ($args['query'] ?? ''));
        $terms = array_values(array_filter(
            preg_split('/[\s,]+/u', mb_strtolower($query)) ?: [],
            fn (string $term) => mb_strlen($term) >= 2,
        ));

        if ($terms === []) {
            return ['error' => 'Give the words to look for, for example SAP or IFRS.'];
        }

        $matches = [];

        foreach ($job->screenings()->where('status', RecruitmentScreening::STATUS_SCREENED)->orderByDesc('score')->get() as $screening) {
            $evaluation = json_encode($screening->evaluation, JSON_UNESCAPED_UNICODE) ?: '';
            $haystack = mb_strtolower($screening->cv_text."\n".$screening->answers_text."\n".$evaluation);

            if (! collect($terms)->every(fn (string $term) => str_contains($haystack, $term))) {
                continue;
            }

            $matches[] = $this->brief($screening, withChecks: false)
                + ['snippet' => self::snippet($screening->cv_text."\n".$evaluation, $terms[0])];

            if (count($matches) >= self::SEARCH_MAX) {
                break;
            }
        }

        return [
            'job' => ['job_id' => $job->teamtailor_job_id, 'title' => $job->title],
            'query' => $query,
            'matches' => $matches,
            'note' => $matches === []
                ? 'No screened applicant of this job mentions all of these words.'
                : 'Best score first. Only screened applicants are searched.',
        ];
    }

    /** @return RecruitmentJob|array{error:string} */
    private function job(array $args): RecruitmentJob|array
    {
        $query = trim((string) ($args['job'] ?? ''));

        if ($query === '') {
            return $this->scope ?? ['error' => 'Say which job - call list_recruitment_jobs to see them.'];
        }

        $jobs = RecruitmentJob::orderBy('title')->get();
        $needle = mb_strtolower($query);

        $exact = $jobs->first(fn (RecruitmentJob $job) => $job->teamtailor_job_id === $query || mb_strtolower((string) $job->title) === $needle);

        if ($exact) {
            return $exact;
        }

        $matches = $jobs->filter(fn (RecruitmentJob $job) => $job->title && str_contains(mb_strtolower($job->title), $needle))->values();

        return match ($matches->count()) {
            1 => $matches->first(),
            0 => ['error' => "No job matching \"{$query}\" is set up in Recruitment AI. AI screening is switched on per job in the NOC, under AI > Recruitment AI."],
            default => [
                'error' => "More than one job matches \"{$query}\" - ask which one.",
                'matches' => $matches->map(fn (RecruitmentJob $job) => ['job_id' => $job->teamtailor_job_id, 'title' => $job->title])->all(),
            ],
        };
    }

    /** @return RecruitmentScreening|array{error:string} */
    private function candidate(RecruitmentJob $job, string $query): RecruitmentScreening|array
    {
        $query = trim($query);

        if ($query === '') {
            return ['error' => 'Say which applicant: a candidate_ref from get_job_shortlist, or a name or email.'];
        }

        if (preg_match('/^C?(\d+)$/i', $query, $m) && ($screening = $job->screenings()->whereKey((int) $m[1])->first())) {
            return $screening;
        }

        $needle = mb_strtolower($query);
        $matches = $job->screenings()
            ->get(['id', 'candidate_name', 'candidate_email', 'score'])
            ->filter(fn (RecruitmentScreening $screening) => str_contains(mb_strtolower((string) $screening->candidate_name), $needle)
                || mb_strtolower((string) $screening->candidate_email) === $needle)
            ->values();

        return match ($matches->count()) {
            1 => $job->screenings()->whereKey($matches->first()->id)->first(),
            0 => ['error' => "No applicant of {$job->title} matches \"{$query}\"."],
            default => [
                'error' => "More than one applicant of {$job->title} matches \"{$query}\" - ask which one.",
                'matches' => $matches->take(10)->map(fn (RecruitmentScreening $screening) => [
                    'candidate_ref' => 'C'.$screening->id,
                    'name' => $screening->candidate_name,
                    'score' => $screening->score,
                ])->all(),
            ],
        };
    }

    private function jobHeader(RecruitmentJob $job): array
    {
        $progress = $job->progress();

        return [
            'job_id' => $job->teamtailor_job_id,
            'title' => $job->title,
            'teamtailor_status' => $job->job_status,
            'ai_screening' => $job->screening_enabled ? 'on' : 'off',
            'applicants' => $job->applicant_count,
            'screened' => $progress['screened'],
            'waiting_to_be_screened' => $progress['pending'],
            'without_cv' => $progress['no_cv'],
            'could_not_be_read' => $progress['failed'],
            'best_score' => $job->screenings()->where('status', RecruitmentScreening::STATUS_SCREENED)->max('score'),
        ];
    }

    private function brief(RecruitmentScreening $screening, bool $withChecks = true): array
    {
        $brief = [
            'candidate_ref' => 'C'.$screening->id,
            'name' => $screening->candidate_name,
            'score' => $screening->score,
            'fit' => $screening->fitLabel(),
            'must_haves_met' => $screening->must_haves_total ? "{$screening->must_haves_met} of {$screening->must_haves_total}" : null,
            'summary' => $screening->evaluationValue('summary'),
            'current_role' => $screening->evaluationValue('current_role'),
            'relevant_years' => $screening->evaluationValue('relevant_years'),
            'stage' => $screening->rejected ? 'Rejected' : ($screening->stage ?? 'Active'),
            'applied' => $screening->applied_at?->toDateString(),
        ];

        if ($withChecks) {
            $brief['must_haves'] = array_map(
                fn (array $check) => $check['requirement'].': '.$check['met'],
                (array) $screening->evaluationValue('must_haves', []),
            );
            $brief['strengths'] = array_slice((array) $screening->evaluationValue('strengths', []), 0, 3);
            $brief['concerns'] = array_slice((array) $screening->evaluationValue('concerns', []), 0, 3);
        }

        return $brief;
    }

    /** @return list<string> */
    private function notes(RecruitmentJob $job, bool $includeRejected): array
    {
        $progress = $job->progress();
        $notes = [];

        if (! $job->screening_enabled) {
            $notes[] = 'AI screening is switched off for this job, so new applicants are not being screened.';
        }
        if ($progress['pending'] > 0) {
            $notes[] = "{$progress['pending']} applicants are still waiting to be screened, so this ranking can still change.";
        }
        if ($progress['no_cv'] > 0) {
            $notes[] = "{$progress['no_cv']} applicants have no CV in Teamtailor and are not ranked.";
        }
        if ($progress['failed'] > 0) {
            $notes[] = "{$progress['failed']} CVs could not be read and are not ranked.";
        }
        if (! $includeRejected) {
            $notes[] = 'Applications already rejected in Teamtailor are left out.';
        }

        return $notes;
    }

    private static function snippet(string $text, string $term): string
    {
        $flat = (string) preg_replace('/\s+/u', ' ', $text);
        $at = mb_stripos($flat, $term);

        if ($at === false) {
            return mb_substr($flat, 0, 200);
        }

        $start = max(0, $at - 80);

        return ($start > 0 ? '…' : '').mb_substr($flat, $start, 240).'…';
    }

    private function def(string $name, string $description, array $properties, array $required): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $name,
                'description' => $description,
                'parameters' => [
                    'type' => 'object',
                    // An object even when empty: Azure rejects `[]` here (see AssistantToolbox::def).
                    'properties' => (object) $properties,
                    'required' => $required,
                ],
            ],
        ];
    }
}
