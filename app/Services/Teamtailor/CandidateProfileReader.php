<?php

namespace App\Services\Teamtailor;

use App\Services\Recruitment\JobAd;
use App\Services\Recruitment\SalaryAnswers;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/**
 * Everything the Candidates page shows about one person, read live from
 * Teamtailor: their details, every application with its stage, rejection,
 * cover letter and the questions asked with the answers given, the salary
 * those answers state, attachments, and the activity log. Read-only.
 *
 * Three quirks of the API shape it (checked against the live account on
 * 2026-09-15):
 * - Answers are the candidate's, not an application's. Each names the job's
 *   "picked question" it answers, and a job's picked questions are one call
 *   away: include=answers.picked-question.job is refused with HTTP 400.
 * - The candidate's own `activities` include carries no job and no user, so
 *   the log is read from /candidates/{id}/activities with include=job,user.
 * - An activity's `data` is a JSON string whose keys depend on its code.
 *
 * A job's stages and picked questions come from TeamtailorJobLookups, cached
 * for ten minutes: they change rarely, and every profile of that job's
 * applicants needs them.
 */
class CandidateProfileReader
{
    /** Teamtailor pages activities 30 at a time; the page shows the newest page. */
    public const ACTIVITY_PAGE_SIZE = 30;

    /** With "Show all activity": at most this many pages. */
    public const ACTIVITY_MAX_PAGES = 10;

    private const MESSAGE_CHARS = 5000;

    /** Largest first; the includes are undocumented, so a rejected one falls back to a smaller set. */
    private const PROFILE_INCLUDES = [
        ['job-applications', 'job-applications.job', 'job-applications.stage', 'job-applications.reject-reason', 'answers', 'answers.question', 'answers.picked-question', 'uploads'],
        ['job-applications', 'job-applications.job', 'job-applications.stage', 'answers', 'answers.question', 'answers.picked-question', 'uploads'],
        ['job-applications', 'job-applications.job', 'answers', 'answers.question'],
        ['job-applications'],
    ];

    public function __construct(
        private TeamtailorApiService $api,
        private TeamtailorJobLookups $jobs,
    ) {}

    /**
     * @return array{
     *     profile: array<string,mixed>,
     *     applications: list<array<string,mixed>>,
     *     other_answers: list<array{question: ?string, answer: string}>,
     *     salary: array{expected: ?array, current: ?array},
     *     uploads: list<array<string,mixed>>,
     *     activities: list<array<string,mixed>>,
     *     activities_total: ?int,
     *     activity_error: ?string,
     * }
     */
    public function read(string $candidateId, bool $allActivity = false): array
    {
        $body = $this->candidate($candidateId);
        $candidate = $body['data'] ?? [];
        $included = self::index($body['included'] ?? []);

        [$activityRows, $activityIncluded, $activitiesTotal, $activityError] = $this->activityRows($candidateId, $allActivity);
        $activityIndex = self::index($activityIncluded);

        $applicationJobIds = self::jobIds($included->where('type', 'job-applications')->all());

        $pickedByJob = [];
        foreach ($applicationJobIds as $jobId) {
            $pickedByJob[$jobId] = $this->jobs->pickedQuestionIds($jobId);
        }

        $stageNames = [];
        foreach (array_unique(array_merge($applicationJobIds, self::jobIds($activityRows))) as $jobId) {
            $stageNames += $this->jobs->stageNames($jobId);
        }

        $grouped = self::applications($candidate, $included, $pickedByJob);
        $lookups = $included->merge($activityIndex);

        return [
            'profile' => self::profile($candidate),
            'applications' => $grouped['applications'],
            'other_answers' => $grouped['other_answers'],
            'salary' => self::salary($grouped['applications'], $grouped['other_answers']),
            'uploads' => self::uploads($included),
            'activities' => array_map(fn (array $row) => self::activity($row, $lookups, $stageNames), $activityRows),
            'activities_total' => $activitiesTotal,
            'activity_error' => $activityError,
        ];
    }

