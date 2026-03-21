<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PrinterDeployToken extends Model
{
    protected $fillable = [
        'token',
        'printer_id',
        'employee_id',
        'sent_to_email',
        'printer_config',
        'used_at',
        'expires_at',
    ];

    protected $casts = [
        'printer_config' => 'array',
        'used_at'        => 'datetime',
        'expires_at'     => 'datetime',
    ];

    // ─── Relationships ────────────────────────────────────────────

    public function printer(): BelongsTo
    {
        return $this->belongsTo(Printer::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    // ─── Helpers ──────────────────────────────────────────────────

    public static function generate(int $printerId, array $attributes = []): self
    {
        return static::create(array_merge([
            'token'      => Str::random(48),
            'printer_id' => $printerId,
            'expires_at' => now()->addDays(14),
        ], $attributes));
    }

    public function isValid(): bool
    {
        return is_null($this->used_at)
            && (is_null($this->expires_at) || $this->expires_at->isFuture());
    }

    public function markUsed(): void
    {
        $this->update(['used_at' => now()]);
    }
}
