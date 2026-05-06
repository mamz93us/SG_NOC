<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-branch log-collector VM configuration.
 *
 * Each row maps a branch code (e.g. "jed") to the network endpoint
 * (host + port) and bearer token the NOC uses to query that branch's
 * /api/logs/search. Tokens are encrypted at rest via the cast.
 */
class BranchLogCollector extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'host',
        'port',
        'api_token',
        'enabled',
        'notes',
    ];

    protected $casts = [
        'api_token'          => 'encrypted',
        'enabled'            => 'boolean',
        'last_seen_at'       => 'datetime',
    ];

    /**
     * Hide the encrypted token from default API responses / array casts.
     * UI explicitly reads it when needed via $model->api_token.
     */
    protected $hidden = ['api_token'];

    /**
     * Convenience scope: only enabled rows that actually have a token set.
     */
    public function scopeReady($query)
    {
        return $query->where('enabled', true)->whereNotNull('api_token');
    }

    /**
     * Build the base URL for this collector's API.
     */
    public function baseUrl(): string
    {
        return sprintf('http://%s:%d', $this->host, $this->port);
    }

    /**
     * Mark health from a successful or failed probe.
     */
    public function markHealth(string $status, ?string $error = null): void
    {
        $this->forceFill([
            'last_health_status' => $status,
            'last_error'         => $error,
            'last_seen_at'       => $status === 'healthy' ? now() : $this->last_seen_at,
        ])->save();
    }
}
