<?php

namespace App\Http\Controllers\Archive;

use App\Http\Controllers\Controller;
use App\Models\Archive\Archive;
use App\Models\Archive\ArchiveFile;
use App\Models\Archive\ArchiveSource;
use App\Models\Archive\ArchiveTask;
use App\Models\Archive\ArchiveTransferRun;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * The Transfer page: moving ArcMate's 372 GB into the NOC's Azure storage.
 *
 * Nothing on this page copies anything. Every button either changes a setting
 * the worker reads on its next wake, or queues an ArchiveTask — the same rule
 * as attendance, which learned it by holding PHP-FPM workers until nginx
 * returned 504s. The worker runs every minute, so a button takes effect within
 * about a minute.
 *
 * On honesty about progress: ArcMate recorded arcFileSize = 0 for every file,
 * so the bytes still to move are genuinely unknown until each file is copied.
 * Progress is therefore counted in FILES, which is exact, and anything derived
 * from size is labelled an estimate rather than dressed up as a fact.
 */
class TransferController extends Controller
{
    public function index(Request $request): View
    {
        $source = ArchiveSource::query()->first();
        $totals = $this->totals();
        $perArchive = $this->perArchive();

        // Average size of what HAS moved, used to estimate what is left. Only
        // meaningful once a reasonable number of files have gone across.
        $averageBytes = $totals['done'] > 0 ? $totals['bytes_done'] / $totals['done'] : null;
        $estimatedRemaining = $averageBytes ? (int) round($averageBytes * $totals['pending']) : null;
        $bytesPerSecond = ArchiveTransferRun::recentBytesPerSecond();

        return view('archive.manage.transfer', [
            'source' => $source,
            'totals' => $totals,
            'archives' => $perArchive,
            'estimatedRemaining' => $estimatedRemaining,
            'bytesPerSecond' => $bytesPerSecond,
            'estimatedSeconds' => ($bytesPerSecond && $estimatedRemaining)
                ? (int) round($estimatedRemaining / max(1, $bytesPerSecond))
                : null,
            'allowedNow' => (bool) $source?->transferAllowedNow(),
            'failed' => ArchiveFile::query()->transferFailed()->with('archive')->limit(50)->get(),
            'failedCount' => ArchiveFile::query()->transferFailed()->count(),
            'runs' => ArchiveTransferRun::query()->orderByDesc('id')->limit(10)->get(),
            'lastVerify' => ArchiveTask::query()
                ->where('type', ArchiveTask::TYPE_VERIFY_SAMPLE)
                ->where('status', ArchiveTask::STATUS_DONE)
                ->latest('id')->first(),
        ]);
    }

