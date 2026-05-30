<?php

namespace App\Http\Controllers\Admin\Teamtailor;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\TeamtailorCvExport;
use App\Services\Teamtailor\TeamtailorApiService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class JobController extends Controller
{
    /** Page size for listings (Teamtailor caps page[size] at 30). */
    private const PER_PAGE = 25;

    /**
     * List the recruiting jobs candidates apply to.
     */
    public function index(Request $request, TeamtailorApiService $teamtailor)
    {
        $page = max(1, (int) $request->query('page', 1));

        $jobs = collect();
        $total = 0;
        $error = null;
        $configured = $teamtailor->isConfigured();

        if ($configured) {
            try {
                $body = $teamtailor->listJobs([], $page, self::PER_PAGE, '-created-at');
                $total = (int) Arr::get($body, 'meta.record-count', 0);
                $jobs = collect(Arr::get($body, 'data', []))
                    ->map(fn ($row) => $this->mapJob($row));
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        $paginator = new LengthAwarePaginator(
            $jobs,
            $total,
            self::PER_PAGE,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return view('admin.teamtailor.jobs.index', [
            'jobs' => $jobs,
            'paginator' => $paginator,
            'total' => $total,
            'error' => $error,
            'configured' => $configured,
        ]);
    }

    /**
     * Show one job's applicants (job-applications with the candidate side-loaded).
     */
    public function show(Request $request, TeamtailorApiService $teamtailor, string $job)
    {
        $page = max(1, (int) $request->query('page', 1));
        $jobTitle = (string) $request->query('title', '');

        // Newest-first by default; mirrors the candidates list toggle.
        $sortKey = $request->query('sort') === 'oldest' ? 'created-at' : '-created-at';
        $ttFilters = $this->buildApplicantFilters($request);

        $applications = collect();
        $stages = [];
        $total = 0;
        $error = null;
        $filtersIgnored = false;
        $configured = $teamtailor->isConfigured();

        if ($configured) {
            // Resolve the job's stage id→name map first so each application can
            // show its pipeline stage as the candidate's status. Non-fatal.
            $stageNames = $this->resolveStageNames($teamtailor, $job);
            $stages = array_values(array_filter(array_unique(array_values($stageNames))));

            try {
                $body = $this->fetchApplicants($teamtailor, $job, $page, $sortKey, $ttFilters, $filtersIgnored);
                $total = (int) Arr::get($body, 'meta.record-count', 0);

                // Side-loaded job-applications, indexed so each candidate can
                // resolve its application for this job without a per-row call.
                $included = collect(Arr::get($body, 'included', []))
                    ->keyBy(fn ($r) => ($r['type'] ?? '').':'.($r['id'] ?? ''));

                $applications = collect(Arr::get($body, 'data', []))
                    ->map(fn ($row) => $this->mapApplicant($row, $included, $job, $stageNames));
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        $paginator = new LengthAwarePaginator(
            $applications,
            $total,
            self::PER_PAGE,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        // Latest bulk-CV-export request for this job, if any — drives the
        // "Download all CVs" card's preparing / ready / failed state.
        $cvExport = TeamtailorCvExport::query()
            ->where('job_id', $job)
            ->latest('id')
            ->first();

        return view('admin.teamtailor.jobs.show', [
            'jobId' => $job,
            'jobTitle' => $jobTitle,
            'applications' => $applications,
            'paginator' => $paginator,
            'stages' => $stages,
            'total' => $total,
            'error' => $error,
            'filtersIgnored' => $filtersIgnored,
            'configured' => $configured,
            'cvExport' => $cvExport,
        ]);
    }

    /**
     * Queue a bulk export of every applicant's CV for this job. The actual work
     * (paging the ATS, downloading each résumé, zipping, uploading to Azure
     * Blob) is drained by the teamtailor:process-cv-exports scheduled command —
     * a synchronous request would time out on a job with hundreds of applicants.
     */
    public function exportCvs(Request $request, TeamtailorApiService $teamtailor, string $job)
    {
        if (! $teamtailor->isConfigured()) {
            return back()->with('error', 'Teamtailor is not configured, so CVs cannot be exported.');
        }

        // One in-flight export per job is enough — collapse repeat clicks onto
        // the existing run rather than spawning duplicate zips.
        $existing = TeamtailorCvExport::query()
            ->where('job_id', $job)
            ->whereIn('status', [TeamtailorCvExport::STATUS_PENDING, TeamtailorCvExport::STATUS_PROCESSING])
            ->first();

        if ($existing) {
            return back()->with('info', 'A CV export for this job is already being prepared. Refresh in a minute to download it.');
        }

        $export = TeamtailorCvExport::create([
            'job_id' => $job,
            'job_title' => (string) $request->input('title', $request->query('title', '')) ?: null,
            'status' => TeamtailorCvExport::STATUS_PENDING,
            'disk' => 'azure_resumes',
            'requested_by' => Auth::id(),
        ]);

        ActivityLog::create([
            'model_type' => 'TeamtailorCvExport',
            'model_id' => $export->id,
            'action' => 'requested',
            'changes' => ['job_id' => $job],
            'user_id' => Auth::id(),
        ]);

        return back()->with('success', 'Preparing a zip of all CVs for this job. Refresh in a minute and a download button will appear.');
    }

    /**
     * Stream a finished CV-export zip down to the admin. The file is candidate
     * PII living in Azure Blob, so it is proxied through this auth + permission
     * gated route, never exposed via a public blob link.
     */
    public function downloadCvExport(string $job, TeamtailorCvExport $export): StreamedResponse
    {
        abort_unless($export->job_id === $job && $export->isDownloadable(), 404);

        $stream = Storage::disk($export->disk)->readStream($export->file_path);
        abort_if($stream === null, 404);

        ActivityLog::create([
            'model_type' => 'TeamtailorCvExport',
            'model_id' => $export->id,
            'action' => 'downloaded',
            'changes' => ['job_id' => $job],
            'user_id' => Auth::id(),
        ]);

        $filename = (Str::slug((string) ($export->job_title ?: $export->job_id)) ?: 'job').'-cvs.zip';

        return new StreamedResponse(function () use ($stream) {
            while (! feof($stream)) {
                echo fread($stream, 1024 * 1024);
                flush();
            }
            fclose($stream);
        }, 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Content-Length' => (string) ($export->file_size ?: ''),
        ]);
    }

    /**
     * Reject one job application. Silent by design — see
     * TeamtailorApiService::rejectJobApplication(). Reversible in Teamtailor.
     */
    public function reject(Request $request, TeamtailorApiService $teamtailor, string $job, string $application)
    {
        try {
            $teamtailor->rejectJobApplication($application);
        } catch (\Throwable $e) {
            return back()->with('error', 'Could not reject application: '.$e->getMessage());
        }

        ActivityLog::create([
            'model_type' => 'TeamtailorJobApplication',
            'model_id' => 0,
            'action' => 'rejected',
            'changes' => ['application_id' => $application, 'job_id' => $job],
            'user_id' => Auth::id(),
        ]);

        return back()->with('success', 'Application rejected in Teamtailor (no email sent).');
    }

    /**
     * Flatten a JSON:API job resource into a view-friendly row.
     *
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function mapJob(array $row): array
    {
        $a = $row['attributes'] ?? [];

        return [
            'id' => $row['id'] ?? null,
            'title' => $a['title'] ?? ($a['internal-name'] ?? '—'),
            'status' => $a['status'] ?? null,
            'created_at' => $a['created-at'] ?? null,
        ];
    }

    /**
     * Translate request inputs into Teamtailor candidate filter keys. Only the
     * filters Teamtailor supports on the candidate resource go server-side
     * (email search + applied-date range); status/stage is narrowed in the view
     * because it lives on the application, not the candidate.
     *
     * @return array<string,string>
     */
    private function buildApplicantFilters(Request $request): array
    {
        $filters = [];

        // The candidate resource has no name filter, so the box is an email
        // (contains) search — the most useful server-side lookup available.
        $email = trim((string) $request->query('q', ''));
        if ($email !== '') {
            $filters['filter[email]'] = $email;
        }
        if ($request->filled('applied_from')) {
            $filters['filter[created-at][from]'] = (string) $request->query('applied_from');
        }
        if ($request->filled('applied_to')) {
            $filters['filter[created-at][to]'] = (string) $request->query('applied_to');
        }

        return $filters;
    }

    /**
     * Build a stage id → name map for the job. Non-fatal: returns [] on failure
     * so the applicants list still renders with plain Active/Rejected badges.
     *
     * @return array<string,string>
     */
    private function resolveStageNames(TeamtailorApiService $teamtailor, string $jobId): array
    {
        $names = [];

        try {
            foreach (Arr::get($teamtailor->listJobStages($jobId), 'data', []) as $st) {
                $name = Arr::get($st, 'attributes.name');
                if ($name !== null) {
                    $names[(string) ($st['id'] ?? '')] = (string) $name;
                }
            }
        } catch (\Throwable) {
            // Stage names are optional.
        }

        return $names;
    }

    /**
     * Fetch one page of applicants, passing the sort + candidate filters through.
     * Teamtailor's support for sort/filter on the nested candidates endpoint is
     * undocumented, so a 4xx param rejection retries once with neither — the page
     * must never break just because a filter turns out to be unsupported.
     *
     * @param  array<string,string|int>  $filters
     * @return array<string,mixed>
     */
    private function fetchApplicants(
        TeamtailorApiService $teamtailor,
        string $jobId,
        int $page,
        string $sortKey,
        array $filters,
        bool &$filtersIgnored
    ): array {
        try {
            return $teamtailor->listJobApplicants($jobId, $page, self::PER_PAGE, $sortKey, $filters);
        } catch (\Throwable $e) {
            // Only a client-side param rejection is safe to downgrade; surface
            // anything else (auth, 404, 5xx, network) as the real error.
            if (preg_match('/\((400|422)\)/', $e->getMessage()) !== 1) {
                throw $e;
            }

            $filtersIgnored = true;

            return $teamtailor->listJobApplicants($jobId, $page, self::PER_PAGE);
        }
    }

    /**
     * Flatten a candidate (the primary resource on /v1/jobs/{id}/candidates) into
     * a view-friendly applicant row, resolving its job-application FOR THIS JOB
     * from the side-loaded `included` collection — that application carries the
     * id needed to reject, the rejected-at stamp and the pipeline stage.
     *
     * Matching prefers a job-id linkage on the application; when Teamtailor omits
     * that linkage it falls back to the candidate's sole application, then to the
     * most recent — so the reject action still resolves for the common case of an
     * applicant who only applied to this job.
     *
     * @param  array<string,mixed>  $row  a candidate resource
     * @param  \Illuminate\Support\Collection<string,array<string,mixed>>  $included
     * @param  array<string,string>  $stageNames  stage id → name for this job
     * @return array<string,mixed>
     */
    private function mapApplicant(array $row, $included, string $jobId, array $stageNames = []): array
    {
        $a = $row['attributes'] ?? [];

        $apps = [];
        foreach (Arr::get($row, 'relationships.job-applications.data', []) as $ref) {
            $app = $included->get('job-applications:'.($ref['id'] ?? ''));
            if ($app) {
                $apps[] = $app;
            }
        }

        $chosen = null;
        foreach ($apps as $app) {
            if ((string) Arr::get($app, 'relationships.job.data.id') === $jobId) {
                $chosen = $app;
                break;
            }
        }
        if (! $chosen && count($apps) === 1) {
            $chosen = $apps[0];
        }
        if (! $chosen && count($apps) > 1) {
            usort($apps, fn ($x, $y) => strcmp(
                (string) Arr::get($y, 'attributes.created-at'),
                (string) Arr::get($x, 'attributes.created-at')
            ));
            $chosen = $apps[0];
        }

        $rejectedAt = $chosen ? Arr::get($chosen, 'attributes.rejected-at') : null;
        $appliedAt = $chosen
            ? Arr::get($chosen, 'attributes.created-at', $a['created-at'] ?? null)
            : ($a['created-at'] ?? null);
        $stageId = $chosen ? (string) Arr::get($chosen, 'relationships.stage.data.id', '') : '';
        $stage = ($stageId !== '' && isset($stageNames[$stageId])) ? $stageNames[$stageId] : null;

        $first = $a['first-name'] ?? '';
        $last = $a['last-name'] ?? '';

        return [
            'application_id' => $chosen['id'] ?? null,
            'candidate_id' => $row['id'] ?? null,
            'name' => trim("{$first} {$last}") ?: '—',
            'email' => $a['email'] ?? null,
            'phone' => $a['phone'] ?? null,
            'linkedin' => $a['linkedin-url'] ?? null,
            'resume' => $a['resume'] ?? null,
            'applied_at' => $appliedAt,
            'stage' => $stage,
            'rejected' => ! empty($rejectedAt),
            'rejected_at' => $rejectedAt,
        ];
    }
}
