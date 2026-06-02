<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Local cache of a GDMS configuration template (by model / group / site).
 * The `raw` column holds the template's parameter map as JSON.
 */
class GdmsTemplate extends Model
{
    protected $fillable = [
        'gdms_template_id',
        'name',
        'type',
        'model',
        'scope_ref',
        'raw',
        'synced_at',
    ];

    protected $casts = [
        'raw'       => 'array',
        'synced_at' => 'datetime',
    ];
}
