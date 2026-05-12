<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\Avepoint\RequestAvepointExportJob;
use App\Models\AvepointBackup;
use App\Models\Employee;
use App\Models\IdentityUser;
use App\Models\Setting;
use App\Services\AvePoint\AvePointApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * AvePoint admin module — operates independently of the offboarding flow.
 *
 *   /admin/avepoint            dashboard (jobs + counts + storage)
 *   /admin/avepoint/users      browse identity users, see last NOC backup, request more
 *   /admin/avepoint/jobs       live AvePoint job monitor (via /cloudbackupjobs)
 *   /admin/avepoint/backups    history of NOC-managed AvePoint backups
 *   /admin/avepoint/backups/{backup}  per-backup detail + audit
 *   POST /admin/avepoint/request      trigger an on-demand mailbox/OneDrive backup
 */
class AvePointController extends Controller
{
    public function __construct(private AvePointApiService $avepoint) {}

    public function dashboard(): View
    {
        $settings     = Setting::get();
        $configured   = $this->avepoint->isConfigured();
        $subscription = $configured ? $this->avepoint->getSubscription() : null;

        $recentJobs = $configured
            ? collect($this->avepoint->listRecentJobs(['pageSize' => 10]))
            : collect();

        $localCounts = [
            'total'      => AvepointBackup::count(),
            'in_flight'  => AvepointBackup::whereIn('status', ['pending', 'running', 'uploading'])->count(),
            'completed'  => AvepointBackup::where('status', 'completed')->count(),
            'failed'     => AvepointBackup::where('status', 'failed')->count(),
            'manual'     => AvepointBackup::where('status', 'manual_upload_required')->count(),
            'this_week'  => AvepointBackup::where('created_at', '>=', now()->subDays(7))->count(),
            'bytes_used' => (int) AvepointBackup::where('status', 'completed')->sum('file_size'),
        ];

        $recentBackups = AvepointBackup::query()
            ->with(['requestedBy', 'subjectEmployee', 'subjectIdentityUser'])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        return view('admin.avepoint.dashboard', [
            'settings'      => $settings,
            'configured'    => $configured,
            'hasEndpoints'  => $this->avepoint->hasExportEndpoints(),
            'subscription'  => $subscription,
            'recentJobs'    => $recentJobs,
            'localCounts'   => $localCounts,
            'recentBackups' => $recentBackups,
        ]);
    }

    /**
     * Identity-user browser + per-user "last NOC backup" column.
     */
    public function users(Request $request): View
    {
        $q   = trim((string) $request->query('q'));
        $sub = $this->avepoint->isConfigured();

        $users = IdentityUser::query()
            ->when($q, fn($builder) => $builder->where(function ($w) use ($q) {
                $w->where('display_name',         'like', "%{$q}%")
                  ->orWhere('user_principal_name','like', "%{$q}%")
                  ->orWhere('mail',               'like', "%{$q}%")
                  ->orWhere('department',         'like', "%{$q}%");
            }))
            ->orderBy('display_name')
            ->paginate(40)
            ->withQueryString();

        // Pre-fetch the most recent NOC backup per type per UPN visible on this page
        $upns = $users->pluck('user_principal_name')->filter()->all();
        $lastBackups = AvepointBackup::query()
            ->whereIn('subject_upn', $upns)
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('subject_upn')
            ->map(function ($rows) {
                return $rows->groupBy('type')->map(fn($r) => $r->first());
            });

        return view('admin.avepoint.users', [
            'users'       => $users,
            'q'           => $q,
            'lastBackups' => $lastBackups,
            'hasEndpoints'=> $this->avepoint->hasExportEndpoints(),
        ]);
    }

    /**
     * Live AvePoint jobs (from the read-only /cloudbackupjobs endpoint).
     * objectType: 1=Exchange, 3=OneDrive — defaults to both.
     */
    public function jobs(Request $request): View
    {
        $filter = [
            'pageSize' => 50,
        ];
        if ($t = $request->query('object_type')) {
            $filter['objectType'] = (int) $t;
        }
        if ($s = $request->query('state')) {
            $filter['jobState'] = (int) $s;
        }
        if ($d = $request->query('since')) {
            $filter['startTime'] = $d;
        }

        $jobs = $this->avepoint->isConfigured()
            ? $this->avepoint->listRecentJobs($filter)
            : [];

        return view('admin.avepoint.jobs', [
            'jobs'        => $jobs,
            'configured'  => $this->avepoint->isConfigured(),
            'filter'      => $filter,
            'objectType'  => $request->query('object_type'),
            'state'       => $request->query('state'),
        ]);
    }

