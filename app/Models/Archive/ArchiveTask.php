<?php

namespace App\Models\Archive;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Work a page asked for that must not happen inside the request.
 *
 * The same arrangement as App\Models\Attendance\AttendanceTask, which exists
 * because doing this work inline held a PHP-FPM worker until nginx returned a
 * 504. Buttons on the Transfer and AI pages create a row here; `archive:work`
 * drains it every minute.
 */
class ArchiveTask extends Model
{
    /** Re-download a sample of transferred files and compare them. */
    public const TYPE_VERIFY_SAMPLE = 'verify_sample';

    /** Clear the error on failed transfers so they are picked up again. */
    public const TYPE_RETRY_FAILED = 'retry_failed';

    /** Re-read an archive's fields and settings from the ArcMate share. */
    public const TYPE_RESCAN_SOURCE = 'rescan_source';

    /** Recount documents, files and bytes for the archive cards. */
    public const TYPE_RECOUNT = 'recount';

    // No AI task type here on purpose: an AI batch is its own queue
    // (archive_ai_batches, worked by archive:ai-batch), because it carries an
    // estimate, a running cost and a pause that a generic task row cannot. A
    // type here would be a second, wrong way to start one.

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'type',
        'params',
        'status',
        'result',
        'error',
        'requested_by',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'params' => 'array',
        'result' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function scopePending(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), self::STATUS_PENDING);
    }

    /**
     * Queue a task, unless the same one is already waiting.
     *
     * Buttons get clicked twice; a second identical sweep or recount is pure
     * waste, so an equal pending row is reused rather than duplicated.
     */
    public static function queue(string $type, array $params = [], ?int $requestedBy = null): self
    {
        $existing = static::pending()->where('type', $type)->get()
            ->first(fn (self $task) => ($task->params ?? []) == $params);

        if ($existing) {
            return $existing;
        }

        return static::create([
            'type' => $type,
            'params' => $params,
            'status' => self::STATUS_PENDING,
            'requested_by' => $requestedBy,
        ]);
    }

    public function markRunning(): void
    {
        $this->forceFill(['status' => self::STATUS_RUNNING, 'started_at' => now()])->save();
    }

    public function markDone(array $result = []): void
    {
        $this->forceFill([
            'status' => self::STATUS_DONE,
            'result' => $result,
            'error' => null,
            'finished_at' => now(),
        ])->save();
    }

    public function markFailed(string $error): void
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'error' => mb_substr($error, 0, 2000),
            'finished_at' => now(),
        ])->save();
    }
}
