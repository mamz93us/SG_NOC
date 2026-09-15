<?php

namespace App\Models\Archive;

use Illuminate\Database\Eloquent\Model;

/**
 * One slice of the transfer to Azure.
 *
 * Written every time the worker wakes and does something, which is what turns
 * "roughly 4 to 10 nights" into a real number on the Transfer page: the speed
 * shown there is measured from these rows rather than estimated, because
 * somebody watching 372 GB move deserves better than a guess.
 *
 * Excluded from the automatic audit (config/audit.php) — it is telemetry about
 * a background worker, not something a person did.
 */
class ArchiveTransferRun extends Model
{
    protected $fillable = [
        'started_at',
        'finished_at',
        'files_done',
        'bytes_done',
        'files_failed',
        'avg_mbps',
        'note',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'files_done' => 'integer',
        'bytes_done' => 'integer',
        'files_failed' => 'integer',
        'avg_mbps' => 'decimal:2',
    ];

    /**
     * Bytes a second, averaged over recent runs that actually moved something.
     *
     * Runs that did nothing (outside the window, nothing queued) are skipped
     * rather than averaged in as zero — including them would report a speed of
     * nearly nought all day and a wildly wrong time remaining.
     */
    public static function recentBytesPerSecond(int $runs = 20): ?float
    {
        $rows = static::query()
            ->where('bytes_done', '>', 0)
            ->whereNotNull('finished_at')
            ->orderByDesc('id')
            ->limit($runs)
            ->get(['started_at', 'finished_at', 'bytes_done']);

        if ($rows->isEmpty()) {
            return null;
        }

        $bytes = 0;
        $seconds = 0.0;

        foreach ($rows as $row) {
            $elapsed = $row->finished_at && $row->started_at
                ? max(1, $row->finished_at->diffInSeconds($row->started_at))
                : 0;

            if ($elapsed > 0) {
                $bytes += (int) $row->bytes_done;
                $seconds += $elapsed;
            }
        }

        return $seconds > 0 ? $bytes / $seconds : null;
    }
}
