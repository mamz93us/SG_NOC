<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExchangeRate extends Model
{
    protected $fillable = [
        'currency', 'units_per_base', 'rate_date', 'source', 'updated_by_user_id',
    ];

    protected $casts = [
        'units_per_base' => 'decimal:6',
        'rate_date' => 'date',
    ];

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /** How old the rate is, in days — what makes a report's figure suspect. */
    public function ageInDays(): int
    {
        return (int) $this->rate_date->startOfDay()->diffInDays(now()->startOfDay());
    }

    public function isStale(int $days = 45): bool
    {
        return $this->ageInDays() > $days;
    }
}
