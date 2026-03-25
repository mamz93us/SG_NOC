<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VqAlertEvent extends Model
{
    protected $fillable = [
        'source_type','source_ref','branch','metric','value','threshold','severity','message','resolved_at',
    ];

    protected $casts = ['resolved_at' => 'datetime'];

    public function scopeUnresolved($q) { return $q->whereNull('resolved_at'); }
}
