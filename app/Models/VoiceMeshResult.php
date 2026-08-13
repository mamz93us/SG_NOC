<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One leg's outcome on one run. Append-only history — written in bulk with a
 * raw insert, so there are no timestamps to maintain.
 */
class VoiceMeshResult extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'ok' => 'boolean',
        'rx_pkt' => 'integer',
        'duration_sec' => 'decimal:2',
        'reference_sec' => 'decimal:2',
        'checked_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(VoiceMeshRun::class, 'voice_mesh_run_id');
    }

    public function caller(): BelongsTo
    {
        return $this->belongsTo(VoiceMeshNode::class, 'caller_node_id');
    }

    public function dest(): BelongsTo
    {
        return $this->belongsTo(VoiceMeshNode::class, 'dest_node_id');
    }
}
