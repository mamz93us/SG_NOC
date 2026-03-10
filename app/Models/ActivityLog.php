<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;


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

    /**
     * Helper to log an activity.
     */
    public static function log(string $action, $model = null, ?array $changes = null): self
    {
        return self::create([
            'user_id'    => Auth::id() ?? 1,
            'model_type' => $model ? (is_string($model) ? $model : get_class($model)) : 'System',
            'model_id'   => $model ? (is_numeric($model) ? $model : ($model->id ?? 0)) : 0,
            'action'     => $action,
            'changes'    => $changes,
        ]);
    }

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
