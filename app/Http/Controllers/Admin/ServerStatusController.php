<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\RunDatabaseBackupJob;
use App\Models\ActivityLog;
use App\Models\DatabaseBackup;
use App\Services\ServerStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * Admin → Server Status: live NOC host health (CPU/memory/disks/uptime,
 * systemd services, docker containers, app-level checks) plus the database
 * backup history with a "Backup Now" trigger.
 */
class ServerStatusController extends Controller
{
    public function index(ServerStatusService $status)
    {
        $backups = DatabaseBackup::with('initiator')
            ->orderByDesc('id')
            ->limit(25)
            ->get();

        return view('admin.server-status', [
            'snapshot' => $status->snapshot(),
            'backups' => $backups,
            'lastGoodBackup' => DatabaseBackup::uploaded()->orderByDesc('completed_at')->first(),
            'backupInFlight' => $backups->first(fn ($b) => $b->isInFlight()) !== null,
            'liveInAzureCount' => DatabaseBackup::liveInAzure()->count(),
            'liveInAzureBytes' => (int) DatabaseBackup::liveInAzure()->sum('size'),
            'retentionDays' => config('db_backup.retention_days'),
        ]);
    }

    /** JSON snapshot for the page's auto-refresh poller. */
    public function metrics(ServerStatusService $status): JsonResponse
    {
        return response()->json($status->snapshot());
    }

    public function backupNow(Request $request)
    {
        if ($inFlight = DatabaseBackup::inFlight()->first()) {
            return back()->with('info', "A backup is already {$inFlight->status} (#{$inFlight->id}) — wait for it to finish.");
        }

        $backup = DatabaseBackup::create([
            'status' => DatabaseBackup::STATUS_PENDING,
            'triggered_via' => DatabaseBackup::VIA_MANUAL,
            'initiated_by' => Auth::id(),
        ]);

        RunDatabaseBackupJob::dispatch($backup->id);

        ActivityLog::create([
            'model_type' => 'DatabaseBackup', 'model_id' => $backup->id,
            'action' => 'db_backup_requested',
            'changes' => ['triggered_via' => DatabaseBackup::VIA_MANUAL],
            'user_id' => Auth::id(),
        ]);

        return back()->with('success', 'Backup queued — the queue drainer picks it up within a minute. This page refreshes itself until it completes.');
    }

    public function downloadBackup(DatabaseBackup $databaseBackup)
    {
        abort_unless(
            $databaseBackup->isUploaded() && $databaseBackup->azure_path,
            404,
            'This backup is not archived to Azure.'
        );

        $disk = Storage::disk($databaseBackup->disk ?: config('db_backup.disk', 'azure_db_backups'));

        abort_unless($disk->exists($databaseBackup->azure_path), 404, 'Archived dump no longer exists in storage.');

        ActivityLog::create([
            'model_type' => 'DatabaseBackup', 'model_id' => $databaseBackup->id,
            'action' => 'db_backup_downloaded',
            'changes' => ['azure_path' => $databaseBackup->azure_path, 'size' => $databaseBackup->size],
            'user_id' => Auth::id(),
        ]);

        // Streams the blob (no full-file buffering) — fine for multi-GB dumps.
        return $disk->download($databaseBackup->azure_path, $databaseBackup->filename);
    }

    /** Deletes the blob from Azure; the history row survives as `pruned`. */
    public function deleteBackup(DatabaseBackup $databaseBackup)
    {
        abort_unless($databaseBackup->isUploaded() && $databaseBackup->azure_path, 404);

        try {
            Storage::disk($databaseBackup->disk ?: config('db_backup.disk', 'azure_db_backups'))
                ->delete($databaseBackup->azure_path);
        } catch (\Throwable $e) {
            return back()->with('error', 'Could not delete the blob from Azure: '.$e->getMessage());
        }

        $databaseBackup->forceFill([
            'status' => DatabaseBackup::STATUS_PRUNED,
            'azure_path' => null,
            'pruned_at' => now(),
        ])->save();

        ActivityLog::create([
            'model_type' => 'DatabaseBackup', 'model_id' => $databaseBackup->id,
            'action' => 'db_backup_deleted',
            'changes' => ['filename' => $databaseBackup->filename],
            'user_id' => Auth::id(),
        ]);

        return back()->with('success', "Backup {$databaseBackup->filename} deleted from Azure (history kept).");
    }
}
