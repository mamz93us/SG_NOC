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
     * Flexible helper to log an activity.
     * Supports:
     * - log($action)
     * - log($action, $model, $changes)
     * - log($action, $description, $status, $model_id)
     */
    public static function log(string $action, $arg2 = null, $arg3 = null, $arg4 = null): self
    {
        $userId = Auth::id() ?? 1;
        $modelType = 'System';
        $modelId   = 0;
        $changes   = null;

        if ($arg2 instanceof Model) {
            // Case: log($action, $model, $changes)
            $modelType = get_class($arg2);
            $modelId   = $arg2->id ?? 0;
            $changes   = is_array($arg3) ? $arg3 : null;
        } elseif (is_string($arg2) && is_string($arg3)) {
            // Case: log($type, $description, $status, $model_id)
            // We'll map $action to $type, $arg2 to description, etc.
            $modelType = $action;
            $action    = $arg2; // Use description as action for legacy display
            $modelId   = is_numeric($arg4) ? $arg4 : 0;
            $changes   = ['status' => $arg3];
        } elseif (is_array($arg3)) {
            // Case: log($action, $some_id_or_string, $changes)
            $modelType = is_string($arg2) ? $arg2 : 'System';
            $modelId   = is_numeric($arg2) ? (int)$arg2 : 0;
            $changes   = $arg3;
        }

        return self::create([
            'user_id'    => $userId,
            'model_type' => $modelType,
            'model_id'   => $modelId,
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