    /** @return Collection<string, array<string,mixed>> JSON:API resources keyed "type:id" */
    public static function index(array $resources): Collection
    {
        return collect($resources)->keyBy(fn ($resource) => ($resource['type'] ?? '').':'.($resource['id'] ?? ''));
    }

    /** @param  array<string,mixed>  $candidate */
    public static function profile(array $candidate): array
    {
        $a = $candidate['attributes'] ?? [];
        $name = trim(($a['first-name'] ?? '').' '.($a['last-name'] ?? ''));
        $location = collect([$a['city'] ?? null, $a['country'] ?? null])
            ->map(fn ($part) => self::string($part))
            ->filter()
            ->implode(', ');

        return [
            'id' => isset($candidate['id']) ? (string) $candidate['id'] : null,
            'name' => $name !== '' ? $name : '—',
            'email' => self::string($a['email'] ?? null),
            'phone' => self::string($a['phone'] ?? null),
            'location' => $location !== '' ? $location : null,
            'linkedin' => self::link($a['linkedin-url'] ?? null),
            'resume' => self::link($a['resume'] ?? null),
            'original_resume' => self::link($a['original-resume'] ?? null),
            'pitch' => self::string($a['pitch'] ?? null),
            'resume_summary' => self::string($a['resume-summary'] ?? null),
            'tags' => is_array($a['tags'] ?? null) ? array_values(array_filter(array_map(fn ($tag) => self::string($tag), $a['tags']))) : [],
            'connected' => (bool) ($a['connected'] ?? false),
            'sourced' => (bool) ($a['sourced'] ?? false),
            'internal' => (bool) ($a['internal'] ?? false),
            'unsubscribed' => (bool) ($a['unsubscribed'] ?? false),
            'referring_site' => self::string($a['referring-site'] ?? null),
            'consent_future_jobs_at' => self::date($a['consent-future-jobs-at'] ?? null),
            'created_at' => self::date($a['created-at'] ?? null),
            'updated_at' => self::date($a['updated-at'] ?? null),
        ];
    }

    /**
     * The applications, newest first, each with the answers to its own job's
     * questions and the salary they state; answers to no application's
     * questions are returned apart.
     *
     * @param  array<string,mixed>  $candidate
     * @param  Collection<string, array<string,mixed>>  $included  keyed "type:id"
     * @param  array<string, list<string>>  $pickedByJob  job id => that job's picked question ids
     * @return array{applications: list<array<string,mixed>>, other_answers: list<array{question: ?string, answer: string}>}
     */
    public static function applications(array $candidate, Collection $included, array $pickedByJob): array
    {
        $answers = [];
        foreach ($included->where('type', 'answers') as $answer) {
            if ($described = TeamtailorAnswers::describe($answer, $included)) {
                $answers[] = $described;
            }
        }

        $claimed = [];
        $applications = [];

        foreach (Arr::get($candidate, 'relationships.job-applications.data', []) as $ref) {
            $application = $included->get('job-applications:'.($ref['id'] ?? ''));

            if (! $application) {
                continue;
            }

            $attributes = $application['attributes'] ?? [];
            $jobId = (string) Arr::get($application, 'relationships.job.data.id', '');
            $job = $included->get('jobs:'.$jobId);
            $stage = $included->get('stages:'.Arr::get($application, 'relationships.stage.data.id', ''));
            $reason = $included->get('reject-reasons:'.Arr::get($application, 'relationships.reject-reason.data.id', ''));
            $picked = array_flip($pickedByJob[$jobId] ?? []);

            $own = [];
            foreach ($answers as $i => $answer) {
                if ($answer['picked_question_id'] !== null && isset($picked[$answer['picked_question_id']])) {
                    $own[] = ['question' => $answer['question'], 'answer' => $answer['answer']];
                    $claimed[$i] = true;
                }
            }

            $applications[] = [
                'id' => (string) ($application['id'] ?? ''),
                'job_id' => $jobId !== '' ? $jobId : null,
                'job_title' => $job ? (self::string(Arr::get($job, 'attributes.title')) ?? self::string(Arr::get($job, 'attributes.internal-name'))) : null,
                'applied_at' => self::date($attributes['created-at'] ?? null),
                'stage' => $stage ? self::string(Arr::get($stage, 'attributes.name')) : null,
                'changed_stage_at' => self::date($attributes['changed-stage-at'] ?? null),
                'rejected' => ! empty($attributes['rejected-at']),
                'rejected_at' => self::date($attributes['rejected-at'] ?? null),
                'reject_reason' => $reason ? self::string(Arr::get($reason, 'attributes.reason')) : null,
                'rejected_by_company' => $reason ? Arr::get($reason, 'attributes.rejected-by-company') : null,
                'cover_letter' => self::string($attributes['cover-letter'] ?? null),
                'referring_site' => self::string($attributes['referring-site'] ?? null),
                'sourced' => (bool) ($attributes['sourced'] ?? false),
                'answers' => $own,
                'salary' => SalaryAnswers::extract($own),
            ];
        }

        usort($applications, fn (array $x, array $y) => ($y['applied_at']?->getTimestamp() ?? 0) <=> ($x['applied_at']?->getTimestamp() ?? 0));

        $other = [];
        foreach ($answers as $i => $answer) {
            if (! isset($claimed[$i])) {
                $other[] = ['question' => $answer['question'], 'answer' => $answer['answer']];
            }
        }

        return ['applications' => $applications, 'other_answers' => $other];
    }

