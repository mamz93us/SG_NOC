<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DnsAccount extends Model
{
    protected $fillable = [
        'label',
        'api_key',
        'api_secret',
        'environment',
        'shopper_id',
        'notes',
        'is_active',
        'last_tested_at',
        'last_test_status',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'api_key'        => 'encrypted',
        'api_secret'     => 'encrypted',
        'is_active'      => 'boolean',
        'last_tested_at' => 'datetime',
    ];

    protected $hidden = ['api_key', 'api_secret'];

    // ─── Relationships ────────────────────────────────────────────

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // ─── Helpers ──────────────────────────────────────────────────

    public function baseUrl(): string
    {
        return $this->environment === 'ote'
            ? 'https://api.ote-godaddy.com'
            : 'https://api.godaddy.com';
    }

    public function authHeader(): string
    {
        return "sso-key {$this->api_key}:{$this->api_secret}";
    }

    public function maskedApiKey(): string
    {
        $key = $this->api_key;
        if (empty($key)) return '••••••••';
        return substr($key, 0, 4) . '••••••••';
    }

    public function environmentBadgeClass(): string
    {
        return match ($this->environment) {
            'ote'    => 'bg-warning text-dark',
            default  => 'bg-success',
        };
    }

    public function statusBadgeClass(): string
    {
        return match ($this->last_test_status) {
            'success' => 'bg-success',
            'failed'  => 'bg-danger',
            default   => 'bg-secondary',
        };
    }

    public function statusLabel(): string
    {
        return match ($this->last_test_status) {
            'success' => 'Connected',
            'failed'  => 'Error',
            default   => 'Untested',
        };
    }
}
