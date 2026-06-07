<?php

namespace App\Observers;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Audit observer for the email-marketing models. Logs create / update / delete by
 * an AUTHENTICATED user — i.e. real actions in the marketing portal or the NOC
 * admin — to the shared activity_logs table, where they show up in
 * NOC → Activity Logs, filterable by model type (EmailCampaign, EmailList, …).
 *
 * System-driven changes (the CLI send pipeline, public open/click + SNS webhooks)
 * run without an authenticated user, so they are NOT logged here. That keeps the
 * audit about who DID what, not the machine's bookkeeping (counter bumps, status
 * flips to sending/sent, etc.).
 */
class EmailMarketingActivityObserver
{
    /** When true, model events are not logged (used to silence bulk imports). */
    public static bool $silent = false;

    /** Large/derived fields recorded as "[changed]" instead of dumping the blob. */
    private const REDACT = ['design_json', 'rendered_html'];

    /** Noisy system-maintained fields excluded from the diff entirely. */
    private const IGNORE = [
        'created_at', 'updated_at',
        'total_recipients', 'total_sent', 'total_delivered',
        'total_opens', 'total_unique_opens', 'total_clicks', 'total_unique_clicks',
        'total_bounces', 'total_complaints', 'total_unsubscribes',
        'started_at', 'sent_at',
    ];

    public function created(Model $model): void
    {
        $this->record($model, 'created', ['attributes' => $this->scrub($model->getAttributes())]);
    }

    public function updated(Model $model): void
    {
        $changes = $this->scrub($model->getChanges());
        if ($changes === []) {
            return; // only system-maintained fields changed — nothing to audit
        }

        $old = $this->scrub(array_intersect_key($model->getOriginal(), $model->getChanges()));
        $this->record($model, 'updated', ['old' => $old, 'new' => $changes]);
    }

    public function deleted(Model $model): void
    {
        $this->record($model, 'deleted', ['attributes' => $this->scrub($model->getAttributes())]);
    }

    private function record(Model $model, string $action, array $changes): void
    {
        if (self::$silent || ! Auth::check()) {
            return;
        }

        ActivityLog::create([
            'model_type' => class_basename($model),
            'model_id' => $model->getKey(),
            'action' => $action,
            'changes' => $changes,
            'user_id' => Auth::id(),
        ]);
    }

    /**
     * @param  array<string,mixed>  $attributes
     * @return array<string,mixed>
     */
    private function scrub(array $attributes): array
    {
        foreach (self::REDACT as $field) {
            if (array_key_exists($field, $attributes)) {
                $attributes[$field] = '[changed]';
            }
        }

        return array_diff_key($attributes, array_flip(self::IGNORE));
    }

    /** Run $callback without writing per-model audit rows (for bulk imports). */
    public static function silently(callable $callback): mixed
    {
        $previous = self::$silent;
        self::$silent = true;

        try {
            return $callback();
        } finally {
            self::$silent = $previous;
        }
    }
}
