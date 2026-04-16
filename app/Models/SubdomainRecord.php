<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubdomainRecord extends Model
{
    protected $fillable = [
        'account_id', 'domain', 'subdomain', 'fqdn',
        'ip_address', 'ttl', 'godaddy_synced', 'ssl_certificate_id', 'created_by',
    ];

    protected $casts = [
        'godaddy_synced' => 'boolean',
        'ttl'            => 'integer',
    ];

    // ─── Relationships ────────────────────────────────────────────

    public function account(): BelongsTo
    {
        return $this->belongsTo(DnsAccount::class, 'account_id');
    }

    public function sslCertificate(): BelongsTo
    {
        return $this->belongsTo(SslCertificate::class, 'ssl_certificate_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ─── Helpers ──────────────────────────────────────────────────

    public function isNocIp(): bool
    {
        return $this->ip_address === config('noc.server_ip', env('NOC_SERVER_IP', ''));
    }
}
