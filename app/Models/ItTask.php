<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

class ItTask extends Model
{
    protected $table = 'it_tasks';

    protected $fillable = [
        'title',
        'description',
        'type',
        'priority',
        'status',
        'assigned_to',
        'created_by',
        'branch_id',
        'due_date',
        'estimated_hours',
        'logged_hours',
        'related_type',
        'related_id',
        'completed_at',
    ];

    protected $casts = [
        'due_date'        => 'date',
        'completed_at'    => 'datetime',
        'estimated_hours' => 'decimal:1',
        'logged_hours'    => 'decimal:1',
    ];

    // ─── Relationships ─────────────────────────────────────────

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(ItTaskComment::class, 'task_id');
    }

    // ─── Scopes ────────────────────────────────────────────────

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', '!=', 'done');
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('due_date', '<', Carbon::today())
                     ->where('status', '!=', 'done');
    }

    public function scopeAssignedTo(Builder $query, int $userId): Builder
    {
        return $query->where('assigned_to', $userId);
    }

    // ─── Boot ──────────────────────────────────────────────────

    protected static function booted(): void
    {
        static::saving(function (ItTask $task) {
            if ($task->isDirty('status')) {
                if ($task->status === 'done' && $task->getOriginal('status') !== 'done') {
                    $task->completed_at = now();
                } elseif ($task->status !== 'done' && $task->getOriginal('status') === 'done') {
                    $task->completed_at = null;
                }
            }
        });
    }
}