    /**
     * The salary the answers state, for the top of the profile: the newest
     * application's answers first, then older ones, then answers tied to none.
     * A figure from an application says which job it was given for.
     *
     * @param  list<array<string,mixed>>  $applications  from applications(), newest first
     * @param  list<array{question: ?string, answer: string}>  $otherAnswers
     * @return array{expected: ?array, current: ?array} as SalaryAnswers::extract()
     */
    public static function salary(array $applications, array $otherAnswers): array
    {
        $pairs = [];

        foreach ($applications as $application) {
            foreach ($application['answers'] as $answer) {
                $pairs[] = $answer + ['from' => $application['job_title']];
            }
        }

        return SalaryAnswers::extract(array_merge($pairs, $otherAnswers));
    }

    /**
     * @param  Collection<string, array<string,mixed>>  $included  keyed "type:id"
     * @return list<array{name: string, url: ?string, internal: bool, created_at: ?CarbonImmutable}>
     */
    public static function uploads(Collection $included): array
    {
        return $included->where('type', 'uploads')
            ->map(fn (array $upload) => [
                'name' => self::string(Arr::get($upload, 'attributes.file-name')) ?? 'Attachment',
                'url' => self::link(Arr::get($upload, 'attributes.url')),
                'internal' => (bool) Arr::get($upload, 'attributes.internal', false),
                'created_at' => self::date(Arr::get($upload, 'attributes.created-at')),
            ])
            ->values()
            ->all();
    }

