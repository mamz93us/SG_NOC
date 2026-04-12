<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InternetAccessLevel extends Model
{
    protected $fillable = [
        'label',
        'description',
        'azure_group_id',
        'azure_group_name',
        'is_default',
        'sort_order',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    /**
     * Ordered scope for display.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('label');
    }
}