    /**
     * NOC-managed backup history (everything we've actually pulled into Blob).
     */
    public function backups(Request $request): View
    {
        $status = $request->query('status');
        $type   = $request->query('type');
        $q      = trim((string) $request->query('q'));

        $rows = AvepointBackup::query()
            ->with(['requestedBy', 'subjectEmployee'])
            ->when($status, fn($qq) => $qq->where('status', $status))
            ->when($type,   fn($qq) => $qq->where('type', $type))
            ->when($q,      fn($qq) => $qq->where(function ($w) use ($q) {
                $w->where('subject_upn',  'like', "%{$q}%")
                  ->orWhere('subject_name','like', "%{$q}%");
            }))
            ->orderByDesc('created_at')
            ->paginate(30)
            ->withQueryString();

        return view('admin.avepoint.backups', [
            'rows'   => $rows,
            'status' => $status,
            'type'   => $type,
            'q'      => $q,
        ]);
    }

    public function showBackup(AvepointBackup $backup): View
    {
        $backup->load(['requestedBy', 'subjectEmployee', 'subjectIdentityUser', 'downloadAudits.user']);
        return view('admin.avepoint.show', ['backup' => $backup]);
    }

    /**
     * Trigger a new ad-hoc backup. Accepts a UPN + one or both of the types.
     *
     * POST body:
     *   upn:    employee.user@example.com   (required)
     *   types:  ['mailbox', 'onedrive']     (at least one)
     *   notes:  free text (optional)
     */
    public function requestBackup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'upn'     => 'required|email|max:200',
            'types'   => 'required|array|min:1',
            'types.*' => 'in:mailbox,onedrive',
            'notes'   => 'nullable|string|max:500',
        ]);

        $upn = strtolower($data['upn']);

        $identityUser = IdentityUser::whereRaw('LOWER(user_principal_name) = ?', [$upn])
            ->orWhereRaw('LOWER(mail) = ?', [$upn])
            ->first();
        $employee = Employee::whereRaw('LOWER(email) = ?', [$upn])->first();

        $created  = [];
        $skipped  = [];

        foreach (array_unique($data['types']) as $type) {
            // Don't double-request: if an in-flight one already exists, skip.
            $existing = AvepointBackup::where('subject_upn', $upn)
                ->where('type', $type)
                ->whereIn('status', ['pending', 'running', 'uploading', 'manual_upload_required'])
                ->latest()
                ->first();
            if ($existing) {
                $skipped[] = ['type' => $type, 'backup_id' => $existing->id, 'status' => $existing->status];
                continue;
            }

            $backup = AvepointBackup::create([
                'subject_upn'              => $upn,
                'subject_name'             => $identityUser?->display_name ?? $employee?->name,
                'subject_identity_user_id' => $identityUser?->id,
                'subject_employee_id'      => $employee?->id,
                'requested_by_user_id'     => $request->user()?->id,
                'notes'                    => $data['notes'] ?? null,
                'type'                     => $type,
                'source'                   => $this->avepoint->hasExportEndpoints() ? 'avepoint' : 'manual_upload',
                'status'                   => 'pending',
            ]);

            RequestAvepointExportJob::dispatch($backup->id)->onQueue('avepoint');
            $created[] = ['type' => $type, 'backup_id' => $backup->id];
        }

        return response()->json([
            'ok'      => true,
            'message' => count($created) . ' backup(s) requested, ' . count($skipped) . ' skipped (in-flight).',
            'created' => $created,
            'skipped' => $skipped,
        ], 202);
    }

    /**
     * Retry a failed backup — fires a fresh RequestAvepointExportJob.
     */
    public function retry(AvepointBackup $backup): JsonResponse
    {
        if (! in_array($backup->status, ['failed', 'pruned'], true)) {
            return response()->json([
                'ok'      => false,
                'message' => "Cannot retry — current status is '{$backup->status}'.",
            ], 422);
        }

        $backup->update([
            'status'          => 'pending',
            'avepoint_job_id' => null,
            'file_path'       => null,
            'file_size'       => null,
            'file_sha256'     => null,
            'download_token'  => null,
            'download_expires_at' => null,
            'requester_notified_at' => null,
            'error_message'   => null,
        ]);

        RequestAvepointExportJob::dispatch($backup->id)->onQueue('avepoint');

        return response()->json([
            'ok'      => true,
            'message' => 'Retry dispatched.',
        ]);
    }
}
