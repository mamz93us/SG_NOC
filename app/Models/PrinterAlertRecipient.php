<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrinterAlertRecipient extends Model
{
    protected $fillable = [
        'branch_id',
        'user_id',
        'email',
        'name',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Resolved address — prefers the linked User's email, falls back to the raw `email` column.
     */
    public function effectiveEmail(): ?string
    {
        if ($this->user && $this->user->email) {
            return $this->user->email;
        }
        return $this->email;
    }

    /**
     * Display name — User name first, then the row's `name`, then the email.
     */
    public function effectiveName(): ?string
    {
        return $this->user?->name ?? $this->name ?? $this->email;
    }
}
