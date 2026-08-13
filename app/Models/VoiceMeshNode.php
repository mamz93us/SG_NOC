<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One branch in the synthetic call mesh: its own UCM, the probe extension the
 * prober registers as when impersonating it, and the IVR extension every other
 * branch dials to reach it.
 */
class VoiceMeshNode extends Model
{
    public const STATE_UP = 'up';

    public const STATE_DEGRADED = 'degraded';

    public const STATE_DOWN = 'down';

    public const STATE_UNKNOWN = 'unknown';

    protected $fillable = [
        'branch_id', 'code', 'name', 'ivr_ext',
        'sip_server', 'sip_port', 'sip_user', 'sip_pass',
        'is_active', 'sort_order', 'notes',
    ];

    protected $casts = [
        'sip_pass' => 'encrypted',
        'is_active' => 'boolean',
        'sip_port' => 'integer',
        'sort_order' => 'integer',
        'consecutive_failures' => 'integer',
        'state_changed_at' => 'datetime',
        'last_result_at' => 'datetime',
    ];

    /** Never let the SIP password ride along in an array/JSON cast. */
    protected $hidden = ['sip_pass'];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function outgoingPairs(): HasMany
    {
        return $this->hasMany(VoiceMeshPair::class, 'caller_node_id');
    }

    public function incomingPairs(): HasMany
    {
        return $this->hasMany(VoiceMeshPair::class, 'dest_node_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('code');
    }

    /**
     * The exact dict shape the prober's config validator expects. Defined once,
     * here, so the wire format has a single owner.
     *
     * @return array<string, mixed>
     */
    public function toProberEntry(): array
    {
        return [
            'name' => $this->code,
            'ext' => $this->ivr_ext,
            'sip_server' => $this->sip_server,
            'sip_port' => $this->sip_port,
            'sip_user' => $this->sip_user,
            'sip_pass' => $this->sip_pass,
        ];
    }

    /**
     * Everything except the SIP password, for ActivityLog writes — the audit
     * trail must not become an easier place to read credentials than the
     * encrypted column they live in.
     *
     * @return array<string, mixed>
     */
    public function redactedForLog(): array
    {
        return collect($this->attributesToArray())
            ->except(['sip_pass'])
            ->all();
    }

    public function stateBadgeClass(): string
    {
        return match ($this->state) {
            self::STATE_UP => 'bg-success',
            self::STATE_DEGRADED => 'bg-warning text-dark',
            self::STATE_DOWN => 'bg-danger',
            default => 'bg-secondary',
        };
    }

    public function stateLabel(): string
    {
        return match ($this->state) {
            self::STATE_UP => 'Up',
            self::STATE_DEGRADED => 'Degraded',
            self::STATE_DOWN => 'Down',
            default => 'Unknown',
        };
    }
}
