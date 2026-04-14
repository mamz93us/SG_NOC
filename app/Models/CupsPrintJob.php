<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CupsPrintJob extends Model
{
    protected $fillable = [
        'cups_printer_id',
        'user_id',
        'cups_job_id',
        'title',
        'status',
        'pages',
        'file_path',
        'cups_metadata',
    ];

    protected $casts = [
        'cups_metadata' => 'array',
        'pages'         => 'integer',
        'cups_job_id'   => 'integer',
    ];

    // ─── Relationships ────────────────────────────────────────

    public function cupsPrinter(): BelongsTo
    {
        return $this->belongsTo(CupsPrinter::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ─── Helpers ──────────────────────────────────────────────

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            'completed'  => 'bg-success',
            'processing' => 'bg-primary',
            'pending'    => 'bg-warning text-dark',
            'cancelled'  => 'bg-secondary',
            'error'      => 'bg-danger',
            default      => 'bg-secondary',
        };
    }
}
