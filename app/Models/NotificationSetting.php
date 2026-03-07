<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationSetting extends Model
{
    protected $fillable = [
        'user_id',
        'notify_email',
        'notify_in_app',
    ];

    protected $casts = [
        'notify_email'  => 'boolean',
        'notify_in_app' => 'boolean',
    ];

    // ─────────────────────────────────────────────────────────────
    // Relationships
    // ─────────────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ─────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────

    public static function forUser(int $userId): static
    {
        return static::firstOrCreate(
            ['user_id' => $userId],
            ['notify_email' => true, 'notify_in_app' => true]
        );
    }
}
