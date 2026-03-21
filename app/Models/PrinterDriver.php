<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrinterDriver extends Model
{
    protected $fillable = [
        'printer_id',
        'manufacturer',
        'model_pattern',
        'driver_name',
        'inf_path',
        'driver_file_path',
        'original_filename',
        'os_type',
        'version',
        'notes',
        'is_active',
        'uploaded_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // ─── Relationships ────────────────────────────────────────────

    public function printer(): BelongsTo
    {
        return $this->belongsTo(Printer::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    // ─── Scopes ───────────────────────────────────────────────────

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function scopeWindows(Builder $q): Builder
    {
        return $q->whereIn('os_type', ['windows_x64', 'windows_x86', 'universal']);
    }

    public function scopeMac(Builder $q): Builder
    {
        return $q->whereIn('os_type', ['mac', 'universal']);
    }

    // ─── Static Finder ────────────────────────────────────────────

    /**
     * Find the best matching driver for a printer + OS type.
     * Priority:
     * 1. Exact printer_id match + os_type + is_active
     * 2. manufacturer ILIKE + model_pattern wildcard match + os_type + is_active
     */
    public static function findForPrinter(Printer $printer, string $osType = 'windows_x64'): ?self
    {
        // Priority 1: exact printer match
        $driver = static::where('printer_id', $printer->id)
            ->where('os_type', $osType)
            ->where('is_active', true)
            ->first();

        if ($driver) {
            return $driver;
        }

        // Priority 2: manufacturer + model pattern match (no specific printer linked)
        $candidates = static::whereNull('printer_id')
            ->where('is_active', true)
            ->where('os_type', $osType)
            ->get();

        foreach ($candidates as $candidate) {
            $mfgMatch = ! $candidate->manufacturer
                || (
                    $printer->manufacturer &&
                    stripos($printer->manufacturer, $candidate->manufacturer) !== false
                );

            $patternMatch = ! $candidate->model_pattern
                || (
                    $printer->model &&
                    fnmatch($candidate->model_pattern, $printer->model, FNM_CASEFOLD)
                );

            if ($mfgMatch && $patternMatch) {
                return $candidate;
            }
        }

        return null;
    }

    // ─── Helpers ──────────────────────────────────────────────────

    public function osBadgeLabel(): string
    {
        return match ($this->os_type) {
            'windows_x64' => 'Win 64',
            'windows_x86' => 'Win 32',
            'mac'         => 'macOS',
            'universal'   => 'Universal',
            default       => $this->os_type,
        };
    }

    public function osBadgeClass(): string
    {
        return match ($this->os_type) {
            'windows_x64', 'windows_x86' => 'bg-primary',
            'mac'                         => 'bg-dark',
            'universal'                   => 'bg-success',
            default                       => 'bg-secondary',
        };
    }
}
