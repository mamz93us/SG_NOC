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
                $body = $teamtailor->listJobApplications($job, $page, self::PER_PAGE, '-created-at');
                $total = (int) Arr::get($body, 'meta.record-count', 0);

                // Index the side-loaded resources so each application can resolve
                // its candidate without a per-row API call.
                $included = collect(Arr::get($body, 'included', []))
                    ->keyBy(fn ($r) => ($r['type'] ?? '').':'.($r['id'] ?? ''));

                $applications = collect(Arr::get($body, 'data', []))
                    ->map(fn ($row) => $this->mapApplication($row, $included));
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
     * Flatten a JSON:API job-application resource, resolving its candidate from
     * the side-loaded `included` collection.
     *
     * @param  array<string,mixed>  $row
     * @param  \Illuminate\Support\Collection<string,array<string,mixed>>  $included
     * @return array<string,mixed>
     */
    private function mapApplication(array $row, $included): array
    {
        $a = $row['attributes'] ?? [];

        $candidateId = Arr::get($row, 'relationships.candidate.data.id');
        $candidate = $candidateId ? ($included->get("candidates:{$candidateId}")['attributes'] ?? []) : [];

        $first = $candidate['first-name'] ?? '';
        $last = $candidate['last-name'] ?? '';
        $rejectedAt = $a['rejected-at'] ?? null;

        return [
            'id' => $row['id'] ?? null,
            'name' => trim("{$first} {$last}") ?: '—',
            'email' => $candidate['email'] ?? null,
            'phone' => $candidate['phone'] ?? null,
            'linkedin' => $candidate['linkedin-url'] ?? null,
            'resume' => $candidate['resume'] ?? null,
            'applied_at' => $a['created-at'] ?? null,
            'rejected' => ! empty($rejectedAt),
            'rejected_at' => $rejectedAt,
        ];
    }
}
