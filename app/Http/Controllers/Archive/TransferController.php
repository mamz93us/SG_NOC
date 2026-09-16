<?php

namespace App\Http\Controllers\Archive;

use App\Http\Controllers\Controller;
use App\Models\Archive\Archive;
use App\Models\Archive\ArchiveFile;
use App\Models\Archive\ArchiveSource;
use App\Models\Archive\ArchiveTask;
use App\Models\Archive\ArchiveTransferRun;
use App\Services\Archive\ArchiveTransferService;
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

        return view('admin.archive.transfer', [
            'source' => $source,
            'totals' => $totals,
            'archives' => $perArchive,
            'estimatedRemaining' => $estimatedRemaining,
            'bytesPerSecond' => $bytesPerSecond,
            'estimatedSeconds' => ($bytesPerSecond && $estimatedRemaining)
                ? (int) round($estimatedRemaining / max(1, $bytesPerSecond))
                : null,
            'allowedNow' => (bool) $source?->transferAllowedNow(),
            'blockers' => $this->blockers($source, $totals),
            'pendingInRange' => $this->pendingInRange($source),
            'failed' => ArchiveFile::query()->transferFailed()->with('archive')->limit(50)->get(),
            'failedCount' => ArchiveFile::query()->transferFailed()->count(),
            'runs' => ArchiveTransferRun::query()->orderByDesc('id')->limit(10)->get(),
            'lastVerify' => ArchiveTask::query()
                ->where('type', ArchiveTask::TYPE_VERIFY_SAMPLE)
                ->where('status', ArchiveTask::STATUS_DONE)
                ->latest('id')->first(),
        ]);
    }

    /**
     * Every reason nothing is moving, in the order the worker meets them.
     *
     * The page used to show "0 file(s) waiting" and a progress bar at 0%, which
     * is what a finished transfer looks like as well as a blocked one. Worse, the
     * one blocker that stops everything — the storage being unreachable — was
     * only ever a log line, and at a level production does not record.
     *
     * Deliberately ALL of them rather than the first: a person fixing one and
     * finding it still idle has learned nothing.
     *
     * @return array<int,string>
     */
    private function blockers(?ArchiveSource $source, array $totals): array
    {
        if (! $source) {
            return ['There is no ArcMate connection set up yet, so there is nothing to transfer from.'];
        }

        if ($totals['pending'] === 0 && $totals['total'] > 0) {
            return []; // finished, which is not a blocker
        }

        $blockers = [];

        if (! $source->transfer_enabled) {
            $blockers[] = 'The transfer is switched off. Tick "Transfer switched on" below.';
        } elseif (! $source->transferAllowedNow()) {
            $blockers[] = sprintf(
                'Outside the transfer window (%s–%s). It will start on its own, or tick "Allow during working hours".',
                $source->transfer_window_start ?: '19:00',
                $source->transfer_window_end ?: '07:00',
            );
        }

        // The one that was invisible. Checked here because this is the page
        // somebody opens when nothing is happening.
        if ($problem = (new ArchiveTransferService)->storageProblem()) {
            $blockers[] = 'The NOC\'s Azure storage cannot be reached, so there is nowhere to copy to: '.$problem;
        }

        // Files left, but every archive holding them is paused. Counted against
        // the queue scope, which is what the worker actually reads.
        $paused = Archive::query()
            ->where('transfer_paused', true)
            ->whereExists(fn ($q) => $q->select(DB::raw(1))
                ->from('archive_files')
                ->whereColumn('archive_files.archive_id', 'archives.id')
                ->where('archive_files.disk', ArchiveFile::DISK_ARCMATE))
            ->get()
            ->map(fn (Archive $a) => $a->displayName());

        $queued = ArchiveFile::query()->transferQueue()->count();

        if ($queued === 0 && $paused->isNotEmpty()) {
            $blockers[] = 'Paused, so nothing is queued: '.$paused->implode(', ').'. Resume it in the table below.';
        }

        // A date range that excludes everything left is the other way the page
        // reads as idle while being correctly configured.
        if ($queued > 0 && $this->pendingInRange($source) === 0) {
            $blockers[] = sprintf(
                'The scanned-date range (%s to %s) leaves out all %s remaining file(s). Widen it, or clear both dates.',
                $source->transfer_from ?: 'the beginning',
                $source->transfer_to ?: 'today',
                number_format($queued),
            );
        }

        return $blockers;
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
            'transfer_from' => ['nullable', 'date_format:Y-m-d'],
            'transfer_to' => ['nullable', 'date_format:Y-m-d'],
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
            // Blank means no limit at that end, so a one-sided range works:
            // "everything from 2024 onwards" is a from with no to.
            'transfer_from' => $data['transfer_from'] ?: null,
            'transfer_to' => $data['transfer_to'] ?: null,
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

    /**
     * Files still to move that fall inside the chosen date range.
     *
     * Null when no range is set, because the overall pending figure already says
     * it. With a range, showing only the global total would be misleading: the
     * worker is not going to move those files tonight, and the page should not
     * imply that it will.
     */
    private function pendingInRange(?ArchiveSource $source): ?int
    {
        if (! $source || (! $source->transfer_from && ! $source->transfer_to)) {
            return null;
        }

        return ArchiveFile::query()
            ->transferQueue()
            ->join('archive_documents', 'archive_documents.id', '=', 'archive_files.archive_document_id')
            ->when($source->transfer_from, fn ($q, $d) => $q->where('archive_documents.captured_at', '>=', $d.' 00:00:00'))
            ->when($source->transfer_to, fn ($q, $d) => $q->where('archive_documents.captured_at', '<=', $d.' 23:59:59'))
            ->count();
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
