<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    protected $fillable = [
        'model_type',
        'model_id',
        'action',
        'changes',
        'user_id',
    ];

    protected $casts = [
        'changes' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the model that was logged
     */
    public function model()
    {
        return $this->morphTo();
    }
}
