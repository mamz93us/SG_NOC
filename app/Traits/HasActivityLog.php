<?php

namespace App\Traits;

use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;

trait HasActivityLog
{
    public static function bootHasActivityLog(): void
    {
        static::created(function ($model) {
            if (Auth::check()) {
                ActivityLog::create([
                    'model_type' => class_basename($model),
                    'model_id'   => $model->getKey(),
                    'action'     => 'created',
                    'changes'    => $model->toArray(),
                    'user_id'    => Auth::id(),
                ]);
            }
        });

        static::updated(function ($model) {
            if (Auth::check()) {
                ActivityLog::create([
                    'model_type' => class_basename($model),
                    'model_id'   => $model->getKey(),
                    'action'     => 'updated',
                    'changes'    => [
                        'old' => $model->getOriginal(),
                        'new' => $model->getChanges(),
                    ],
                    'user_id'    => Auth::id(),
                ]);
            }
        });

        static::deleted(function ($model) {
            if (Auth::check()) {
                ActivityLog::create([
                    'model_type' => class_basename($model),
                    'model_id'   => $model->getKey(),
                    'action'     => 'deleted',
                    'changes'    => $model->toArray(),
                    'user_id'    => Auth::id(),
                ]);
            }
        });
    }
}
