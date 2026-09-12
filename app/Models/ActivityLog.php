<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;


class ActivityLog extends Model
{
    protected $fillable = [
        'model_type',
        'model_id',
        'model_label',
        'action',
        'changes',
        'user_id',
        'actor_label',
        'ip_address',
        'user_agent',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $log) {
            // Attribute unattended writes to the command or to `system`, so a row
            // with no user_id still says who acted. Rows written by a signed-in
            // request leave this null — user_id already covers it.
            $log->actor_label ??= \App\Support\Audit\Auditor::actorLabel();

            if (app()->runningInConsole() && ! request()) {
                return;
            }
            try {
                $req = request();
                if ($req) {
                    $log->ip_address ??= $req->ip();
                    $log->user_agent ??= substr((string) $req->header('User-Agent'), 0, 512);
                }
            } catch (\Throwable) {
            }
        });
    }

    /**
     * Who acted, for display. Falls back to the console/system label so an
     * unattended change never renders as a blank cell.
     */
    public function actorName(): string
    {
        return $this->user?->name
            ?? $this->actor_label
            ?? 'system';
    }

    /**
     * The touched record, for display: the label captured at write time, else
     * the bare class and id.
     */
    public function subjectName(): string
    {
        $type = class_basename($this->model_type ?: 'System');

        if ($this->model_label) {
            return "{$type}: {$this->model_label}";
        }

        return $this->model_id ? "{$type} #{$this->model_id}" : $type;
    }

    /**
     * Security-relevant events — sign-ins, denials, and anything that moved a
     * permission. These are what a privilege-escalation review reads, and they
     * are kept longer than ordinary record edits (see config/audit.php).
     */
    public function scopeSecurity($query)
    {
        return $query->whereIn('action', (array) config('audit.security_actions', []));
    }

    protected $casts = [
        'changes' => 'array',
    ];

    /**
     * Flexible helper to log an activity.
     * Supports:
     * - log($action)
     * - log($action, $changes)
     * - log($action, $model, $changes)
     * - log($action, $description, $status, $model_id)
     */
    public static function log(string $action, $arg2 = null, $arg3 = null, $arg4 = null): self
    {
        // Unattended writes stay unattributed to a person. This used to fall back
        // to `User::orderBy('id')->first()`, which booked every scheduled sync and
        // webhook to whoever happened to sign up first — worse than no name at
        // all, because it reads as a real person's action. `actor_label`, set in
        // booted(), carries the command name instead.
        $userId = Auth::id();
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
        } elseif (is_array($arg2) && $arg3 === null) {
            // Case: log($action, $changes) — shorthand for system events
            $changes = $arg2;
        } elseif (is_array($arg3)) {
            // Case: log($action, $some_id_or_string, $changes)
            $modelType = is_string($arg2) ? $arg2 : 'System';
            $modelId   = is_numeric($arg2) ? (int)$arg2 : 0;
            $changes   = $arg3;
        }

        // action is VARCHAR(255); preserve the head, stash the rest in changes
        // so a long error message never crashes the request.
        if (mb_strlen($action) > 250) {
            $changes = is_array($changes) ? $changes : [];
            $changes['full_action'] = $action;
            $action = mb_substr($action, 0, 247) . '...';
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
