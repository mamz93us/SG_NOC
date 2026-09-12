<?php

namespace App\Support\Audit;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * The one place an audit row is written.
 *
 * Three things the per-model observers it replaces got wrong:
 *
 *  1. They were wrapped in `if (Auth::check())`, so everything the scheduler
 *     and the queue did — identity sync, HR imports, Sophos sync, offboarding —
 *     left no trace at all. That is exactly the half of the system a reviewer
 *     most wants to read. Here an unauthenticated write is attributed to the
 *     console command (or `system`) instead of being dropped.
 *  2. `changes` was `$model->toArray()` on create and `getOriginal()` on
 *     update: a full row dump each time, including anything encrypted, and on
 *     update the "old" side listed every column rather than the changed ones.
 *  3. Nothing was redacted, so a password or API key rotation wrote the new
 *     secret into a table 30 people can read at /admin/activity-logs.
 */
class Auditor
{
    /** Suppression depth — see withoutAuditing(). */
    private static int $muted = 0;

    /**
     * Run a callback with auditing off.
     *
     * For bulk work where one summary row is the honest record and ten thousand
     * per-row rows are not (a full re-import, a rebuild). Nested calls are
     * counted, so an inner suppression can't re-enable the outer one.
     */
    public static function withoutAuditing(callable $callback): mixed
    {
        static::$muted++;

        try {
            return $callback();
        } finally {
            static::$muted--;
        }
    }

    public static function muted(): bool
    {
        return static::$muted > 0 || ! config('audit.enabled', true);
    }

    /**
     * Record a change to a model.
     *
     * @param  array<string,mixed>|null  $changes
     */
    public static function record(string $action, ?Model $model = null, ?array $changes = null): ?ActivityLog
    {
        if (static::muted()) {
            return null;
        }

        try {
            return ActivityLog::create([
                'model_type' => $model ? $model::class : 'System',
                'model_id' => $model ? (int) ($model->getKey() ?? 0) : 0,
                'action' => $action,
                'changes' => $changes,
                'user_id' => Auth::id(),
                'actor_label' => static::actorLabel(),
                'model_label' => $model ? static::describe($model) : null,
            ]);
        } catch (\Throwable) {
            // An audit write must never be the reason a request 500s. The
            // trade-off is deliberate and one-directional: losing a log line is
            // recoverable, failing the user's actual work is not.
            return null;
        }
    }

    /**
     * Who did this, in words, for the rows that have no user_id.
     *
     * A scheduled command is the actor for most unattended writes, and naming it
     * is the difference between "somebody changed this" and "the 03:00 Oracle HR
     * import changed this".
     */
    public static function actorLabel(): ?string
    {
        if (Auth::check()) {
            return null; // user_id covers it; don't duplicate.
        }

        if (app()->runningInConsole()) {
            $command = static::consoleCommand();

            return $command ? "console: {$command}" : 'console';
        }

        return 'system';
    }

    private static function consoleCommand(): ?string
    {
        // $_SERVER['argv'] is the only reliable source here: the Artisan
        // command instance isn't reachable from a model observer.
        $argv = $_SERVER['argv'] ?? [];

        foreach (array_slice($argv, 1) as $arg) {
            if (! str_starts_with($arg, '-')) {
                return substr($arg, 0, 80);
            }
        }

        return null;
    }

    /**
     * A human label for the record, so the audit page can say what was touched
     * without a join per row against 200 different tables.
     */
    public static function describe(Model $model): ?string
    {
        foreach (['name', 'title', 'label', 'hostname', 'email', 'slug', 'asset_code', 'serial_number', 'ip_address'] as $attr) {
            $value = $model->getAttribute($attr);

            if (is_string($value) && $value !== '') {
                return mb_substr($value, 0, 150);
            }
        }

        return null;
    }

    /**
     * The diff for an update: only what changed, old alongside new, secrets
     * redacted, noise columns dropped.
     *
     * Returns null when nothing audit-worthy changed, which is the signal to
     * skip the row entirely.
     *
     * @return array{old: array<string,mixed>, new: array<string,mixed>}|null
     */
    public static function diff(Model $model): ?array
    {
        $changed = $model->getChanges();
        $original = $model->getRawOriginal();

        foreach (static::ignored() as $column) {
            unset($changed[$column]);
        }

        if ($changed === []) {
            return null;
        }

        $old = [];
        $new = [];

        foreach ($changed as $key => $value) {
            $old[$key] = static::scrub($key, is_array($original) ? ($original[$key] ?? null) : null);
            $new[$key] = static::scrub($key, $value);
        }

        return ['old' => $old, 'new' => $new];
    }

    /**
     * The full attribute set for a create or delete, scrubbed.
     *
     * @return array<string,mixed>
     */
    public static function snapshot(Model $model): array
    {
        $out = [];

        foreach ($model->getAttributes() as $key => $value) {
            if (in_array($key, static::ignored(), true)) {
                continue;
            }

            $out[$key] = static::scrub($key, $value);
        }

        return $out;
    }

    /**
     * Redact by column NAME, never by inspecting the value — a secret that
     * happens to look innocuous still must not be written here.
     */
    public static function scrub(string $key, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        $needle = strtolower($key);

        foreach ((array) config('audit.redact', []) as $pattern) {
            if (str_contains($needle, strtolower($pattern))) {
                return '[redacted]';
            }
        }

        // Keep the row small: a 2 MB blob column would otherwise be copied into
        // the log on every save.
        if (is_string($value) && mb_strlen($value) > 2000) {
            return mb_substr($value, 0, 2000).'… ['.mb_strlen($value).' chars]';
        }

        if (is_array($value)) {
            $encoded = json_encode($value);

            if ($encoded !== false && strlen($encoded) > 4000) {
                return '['.count($value).' items, truncated]';
            }
        }

        return $value;
    }

    /** @return array<int,string> */
    private static function ignored(): array
    {
        return (array) config('audit.ignore', []);
    }
}
