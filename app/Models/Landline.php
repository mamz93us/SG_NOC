<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Landline extends Model
{
    protected $fillable = [
        'branch_id',
        'phone_number',
        'provider',
        'fxo_port',
        'gateway_id',
        'status',
        'notes',
    ];

    // ─── Relationships ──────────────────────────────────────────

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function gateway(): BelongsTo
    {
        return $this->belongsTo(UcmServer::class, 'gateway_id');
    }

    // ─── Helpers ────────────────────────────────────────────────

    public static function statuses(): array
    {
        return [
            'active'       => 'Active',
            'disconnected' => 'Disconnected',
            'spare'        => 'Spare',
        ];
    }

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            'active'       => 'bg-success',
            'disconnected' => 'bg-danger',
            'spare'        => 'bg-warning text-dark',
            default        => 'bg-secondary',
        };
    }
}
