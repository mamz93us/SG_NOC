<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SslCertificate extends Model
{
    protected $fillable = [
        'account_id', 'domain', 'fqdn', 'issuer', 'status',
        'certificate', 'private_key', 'csr', 'acme_account_key',
        'challenge_type', 'issued_at', 'expires_at', 'auto_renew',
        'last_renewed_at', 'failure_reason', 'created_by',
    ];

    protected $casts = [
        'certificate'     => 'encrypted',
        'private_key'     => 'encrypted',
        'csr'             => 'encrypted',
        'acme_account_key'=> 'encrypted',
        'issued_at'       => 'datetime',
        'expires_at'      => 'datetime',
        'last_renewed_at' => 'datetime',
        'auto_renew'      => 'boolean',
    ];

    protected $hidden = ['certificate', 'private_key', 'csr', 'acme_account_key'];

    // ─── Relationships ────────────────────────────────────────────

    public function account(): BelongsTo
    {
        return $this->belongsTo(DnsAccount::class, 'account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function subdomainRecord(): HasOne
    {
        return $this->hasOne(SubdomainRecord::class, 'ssl_certificate_id');
    }

    // ─── Helpers ──────────────────────────────────────────────────

    public function daysUntilExpiry(): ?int
    {
        if (!$this->expires_at) return null;
        return (int) now()->diffInDays($this->expires_at, false);
    }

    public function expiryStatus(): string
    {
        if (!$this->expires_at) return 'unknown';
        $days = $this->daysUntilExpiry();
        if ($days < 0)  return 'expired';
        if ($days < 14) return 'expiring_soon';
        return 'valid';
    }

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            'valid'         => 'bg-success',
            'pending'       => 'bg-primary',
            'failed'        => 'bg-danger',
            'revoked'       => 'bg-secondary',
            'expired'       => 'bg-danger',
            default         => 'bg-secondary',
        };
    }

    public function expiryBadgeClass(): string
    {
        return match ($this->expiryStatus()) {
            'expired'       => 'bg-danger',
            'expiring_soon' => 'bg-warning text-dark',
            'valid'         => 'bg-success',
            default         => 'bg-secondary',
        };
    }

    public function issuerLabel(): string
    {
        return match ($this->issuer) {
            'letsencrypt' => "Let's Encrypt",
            'zerossl'     => 'ZeroSSL',
            'manual'      => 'Manual',
            default       => ucfirst($this->issuer),
        };
    }
}
