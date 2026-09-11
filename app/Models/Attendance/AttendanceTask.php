<?php

namespace App\Models\Attendance;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A piece of attendance work a page asked for, done in the background by
 * `attendance:work` — never inline, where it held a PHP-FPM worker for
 * minutes and ended in a 504.
 *
 *   sync     {source_id}                 read new punches from one source
 *   rebuild  {from, to, employee_ids?}   recalculate days (null ids = everyone)
 *   relink   {source_id?}                retry matching unmapped codes
 */
class AttendanceTask extends Model
{
    public const PENDING = 'pending';

    public const RUNNING = 'running';

    public const DONE = 'done';

    public const FAILED = 'failed';

    protected $fillable = [
        'type',
        'payload',
        'label',
        'status',
        'attempts',
        'result',
        'requested_by',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'attempts' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** Queues work — unless the very same work is already waiting, so repeated clicks queue it once. */
    public static function queue(string $type, array $payload, string $label, ?int $userId = null): self
    {
        $waiting = static::query()
            ->where('type', $type)
            ->where('status', self::PENDING)
            ->get()
            ->first(fn (self $task) => $task->payload == $payload);

        return $waiting ?? static::create([
            'type' => $type,
            'payload' => $payload,
            'label' => mb_substr($label, 0, 255),
            'status' => self::PENDING,
            'requested_by' => $userId,
        ]);
    }
}