    /**
     * One entry of the activity log, labelled for people. A code not known here
     * still shows, named after the code, with its short fields.
     *
     * @param  array<string,mixed>  $row
     * @param  Collection<string, array<string,mixed>>  $included  keyed "type:id", with the activities' jobs and users
     * @param  array<string,string>  $stageNames  stage id => name
     * @return array{at: ?CarbonImmutable, code: string, label: string, job: ?string, user: ?string, automatic: bool, details: list<string>, body: ?string}
     */
    public static function activity(array $row, Collection $included, array $stageNames = []): array
    {
        $attributes = $row['attributes'] ?? [];
        $code = (string) ($attributes['code'] ?? '');
        $data = $attributes['data'] ?? null;
        $data = is_string($data) ? json_decode($data, true) : $data;
        $data = is_array($data) ? $data : [];

        $jobId = (string) Arr::get($row, 'relationships.job.data.id', '');
        $job = $included->get('jobs:'.$jobId);
        $user = $included->get('users:'.Arr::get($row, 'relationships.user.data.id', ''));

        $stage = fn ($id) => is_scalar($id) && (string) $id !== '' ? ($stageNames[(string) $id] ?? null) : null;
        $meeting = self::string($data['meeting_event_summary'] ?? null);
        $withMeeting = fn (string $label) => $meeting !== null ? "{$label}: {$meeting}" : $label;
        $details = [];
        $body = null;

        switch ($code) {
            case 'created':
                $label = $jobId !== '' ? 'Applied' : 'Added to Teamtailor';
                break;
            case 'stage':
                $from = $stage($data['from'] ?? null);
                $to = $stage($data['to'] ?? null);
                $label = match (true) {
                    $from !== null && $to !== null => "Moved from {$from} to {$to}",
                    $to !== null => "Moved to {$to}",
                    default => 'Moved to another stage',
                };
                break;
            case 'rejected':
                $at = $stage($data['stage_id'] ?? null);
                $label = $at !== null ? "Rejected at {$at}" : 'Rejected';
                break;
            case 'message':
                $subject = self::string($data['subject'] ?? null);
                $label = $subject !== null ? "Message: {$subject}" : 'Message';
                foreach (['from' => 'From', 'to' => 'To'] as $key => $name) {
                    if ($value = self::listText($data[$key] ?? null)) {
                        $details[] = "{$name}: {$value}";
                    }
                }
                $text = self::string($data['body'] ?? null) ?? self::string($data['message'] ?? null);
                $body = $text !== null ? mb_substr(JobAd::plain($text), 0, self::MESSAGE_CHARS) : null;
                break;
            case 'meeting_event_candidate_invited':
                $label = $withMeeting('Invited to a meeting');
                if ($when = self::meetingTime($data)) {
                    $details[] = "When: {$when}";
                }
                break;
            case 'meeting_event_candidate_status_changed':
                $reply = self::string($data['meeting_event_invite_status'] ?? null);
                $label = $withMeeting('Meeting reply'.($reply !== null ? ' - '.str_replace('_', ' ', $reply) : ''));
                break;
            case 'meeting_event_cancelled':
                $label = $withMeeting('Meeting cancelled');
                break;
            case 'meeting_event_sent_self_schedule':
                $label = $withMeeting('Sent a link to book a meeting');
                break;
            case 'note':
                // The only way a recruiter's note reaches the API: /candidates/{id}/notes is a 404.
                $label = 'Note';
                $text = self::string($data['note'] ?? null);
                $body = $text !== null ? mb_substr(JobAd::plain($text), 0, self::MESSAGE_CHARS) : null;
                break;
            case 'sourced':
                $label = 'Added as a sourced candidate';
                break;
            case 'resume_uploaded':
                $label = 'CV uploaded';
                break;
            case 'consent_extended':
                $label = 'Consent to be considered for future jobs extended';
                break;
            case 'connected':
                $label = 'Connected with the company';
                break;
            case 'copilot_resume_summary':
                $label = 'Teamtailor AI summarised the CV';
                break;
            case 'copilot_resume_timeline':
                $label = 'Teamtailor AI built a CV timeline';
                break;
            default:
                $label = ucfirst(str_replace(['_', '-'], ' ', $code !== '' ? $code : 'activity'));
                foreach ($data as $key => $value) {
                    if ($key === 'from_trigger' || ! is_scalar($value) || mb_strlen((string) $value) > 120 || (string) $value === '') {
                        continue;
                    }
                    $details[] = ucfirst(str_replace('_', ' ', (string) $key)).': '.(is_bool($value) ? ($value ? 'yes' : 'no') : $value);
                    if (count($details) >= 4) {
                        break;
                    }
                }
        }

        return [
            'at' => self::date($attributes['created-at'] ?? null),
            'code' => $code,
            'label' => $label,
            'job' => $job ? (self::string(Arr::get($job, 'attributes.title')) ?? self::string(Arr::get($job, 'attributes.internal-name'))) : null,
            'user' => $user ? (self::string(Arr::get($user, 'attributes.name')) ?? self::string(Arr::get($user, 'attributes.email'))) : null,
            'automatic' => (bool) ($data['from_trigger'] ?? false),
            'details' => $details,
            'body' => $body !== '' ? $body : null,
        ];
    }

