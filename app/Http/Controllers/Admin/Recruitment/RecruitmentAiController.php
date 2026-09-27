<?php

namespace App\Http\Controllers\Admin\Recruitment;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiSetting;
use App\Models\Branch;
use App\Models\Recruitment\RecruitmentJob;
use App\Models\Recruitment\RecruitmentScreening;
use App\Services\Recruitment\JobAd;
use App\Services\Recruitment\RecruitmentAgent;
use App\Services\Teamtailor\TeamtailorApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * AI ▸ Recruitment AI: switch AI screening on for a Teamtailor job, set its
 * must-haves, office and salary budget, see the ranked applicants, and ask
 * about them.
 *
 * Nothing slow happens here — recruitment:screen reads the CVs — and nothing
 * is written to Teamtailor. Every action that changes what is screened, or
 * deletes screening data, is logged by hand (the models are out of the
 * automatic audit because they hold CV text).
 */
class RecruitmentAiController extends Controller
{
    /** Columns the page lists; never the CV text or the answers. */
    private const LIST_COLUMNS = [
        'id', 'recruitment_job_id', 'teamtailor_candidate_id', 'candidate_name', 'candidate_email',
        'candidate_location', 'linkedin_url', 'applied_at', 'stage', 'rejected', 'status', 'error',
        'score', 'fit', 'must_haves_met', 'must_haves_total', 'evaluation', 'facts', 'cv_read_as', 'screened_at',
    ];

