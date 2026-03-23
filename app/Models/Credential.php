<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Credential extends Model
{
    protected $fillable = [
        'title',
        'username',
        'password',
        'url',
        'notes',
        'device_id',
        'category',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'password' => 'encrypted',
    ];

    public function getPasswordAttribute($value)
    {
        if (empty($value)) return '';
        try {
            return decrypt($value);
        } catch (\Exception $e) {
            \Log::error("Decryption failed for credential {$this->id} ({$this->title}). MAC probably invalid.");
            return '********';
        }
    }

    public function setPasswordAttribute($value)
    {
        $this->attributes['password'] = encrypt($value);
    }

    protected $hidden = ['password'];  // never included in toArray/toJson by default

    // ─── Relationships ────────────────────────────────────────────

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function accessLogs(): HasMany
    {
        return $this->hasMany(CredentialAccessLog::class)->orderByDesc('created_at');
    }

    // ─── Helpers ──────────────────────────────────────────────────

    public function categoryLabel(): string
    {
        return match ($this->category) {
            'admin'   => 'Admin',
            'api'     => 'API Key',
            'snmp'    => 'SNMP',
            'user'    => 'User',
            'service' => 'Service Account',
            default   => 'Other',
        };
    }

    public function categoryBadgeClass(): string
    {
        return match ($this->category) {
            'admin'   => 'bg-danger',
            'api'     => 'bg-primary',
            'snmp'    => 'bg-warning text-dark',
            'user'    => 'bg-info text-dark',
            'service' => 'bg-secondary',
            default   => 'bg-light text-dark border',
        };
    }

    /**
     * Log access to this credential.
     */
    public function logAccess(string $action, ?int $userId = null, ?string $ip = null): void
    {
        $this->accessLogs()->create([
            'user_id'    => $userId,
            'action'     => $action,
            'ip_address' => $ip,
        ]);
    }
}
