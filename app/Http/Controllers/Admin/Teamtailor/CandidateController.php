<?php

namespace App\Http\Controllers\Admin\Teamtailor;

use App\Http\Controllers\Controller;
use App\Services\Teamtailor\TeamtailorApiService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;

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
     * Show one candidate's in-app profile, side-loading their job-applications (and
     * the jobs those applications belong to, when Teamtailor allows the nested
     * include) so the page can list what they applied to. Also exposes a deep link
     * out to the Teamtailor recruiter app when teamtailor.app_url is configured.
     */
    public function show(Request $request, TeamtailorApiService $teamtailor, string $candidate)
    {
        $configured = $teamtailor->isConfigured();
        $profile = null;
        $applications = [];
        $error = null;

        if ($configured) {
            try {
                $body = $this->fetchCandidateProfile($teamtailor, $candidate);
                $row = Arr::get($body, 'data', []);
                $included = collect(Arr::get($body, 'included', []))
                    ->keyBy(fn ($r) => ($r['type'] ?? '').':'.($r['id'] ?? ''));

                $profile = $this->mapCandidate($row);
                $applications = $this->mapProfileApplications($row, $included);
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        $appBase = (string) config('teamtailor.app_url', '');
        $teamtailorUrl = $appBase !== '' ? $appBase.'/candidates/'.$candidate : null;

        return view('admin.teamtailor.candidates.show', [
            'candidateId' => $candidate,
            'configured' => $configured,
            'profile' => $profile,
            'applications' => $applications,
            'error' => $error,
            'teamtailorUrl' => $teamtailorUrl,
        ]);
    }

    /**
     * Fetch a candidate with their applications. Tries the nested job include so
     * each application can show its job title; the nested include is undocumented,
     * so a 4xx param rejection retries with just job-applications — the profile must
     * never break over an unsupported include.
     *
     * @return array<string,mixed>
     */
    private function fetchCandidateProfile(TeamtailorApiService $teamtailor, string $id): array
    {
        try {
            return $teamtailor->getCandidate($id, ['job-applications', 'job-applications.job']);
        } catch (\Throwable $e) {
            if (preg_match('/\((400|422)\)/', $e->getMessage()) !== 1) {
                throw $e;
            }

            return $teamtailor->getCandidate($id, ['job-applications']);
        }
    }

    /**
     * Build the candidate's application history from the side-loaded resources:
     * one row per job-application with its job title (when the job was included),
     * the applied date and whether it has been rejected. Sorted newest first.
     *
     * @param  array<string,mixed>  $row  the candidate resource
     * @param  \Illuminate\Support\Collection<string,array<string,mixed>>  $included
     * @return array<int,array<string,mixed>>
     */
    private function mapProfileApplications(array $row, $included): array
    {
        $apps = [];

        foreach (Arr::get($row, 'relationships.job-applications.data', []) as $ref) {
            $app = $included->get('job-applications:'.($ref['id'] ?? ''));
            if (! $app) {
                continue;
            }

            $jobId = (string) Arr::get($app, 'relationships.job.data.id', '');
            $job = $jobId !== '' ? $included->get('jobs:'.$jobId) : null;
            $title = $job
                ? (Arr::get($job, 'attributes.title') ?? Arr::get($job, 'attributes.internal-name'))
                : null;

            $apps[] = [
                'id' => $app['id'] ?? null,
                'job_id' => $jobId ?: null,
                'job_title' => $title,
                'applied_at' => Arr::get($app, 'attributes.created-at'),
                'rejected' => ! empty(Arr::get($app, 'attributes.rejected-at')),
            ];
        }

        usort($apps, fn ($x, $y) => strcmp((string) $y['applied_at'], (string) $x['applied_at']));

        return $apps;
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
