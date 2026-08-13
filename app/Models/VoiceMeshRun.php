<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One report POSTed by the prober — a whole sweep of the mesh.
 */
class VoiceMeshRun extends Model
{
    protected $guarded = [];

    protected $casts = [
        'ok' => 'boolean',
        'pairs_total' => 'integer',
        'pairs_ok' => 'integer',
        'pairs_failed' => 'integer',
        'nodes_total' => 'integer',
        'reported_at' => 'datetime',
        'received_at' => 'datetime',
        'unknown_nodes' => 'array',
        'payload' => 'array',
    ];

    public function results(): HasMany
    {
        return $this->hasMany(VoiceMeshResult::class, 'voice_mesh_run_id');
    }

    public function scopeRecent($query)
    {
        return $query->orderByDesc('received_at');
    }

    public function okPercent(): ?float
    {
        if (! $this->pairs_total) {
            return null;
        }

        return round($this->pairs_ok / $this->pairs_total * 100, 1);
    }
}
