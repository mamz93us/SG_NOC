<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CupsPrinter extends Model
{
    protected $fillable = [
        'name',
        'queue_name',
        'ip_address',
        'port',
        'protocol',
        'ipp_path',
        'branch_id',
        'driver',
        'location',
        'is_shared',
        'is_active',
        'status',
        'last_checked_at',
    ];

    protected $casts = [
        'is_shared'       => 'boolean',
        'is_active'       => 'boolean',
        'port'            => 'integer',
        'last_checked_at' => 'datetime',
    ];

    // ─── Relationships ────────────────────────────────────────

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function printJobs(): HasMany
    {
        return $this->hasMany(CupsPrintJob::class);
    }

    // ─── Helpers ──────────────────────────────────────────────

    /**
     * Build the CUPS device URI used by lpadmin to reach the real printer.
     */
    public function getCupsUri(): string
    {
        if ($this->protocol === 'socket') {
            return "socket://{$this->ip_address}:{$this->port}";
        }

        if ($this->protocol === 'lpd') {
            return "lpd://{$this->ip_address}/{$this->queue_name}";
        }

        // ipp / ipps
        return "{$this->protocol}://{$this->ip_address}:{$this->port}{$this->ipp_path}";
    }

    /**
     * Public IPP address that clients (mobile/PC) use to connect.
     */
    public function getIppAddress(): string
    {
        $domain = Setting::get()->cups_ipp_domain ?? 'localhost';

        return "ipp://{$domain}:631/printers/{$this->queue_name}";
    }

    /**
     * Check if the printer is considered online.
     */
    public function isOnline(): bool
    {
        return in_array($this->status, ['online', 'idle', 'printing']);
    }

    /**
     * Status badge CSS class.
     */
    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            'online', 'idle' => 'bg-success',
            'printing'       => 'bg-primary',
            'offline'        => 'bg-danger',
            'error'          => 'bg-danger',
            default          => 'bg-secondary',
        };
    }

    // ─── Scopes ───────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