    /** The window, the cap, and whether the transfer runs at all. */
    public function saveSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'transfer_enabled' => ['nullable', 'boolean'],
            'transfer_anytime' => ['nullable', 'boolean'],
            'transfer_weekend_all_day' => ['nullable', 'boolean'],
            'transfer_window_start' => ['nullable', 'date_format:H:i'],
            'transfer_window_end' => ['nullable', 'date_format:H:i'],
            'transfer_speed_mbps' => ['nullable', 'integer', 'min:0', 'max:10000'],
        ]);

        $source = ArchiveSource::query()->first();

        abort_unless($source, 404);

        $source->forceFill([
            'transfer_enabled' => (bool) ($data['transfer_enabled'] ?? false),
            'transfer_anytime' => (bool) ($data['transfer_anytime'] ?? false),
            'transfer_weekend_all_day' => (bool) ($data['transfer_weekend_all_day'] ?? false),
            'transfer_window_start' => $data['transfer_window_start'] ?: '19:00',
            'transfer_window_end' => $data['transfer_window_end'] ?: '07:00',
            'transfer_speed_mbps' => ($data['transfer_speed_mbps'] ?? 0) ?: null,
        ])->save();

        return back()->with('status', 'Transfer settings saved. They apply on the next run, within a minute.');
    }

    /** Pause, resume, or push one archive to the front of the queue. */
    public function archiveAction(Request $request, Archive $archive): RedirectResponse
    {
        $action = $request->validate(['action' => ['required', 'in:pause,resume,now']])['action'];

        if ($action === 'pause') {
            $archive->forceFill(['transfer_paused' => true])->save();

            return back()->with('status', $archive->displayName().' paused. The current file finishes first.');
        }

        if ($action === 'resume') {
            $archive->forceFill(['transfer_paused' => false])->save();

            return back()->with('status', $archive->displayName().' resumed.');
        }

        // "Transfer now" is a reordering, not a separate run: the worker takes
        // the lowest priority first, so this simply puts the archive ahead of
        // everything else next time it wakes.
        $highest = (int) Archive::query()->min('transfer_priority');

        $archive->forceFill(['transfer_paused' => false, 'transfer_priority' => $highest - 1])->save();

        return back()->with('status', $archive->displayName().' moved to the front of the queue.');
    }

    /** Clear the errors so failed files are tried again. */
    public function retry(Request $request): RedirectResponse
    {
        $archiveId = $request->validate(['archive_id' => ['nullable', 'integer']])['archive_id'] ?? null;

        ArchiveTask::queue(
            ArchiveTask::TYPE_RETRY_FAILED,
            array_filter(['archive_id' => $archiveId]),
            $request->user()?->getKey(),
        );

        return back()->with('status', 'Queued a retry of the failed files.');
    }

    /**
     * Check a sample of what has already been transferred.
     *
     * Queued, because it reads whole files back out of Azure. This is the
     * question that has to be answered before the ArcMate server is switched
     * off: not "did the write succeed" but "is the copy still there and still
     * right".
     */
    public function verify(Request $request): RedirectResponse
    {
        $count = (int) ($request->validate(['count' => ['nullable', 'integer', 'min:1', 'max:200']])['count'] ?? 20);

        ArchiveTask::queue(ArchiveTask::TYPE_VERIFY_SAMPLE, ['count' => $count], $request->user()?->getKey());

        return back()->with('status', "Queued a check of {$count} transferred files.");
    }

    // ─── Figures ─────────────────────────────────────────────────

    /** @return array{done:int, pending:int, failed:int, bytes_done:int, total:int} */
    private function totals(): array
    {
        $rows = DB::table('archive_files')
            ->selectRaw('disk, COUNT(*) AS files, COALESCE(SUM(size), 0) AS bytes')
            ->groupBy('disk')
            ->get()
            ->keyBy('disk');

        $done = (int) ($rows[ArchiveFile::DISK_AZURE]->files ?? 0);
        $pending = (int) ($rows[ArchiveFile::DISK_ARCMATE]->files ?? 0);

        return [
            'done' => $done,
            'pending' => $pending,
            'failed' => (int) ArchiveFile::query()->transferFailed()->count(),
            'bytes_done' => (int) ($rows[ArchiveFile::DISK_AZURE]->bytes ?? 0),
            'total' => $done + $pending,
        ];
    }

    /**
     * Per-archive progress, counted in files.
     *
     * @return \Illuminate\Support\Collection<int,object>
     */
    private function perArchive()
    {
        $counts = DB::table('archive_files')
            ->selectRaw('archive_id, disk, COUNT(*) AS files, COALESCE(SUM(size), 0) AS bytes')
            ->groupBy('archive_id', 'disk')
            ->get();

        return Archive::query()->orderBy('transfer_priority')->orderBy('name')->get()
            ->map(function (Archive $archive) use ($counts) {
                $mine = $counts->where('archive_id', $archive->getKey());
                $done = (int) ($mine->firstWhere('disk', ArchiveFile::DISK_AZURE)->files ?? 0);
                $pending = (int) ($mine->firstWhere('disk', ArchiveFile::DISK_ARCMATE)->files ?? 0);
                $total = $done + $pending;

                return (object) [
                    'archive' => $archive,
                    'done' => $done,
                    'pending' => $pending,
                    'total' => $total,
                    'bytes_done' => (int) ($mine->firstWhere('disk', ArchiveFile::DISK_AZURE)->bytes ?? 0),
                    'percent' => $total > 0 ? (int) round($done / $total * 100) : 0,
                ];
            });
    }
}
