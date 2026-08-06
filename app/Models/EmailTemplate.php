<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An admin override of one of the emails catalogued in App\Support\EmailTemplates.
 *
 * Absent row, inactive row, or empty column all mean the same thing: send the
 * original Blade. That is what makes this table safe to add to a live system —
 * nothing changes until somebody deliberately saves an override, and deleting
 * the row is a complete undo.
 */
class EmailTemplate extends Model
{
    protected $fillable = [
        'template_key',
        'subject',
        'body_html',
        'is_active',
        'updated_by_user_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /** Per-request memo — a digest send resolves the same key many times. */
    private static array $cache = [];

    /** When set, for() reports "no override" — used by the editor's preview. */
    private static bool $suspended = false;

    /**
     * Run a callback as if no overrides existed at all, so the editor can show
     * the coded design side by side with the customised one.
     *
     * @template T
     *
     * @param  callable():T  $callback
     * @return T
     */
    public static function withoutOverrides(callable $callback)
    {
        $previous = self::$suspended;
        self::$suspended = true;

        try {
            return $callback();
        } finally {
            self::$suspended = $previous;
        }
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /**
     * The active override for a key, or null. Never throws: if the table has
     * not been migrated yet, every email falls back to its Blade.
     */
    public static function for(string $key): ?self
    {
        if (self::$suspended) {
            return null;
        }

        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }

        try {
            $row = static::where('template_key', $key)->where('is_active', true)->first();
        } catch (\Throwable) {
            $row = null;
        }

        return self::$cache[$key] = $row;
    }

    /** Custom subject line for a key, or null to keep the coded one. */
    public static function subjectFor(string $key): ?string
    {
        $subject = static::for($key)?->subject;

        return $subject !== null && trim($subject) !== '' ? $subject : null;
    }

    /** Custom body HTML for a key, or null to render the original view. */
    public static function bodyFor(string $key): ?string
    {
        $body = static::for($key)?->body_html;

        return $body !== null && trim($body) !== '' ? $body : null;
    }

    public static function flushCache(): void
    {
        self::$cache = [];
    }

    public function hasSubject(): bool
    {
        return $this->subject !== null && trim($this->subject) !== '';
    }

    public function hasBody(): bool
    {
        return $this->body_html !== null && trim((string) $this->body_html) !== '';
    }
}
