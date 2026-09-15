<?php

namespace App\Http\Controllers\Admin\Teamtailor;

use App\Http\Controllers\Controller;
use App\Models\Recruitment\RecruitmentScreening;
use App\Services\Recruitment\RecruitmentToolbox;
use App\Services\Teamtailor\CandidateProfileReader;
use App\Services\Teamtailor\TeamtailorApiService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class CandidateController extends Controller
{
    /** Page size for the candidate listing (Teamtailor caps page[size] at 30). */
    private const PER_PAGE = 25;

    public function index(Request $request, TeamtailorApiService $teamtailor)
    {
        $page = max(1, (int) $request->query('page', 1));
        $sort = $request->query('sort') === 'oldest' ? 'created-at' : '-created-at';

        $filters = $this->buildFilters($request);

        $candidates = collect();
        $total = 0;
        $error = null;
        $configured = $teamtailor->isConfigured();

        if ($configured) {
            try {
                $body = $teamtailor->listCandidates($filters, $page, self::PER_PAGE, $sort);
                $total = (int) Arr::get($body, 'meta.record-count', 0);
                $candidates = collect(Arr::get($body, 'data', []))
                    ->map(fn ($row) => $this->mapCandidate($row));
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        $paginator = new LengthAwarePaginator(
            $candidates,
            $total,
            self::PER_PAGE,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return view('admin.teamtailor.candidates.index', [
            'candidates' => $candidates,
            'paginator' => $paginator,
            'total' => $total,
            'error' => $error,
            'configured' => $configured,
        ]);
    }

    /**
     * One candidate as Teamtailor holds them: the salary their answers state on
     * top, details, every application with its stage, rejection, cover letter
     * and questions and answers, attachments, and the activity log
     * (CandidateProfileReader). Also a deep link out to the Teamtailor recruiter
     * app when teamtailor.app_url is configured, and — for people who may use
     * Recruitment AI — each application's AI score and the distance to the
     * office the screening estimated.
     */
    public function show(Request $request, TeamtailorApiService $teamtailor, CandidateProfileReader $reader, string $candidate)
    {
        $configured = $teamtailor->isConfigured();
        $allActivity = $request->boolean('all_activity');
        $data = null;
        $error = null;

        if ($configured) {
            try {
                $data = $reader->read($candidate, $allActivity);
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        $appBase = (string) config('teamtailor.app_url', '');
        $canUseRecruitmentAi = (bool) $request->user()?->hasPermission(RecruitmentToolbox::PERMISSION);
        $screenings = $canUseRecruitmentAi ? $this->screenings($candidate) : collect();
        $applications = $data['applications'] ?? [];

        return view('admin.teamtailor.candidates.show', [
            'candidateId' => $candidate,
            'configured' => $configured,
            'profile' => $data['profile'] ?? null,
            'applications' => $applications,
            'otherAnswers' => $data['other_answers'] ?? [],
            'salary' => $data['salary'] ?? ['expected' => null, 'current' => null],
            'uploads' => $data['uploads'] ?? [],
            'activities' => $data['activities'] ?? [],
            'activitiesTotal' => $data['activities_total'] ?? null,
            'activityError' => $data['activity_error'] ?? null,
            'allActivity' => $allActivity,
            'canUseRecruitmentAi' => $canUseRecruitmentAi,
            'screenings' => $screenings,
            'screenedLocation' => self::screenedLocation($applications, $screenings),
            'error' => $error,
            'teamtailorUrl' => $appBase !== '' ? $appBase.'/candidates/'.$candidate : null,
        ]);
    }

    /**
     * Recruitment AI's screening of this candidate, keyed by Teamtailor job id.
     * Only for people who may use Recruitment AI: the caller checks.
     *
     * @return Collection<string, RecruitmentScreening>
     */
    private function screenings(string $candidate): Collection
    {
        return RecruitmentScreening::with('job:id,teamtailor_job_id,title')
            ->where('teamtailor_candidate_id', $candidate)
            ->get(['id', 'recruitment_job_id', 'status', 'score', 'fit', 'must_haves_met', 'must_haves_total', 'facts'])
            ->filter(fn (RecruitmentScreening $screening) => $screening->job !== null)
            ->keyBy(fn (RecruitmentScreening $screening) => $screening->job->teamtailor_job_id);
    }

    /**
     * Where the newest application with an AI screening says the candidate
     * lives, and how far that is from its job's office; null when none says.
     *
     * @param  list<array<string,mixed>>  $applications  newest first
     * @param  Collection<string, RecruitmentScreening>  $screenings  keyed by Teamtailor job id
     * @return array{lives_in: ?string, distance_km: ?int, office: ?string, relocation_needed: ?string, job_title: ?string}|null
     */
    private static function screenedLocation(array $applications, Collection $screenings): ?array
    {
        foreach ($applications as $application) {
            $screening = $application['job_id'] ? $screenings->get($application['job_id']) : null;

            if ($screening && ($screening->distanceKm() !== null || $screening->livesIn() !== null)) {
                return [
                    'lives_in' => $screening->livesIn(),
                    'distance_km' => $screening->distanceKm(),
                    'office' => $screening->distanceOffice(),
                    'relocation_needed' => $screening->relocationNeeded(),
                    'job_title' => $application['job_title'],
                ];
            }
        }

        return null;
    }

    /**
     * Translate request inputs into Teamtailor JSON:API filter keys.
     *
     * @return array<string,string>
     */
    private function buildFilters(Request $request): array
    {
        $filters = [];

        if ($request->filled('email')) {
            $filters['filter[email]'] = trim((string) $request->query('email'));
        }
        if ($request->filled('phone')) {
            $filters['filter[phone]'] = trim((string) $request->query('phone'));
        }
        if (in_array($request->query('connected'), ['true', 'false'], true)) {
            $filters['filter[connected]'] = $request->query('connected');
        }
        if ($request->filled('created_from')) {
            $filters['filter[created-at][from]'] = (string) $request->query('created_from');
        }
        if ($request->filled('created_to')) {
            $filters['filter[created-at][to]'] = (string) $request->query('created_to');
        }

        return $filters;
    }

    /**
     * Flatten a JSON:API candidate resource into a view-friendly row.
     *
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function mapCandidate(array $row): array
    {
        $a = $row['attributes'] ?? [];
        $first = $a['first-name'] ?? '';
        $last = $a['last-name'] ?? '';

        return [
            'id' => $row['id'] ?? null,
            'name' => trim("{$first} {$last}") ?: '—',
            'email' => $a['email'] ?? null,
            'phone' => $a['phone'] ?? null,
            'connected' => (bool) ($a['connected'] ?? false),
            'sourced' => (bool) ($a['sourced'] ?? false),
            'tags' => is_array($a['tags'] ?? null) ? $a['tags'] : [],
            'linkedin' => $a['linkedin-url'] ?? null,
            'resume' => $a['resume'] ?? null,
            'created_at' => $a['created-at'] ?? null,
        ];
    }
}