    /** Every Teamtailor job, closed ones included, narrowed to one status by ?status=. */
    public function index(Request $request, TeamtailorApiService $teamtailor): View
    {
        $configured = $teamtailor->isConfigured();
        $error = null;
        $jobs = collect();
        $status = in_array($request->query('status'), TeamtailorApiService::JOB_STATUSES, true)
            ? $request->query('status')
            : 'all';

        if ($configured) {
            try {
                $page = 1;

                do {
                    $rows = $teamtailor->listJobs([], $page, 30, '-created-at')['data'] ?? [];
                    foreach ($rows as $row) {
                        $jobs->push([
                            'id' => (string) $row['id'],
                            'title' => $row['attributes']['title'] ?? ($row['attributes']['internal-name'] ?? '—'),
                            'status' => TeamtailorApiService::jobStatus($row['attributes'] ?? []),
                        ]);
                    }
                    $page++;
                } while (count($rows) === 30 && $page <= 10);
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        $local = RecruitmentJob::query()
            ->withCount([
                'screenings as screened_count' => fn ($query) => $query->where('status', RecruitmentScreening::STATUS_SCREENED),
                'screenings as pending_count' => fn ($query) => $query->where('status', RecruitmentScreening::STATUS_PENDING),
            ])
            ->withMax(['screenings as best_score' => fn ($query) => $query->where('status', RecruitmentScreening::STATUS_SCREENED)], 'score')
            ->get()
            ->keyBy('teamtailor_job_id');

        // A job set up here that Teamtailor did not list (deleted there, or older
        // than the 300 read) still shows, so its data can be deleted. Its last
        // known status is shown only when the list could not be read.
        $listed = $configured && $error === null;

        foreach ($local as $id => $job) {
            if (! $jobs->contains('id', (string) $id)) {
                $jobs->push([
                    'id' => (string) $id,
                    'title' => $job->title ?? '—',
                    'status' => $listed ? 'not listed' : (TeamtailorApiService::jobStatus(['status' => $job->job_status]) ?? 'not listed'),
                ]);
            }
        }

        $counts = ['all' => $jobs->count()] + $jobs->countBy('status')->all();

        if ($status !== 'all') {
            $jobs = $jobs->where('status', $status)->values();
        }

        return view('admin.recruitment-ai.index', compact('jobs', 'local', 'configured', 'error', 'status', 'counts'));
    }

    public function show(Request $request, TeamtailorApiService $teamtailor, string $job): View
    {
        $recruitmentJob = RecruitmentJob::firstOrNew(['teamtailor_job_id' => $job]);
        $ad = null;
        $adError = null;

        try {
            $ad = JobAd::fromTeamtailor($teamtailor->getJob($job)['data'] ?? []);
        } catch (\Throwable $e) {
            $adError = $e->getMessage();
        }

        // Rejected applications rank too unless left out: a recruiter may want
        // back someone Teamtailor already turned down.
        $includeRejected = ! $request->boolean('hide_rejected');
        $exists = $recruitmentJob->exists;

        return view('admin.recruitment-ai.show', [
            'jobId' => $job,
            'job' => $recruitmentJob,
            'title' => ($ad?->title ?: $recruitmentJob->title) ?: 'Job '.$job,
            'ad' => $ad,
            'adError' => $adError,
            'branches' => Branch::orderBy('name')->get(['id', 'name', 'city']),
            'progress' => $exists ? $recruitmentJob->progress() : ['total' => 0, 'screened' => 0, 'pending' => 0, 'no_cv' => 0, 'failed' => 0],
            'top' => $exists ? $recruitmentJob->screenings()->ranked($includeRejected)->limit(10)->get(self::LIST_COLUMNS) : collect(),
            'all' => $exists
                ? $recruitmentJob->screenings()
                    ->orderByRaw("CASE status WHEN 'screened' THEN 0 WHEN 'pending' THEN 1 WHEN 'no_cv' THEN 2 ELSE 3 END")
                    ->orderByDesc('score')
                    ->orderBy('id')
                    ->get(self::LIST_COLUMNS)
                : collect(),
            'includeRejected' => $includeRejected,
            'aiConfigured' => AiSetting::get()->isConfigured(),
            'teamtailorUrl' => config('teamtailor.app_url') ?: null,
        ]);
    }

    public function status(string $job): JsonResponse
    {
        $recruitmentJob = RecruitmentJob::where('teamtailor_job_id', $job)->first();

        return response()->json($recruitmentJob
            ? $recruitmentJob->progress() + ['enabled' => $recruitmentJob->screening_enabled]
            : ['total' => 0, 'screened' => 0, 'pending' => 0, 'no_cv' => 0, 'failed' => 0, 'enabled' => false]);
    }

    public function start(TeamtailorApiService $teamtailor, string $job): RedirectResponse
    {
        if (! $teamtailor->isConfigured()) {
            return back()->with('error', 'Teamtailor is not configured, so there is nothing to screen.');
        }

        $settings = AiSetting::get();
        if (! $settings->isConfigured()) {
            return back()->with('error', 'The AI assistant is not configured: '.$settings->configurationIssue());
        }

        $recruitmentJob = RecruitmentJob::firstOrNew(['teamtailor_job_id' => $job]);

        if (! $recruitmentJob->title) {
            try {
                $recruitmentJob->title = JobAd::fromTeamtailor($teamtailor->getJob($job)['data'] ?? [])->title ?: null;
            } catch (\Throwable) {
                // The worker fills the title in on its first read.
            }
        }

        $recruitmentJob->forceFill([
            'screening_enabled' => true,
            'screening_enabled_at' => now(),
            'screening_enabled_by' => Auth::id(),
        ])->save();

        $this->audit('recruitment_ai_screening_on', $recruitmentJob);

        return back()->with('success', 'AI screening is on. CVs are read in the background: the first results appear within a few minutes, and the counts on this page update as they come in.');
    }

    public function stop(string $job): RedirectResponse
    {
        $recruitmentJob = RecruitmentJob::where('teamtailor_job_id', $job)->firstOrFail();
        $recruitmentJob->forceFill(['screening_enabled' => false])->save();

        $this->audit('recruitment_ai_screening_off', $recruitmentJob);

        return back()->with('success', 'AI screening is off. The results so far are kept; new applicants are not screened.');
    }

    /**
     * Saves what applicants are judged against besides the ad: the must-haves,
     * the office distances are measured to, and the monthly salary budget. All
     * three are in the criteria hash, so any change screens everyone again.
     */
    public function updateCriteria(Request $request, string $job): RedirectResponse
    {
        $data = $request->validate([
            'must_haves' => 'nullable|string|max:5000',
            'office_branch_id' => 'nullable|integer|exists:branches,id',
            'salary_budget_min' => 'nullable|integer|min:0|max:10000000',
            'salary_budget_max' => 'nullable|integer|min:0|max:10000000',
            'salary_currency' => ['nullable', 'required_with:salary_budget_min,salary_budget_max', Rule::in(RecruitmentJob::CURRENCIES)],
        ], [
            'salary_currency.required_with' => 'Choose the currency of the salary budget.',
        ]);

        $min = isset($data['salary_budget_min']) ? (int) $data['salary_budget_min'] : null;
        $max = isset($data['salary_budget_max']) ? (int) $data['salary_budget_max'] : null;

        if ($min !== null && $max !== null && $min > $max) {
            throw ValidationException::withMessages(['salary_budget_max' => 'The top of the salary budget is below its bottom.']);
        }

        $recruitmentJob = RecruitmentJob::firstOrNew(['teamtailor_job_id' => $job]);
        $before = $this->criteria($recruitmentJob);
        $mustHaves = RecruitmentJob::parseMustHaves((string) ($data['must_haves'] ?? ''));

        $recruitmentJob->forceFill([
            'must_haves' => $mustHaves === [] ? null : implode("\n", $mustHaves),
            'office_branch_id' => isset($data['office_branch_id']) ? (int) $data['office_branch_id'] : null,
            'salary_budget_min' => $min,
            'salary_budget_max' => $max,
            'salary_currency' => $min !== null || $max !== null ? $data['salary_currency'] : null,
        ]);

        $after = $this->criteria($recruitmentJob);

        if ($before === $after) {
            return back()->with('info', 'Nothing changed.');
        }

        $recruitmentJob->forceFill([
            'criteria_updated_at' => now(),
            'criteria_updated_by' => Auth::id(),
        ])->save();

        $changed = array_keys(array_filter($after, fn ($value, string $key) => $value !== $before[$key], ARRAY_FILTER_USE_BOTH));

        $this->audit('recruitment_ai_criteria_changed', $recruitmentJob, [
            'old' => Arr::only($before, $changed),
            'new' => Arr::only($after, $changed),
        ]);

        $screened = $recruitmentJob->screenings()->where('status', RecruitmentScreening::STATUS_SCREENED)->count();

        return back()->with('success', 'Saved.'.($screened > 0
            ? " The {$screened} applicants screened so far will be screened again".($recruitmentJob->screening_enabled ? '.' : ' once screening is switched on.')
            : ''));
    }

    public function rescreen(string $job): RedirectResponse
    {
        $recruitmentJob = RecruitmentJob::where('teamtailor_job_id', $job)->firstOrFail();

        $queued = $recruitmentJob->screenings()
            ->where('status', '!=', RecruitmentScreening::STATUS_PENDING)
            ->update(['status' => RecruitmentScreening::STATUS_PENDING, 'attempts' => 0, 'error' => null]);

        // Re-read the applicant list on the next run as well.
        $recruitmentJob->forceFill(['applicants_synced_at' => null])->save();

        $this->audit('recruitment_ai_rescreen', $recruitmentJob, ['queued' => $queued]);

        return back()->with('success', "{$queued} applicants queued to be read and screened again.");
    }

    /**
     * Deletes everything Recruitment AI stored for the job — the CV text, the
     * evaluations and the Ask conversations — and switches screening off.
     * The must-haves stay; nothing in Teamtailor changes.
     */
    public function destroyData(string $job): RedirectResponse
    {
        $recruitmentJob = RecruitmentJob::where('teamtailor_job_id', $job)->firstOrFail();
        $conversationIds = AiConversation::where('recruitment_job_id', $recruitmentJob->id)->pluck('id');
        $screenings = $recruitmentJob->screenings()->count();

        DB::transaction(function () use ($recruitmentJob, $conversationIds) {
            AiMessage::whereIn('conversation_id', $conversationIds)->delete();
            AiConversation::whereIn('id', $conversationIds)->delete();
            $recruitmentJob->screenings()->delete();

            $recruitmentJob->forceFill([
                'screening_enabled' => false,
                'applicant_count' => 0,
                'applicants_synced_at' => null,
                'last_screened_at' => null,
                'criteria_hash' => null,
                'sync_error' => null,
            ])->save();
        });

        $this->audit('recruitment_ai_data_deleted', $recruitmentJob, [
            'screenings' => $screenings,
            'conversations' => $conversationIds->count(),
        ]);

        return back()->with('success', "Deleted the AI screening of {$screenings} applicants (CV text and evaluations) and "
            .$conversationIds->count().' Ask conversation(s). Screening is off; nothing was changed in Teamtailor.');
    }

    public function ask(Request $request, RecruitmentAgent $agent, string $job): JsonResponse
    {
        $data = $request->validate([
            'question' => 'required|string|max:2000',
            'conversation_id' => 'nullable|integer',
        ]);

        $recruitmentJob = RecruitmentJob::where('teamtailor_job_id', $job)->first();

        if (! $recruitmentJob || ! $recruitmentJob->screenings()->exists()) {
            return response()->json(['message' => 'There is nothing to ask about yet: switch AI screening on and wait for the first applicants to be screened.'], 422);
        }

        $settings = AiSetting::get();
        if (! $settings->isConfigured()) {
            return response()->json(['message' => 'The AI assistant is not configured: '.$settings->configurationIssue()], 503);
        }

        $conversation = empty($data['conversation_id']) ? null : AiConversation::where('id', $data['conversation_id'])
            ->where('user_id', $request->user()->id)
            ->where('recruitment_job_id', $recruitmentJob->id)
            ->first();

        $lock = Cache::lock('recruitment-ai:ask:'.$request->user()->id, 150);

        if (! $lock->get()) {
            return response()->json(['message' => 'Still answering your previous question.'], 429);
        }

        try {
            $result = $agent->ask($recruitmentJob, $request->user(), $conversation, $data['question']);
        } catch (\Throwable $e) {
            Log::warning('Recruitment AI: ask failed', ['job' => $job, 'error' => $e->getMessage()]);

            return response()->json(['message' => str_contains($e->getMessage(), 'HTTP 429')
                ? 'Azure OpenAI is busy right now. Try again in a minute.'
                : 'The AI could not answer just now. Try again.'], 503);
        } finally {
            $lock->release();
        }

        return response()->json([
            'conversation_id' => $result['conversation']->id,
            'reply' => (string) ($result['reply']?->content ?? ''),
        ]);
    }

    /** What the criteria form sets, in the words the audit log keeps. */
    private function criteria(RecruitmentJob $job): array
    {
        $job->unsetRelation('office');

        return [
            'must_haves' => $job->mustHaveList(),
            'office' => $job->officeText(),
            'salary_budget' => $job->budgetText(),
        ];
    }

    private function audit(string $action, RecruitmentJob $job, array $changes = []): void
    {
        ActivityLog::create([
            'model_type' => RecruitmentJob::class,
            'model_id' => $job->id,
            'model_label' => $job->title,
            'action' => $action,
            'changes' => ['teamtailor_job_id' => $job->teamtailor_job_id] + $changes,
            'user_id' => Auth::id(),
        ]);
    }
}
