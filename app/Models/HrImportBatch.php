<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrImportBatch extends Model
{
    protected $fillable = [
        'filename',
        'uploaded_by',
        'total_rows',
        'matched_count',
        'unmatched_count',
        'error_count',
        'applied_count',
        'status',
    ];

    protected $casts = [
        'total_rows' => 'integer',
        'matched_count' => 'integer',
        'unmatched_count' => 'integer',
        'error_count' => 'integer',
        'applied_count' => 'integer',
    ];

    public function rows(): HasMany
    {
        return $this->hasMany(HrImportRow::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Recompute the cached counts from the child rows.
     */
    public function refreshCounts(): void
    {
        $rows = $this->rows();

        $this->update([
            'total_rows' => (clone $rows)->count(),
            'matched_count' => (clone $rows)->whereIn('status', ['matched', 'applied', 'linked'])->count(),
            'unmatched_count' => (clone $rows)->where('status', 'unmatched')->count(),
            'error_count' => (clone $rows)->where('status', 'error')->count(),
            'applied_count' => (clone $rows)->whereIn('status', ['applied', 'created', 'linked'])->count(),
        ]);
    }
}
