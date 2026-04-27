<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class SyslogMessage extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'received_at', 'device_time', 'facility', 'severity',
        'host', 'source_ip', 'program', 'message', 'raw',
        'source_type', 'source_id', 'processed_at',
    ];

    protected $casts = [
        'received_at'  => 'datetime',
        'device_time'  => 'datetime',
        'processed_at' => 'datetime',
        'facility'     => 'integer',
        'severity'     => 'integer',
        'source_id'    => 'integer',
    ];

    // ─── Severity helpers (RFC 5424) ──────────────────────────────────────
    public const SEVERITIES = [
        0 => 'emerg',
        1 => 'alert',
        2 => 'crit',
        3 => 'err',
        4 => 'warning',
        5 => 'notice',
        6 => 'info',
        7 => 'debug',
    ];

    public function severityLabel(): string
    {
        return self::SEVERITIES[$this->severity] ?? (string) $this->severity;
    }

    public function severityBadgeClass(): string
    {
        return match (true) {
            $this->severity <= 2 => 'bg-danger',
            $this->severity <= 4 => 'bg-warning text-dark',
            $this->severity == 5 => 'bg-info text-dark',
            default              => 'bg-secondary',
        };
    }

    public function sourceTypeBadgeClass(): string
    {
        return match ($this->source_type) {
            'sophos'  => 'bg-primary',
            'cisco'   => 'bg-success',
            'ucm'     => 'bg-info text-dark',
            'printer' => 'bg-secondary',
            'vps'     => 'bg-dark',
            default   => 'bg-light text-dark border',
        };
    }

    // ─── Scopes ──────────────────────────────────────────────────────────
    public function scopeUnprocessed(Builder $q): Builder
    {
        return $q->whereNull('processed_at');
    }

    public function scopeRecent(Builder $q, int $minutes = 60): Builder
    {
        return $q->where('received_at', '>=', now()->subMinutes($minutes));
    }
}