    /** @return array<string,mixed> the candidate, with the largest include set Teamtailor accepts */
    private function candidate(string $id): array
    {
        $last = array_key_last(self::PROFILE_INCLUDES);

        foreach (self::PROFILE_INCLUDES as $i => $include) {
            try {
                return $this->api->getCandidate($id, $include);
            } catch (\RuntimeException $e) {
                if ($i === $last || preg_match('/\((400|422)\)/', $e->getMessage()) !== 1) {
                    throw $e;
                }
            }
        }

        return $this->api->getCandidate($id);
    }

    /**
     * The newest page of the log, or every page with "Show all activity". A
     * failure here costs only the log: the rest of the profile still shows.
     *
     * @return array{0: list<array<string,mixed>>, 1: list<array<string,mixed>>, 2: ?int, 3: ?string} rows, included, total, error
     */
    private function activityRows(string $candidateId, bool $all): array
    {
        $rows = [];
        $included = [];
        $total = null;

        try {
            for ($page = 1; $page <= ($all ? self::ACTIVITY_MAX_PAGES : 1); $page++) {
                $body = $this->api->listCandidateActivities($candidateId, $page, self::ACTIVITY_PAGE_SIZE, ['job', 'user']);
                $batch = $body['data'] ?? [];
                $rows = array_merge($rows, $batch);
                $included = array_merge($included, $body['included'] ?? []);
                $total ??= isset($body['meta']['record-count']) ? (int) $body['meta']['record-count'] : null;

                if (count($batch) < self::ACTIVITY_PAGE_SIZE) {
                    break;
                }
            }
        } catch (\Throwable $e) {
            return [$rows, $included, $total, $e->getMessage()];
        }

        return [$rows, $included, $total, null];
    }

    /** @param  iterable<array<string,mixed>>  $resources  anything with a job relationship */
    private static function jobIds(iterable $resources): array
    {
        $ids = [];

        foreach ($resources as $resource) {
            $id = (string) Arr::get($resource, 'relationships.job.data.id', '');
            if ($id !== '') {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /** @param  array<string,mixed>  $data  a meeting activity's data */
    private static function meetingTime(array $data): ?string
    {
        $starts = self::date($data['meeting_event_starts_at'] ?? null);

        if (! $starts) {
            return null;
        }

        $zone = self::string($data['meeting_event_tzid'] ?? null);
        $ends = self::date($data['meeting_event_ends_at'] ?? null);

        try {
            if ($zone !== null) {
                $starts = $starts->setTimezone($zone);
                $ends = $ends?->setTimezone($zone);
            }
        } catch (\Throwable) {
            $zone = null;
        }

        return $starts->format('d M Y, H:i').($ends ? '-'.$ends->format('H:i') : '').($zone !== null ? " ({$zone})" : '');
    }

    private static function listText(mixed $value): ?string
    {
        if (is_array($value)) {
            $parts = array_filter(array_map(fn ($item) => is_array($item) ? self::string($item['email'] ?? $item['name'] ?? null) : self::string($item), $value));

            return $parts === [] ? null : implode(', ', $parts);
        }

        return self::string($value);
    }

    private static function string(mixed $value): ?string
    {
        if (! is_scalar($value) || is_bool($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    /** Only an http(s) link is ever put in an href: these are values people typed. */
    private static function link(mixed $value): ?string
    {
        $url = self::string($value);

        return $url !== null && preg_match('#^https?://#i', $url) ? $url : null;
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        $text = self::string($value);

        if ($text === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse($text);
        } catch (\Throwable) {
            return null;
        }
    }
}
