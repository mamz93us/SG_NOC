<?php

namespace App\Http\Controllers\Admin\Teamtailor;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\Teamtailor\TeamtailorApiService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;

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
        $applications = collect();
        $total = 0;
        $error = null;
        $configured = $teamtailor->isConfigured();

        if ($configured) {
            try {
                $body = $teamtailor->listJobApplicants($job, $page, self::PER_PAGE);
                $total = (int) Arr::get($body, 'meta.record-count', 0);

                // Side-loaded job-applications, indexed so each candidate can
                // resolve its application for this job without a per-row call.
                $included = collect(Arr::get($body, 'included', []))
                    ->keyBy(fn ($r) => ($r['type'] ?? '').':'.($r['id'] ?? ''));

                $applications = collect(Arr::get($body, 'data', []))
                    ->map(fn ($row) => $this->mapApplicant($row, $included, $job));
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

        return view('admin.teamtailor.jobs.show', [
            'jobId' => $job,
            'jobTitle' => $jobTitle,
            'applications' => $applications,
            'paginator' => $paginator,
            'total' => $total,
            'error' => $error,
            'configured' => $configured,
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
     * Flatten a candidate (the primary resource on /v1/jobs/{id}/candidates) into
     * a view-friendly applicant row, resolving its job-application FOR THIS JOB
     * from the side-loaded `included` collection — that application carries the
     * id needed to reject and the rejected-at stamp.
     *
     * @param  array<string,mixed>  $row  a candidate resource
     * @param  \Illuminate\Support\Collection<string,array<string,mixed>>  $included
     * @return array<string,mixed>
     */
    private function mapApplicant(array $row, $included, string $jobId): array
    {
        $a = $row['attributes'] ?? [];

        // Match this candidate's application to the current job.
        $applicationId = null;
        $rejectedAt = null;
        $appliedAt = $a['created-at'] ?? null;

        foreach (Arr::get($row, 'relationships.job-applications.data', []) as $ref) {
            $app = $included->get('job-applications:'.($ref['id'] ?? ''));
            if (! $app) {
                continue;
            }
            if ((string) Arr::get($app, 'relationships.job.data.id') === $jobId) {
                $applicationId = $app['id'] ?? null;
                $rejectedAt = Arr::get($app, 'attributes.rejected-at');
                $appliedAt = Arr::get($app, 'attributes.created-at', $appliedAt);
                break;
            }
        }

        $first = $a['first-name'] ?? '';
        $last = $a['last-name'] ?? '';

        return [
            'application_id' => $applicationId,
            'candidate_id' => $row['id'] ?? null,
            'name' => trim("{$first} {$last}") ?: '—',
            'email' => $a['email'] ?? null,
            'phone' => $a['phone'] ?? null,
            'linkedin' => $a['linkedin-url'] ?? null,
            'resume' => $a['resume'] ?? null,
            'applied_at' => $appliedAt,
            'rejected' => ! empty($rejectedAt),
            'rejected_at' => $rejectedAt,
        ];
    }
}
