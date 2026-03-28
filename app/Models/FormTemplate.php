<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class FormTemplate extends Model
{
    protected $fillable = [
        'name', 'slug', 'description', 'type', 'visibility',
        'schema', 'settings', 'workflow_template_id', 'workflow_payload_map',
        'created_by', 'is_active', 'expires_at',
    ];

    protected $casts = [
        'schema'               => 'array',
        'settings'             => 'array',
        'workflow_payload_map' => 'array',
        'is_active'            => 'boolean',
        'expires_at'           => 'datetime',
    ];

    // ─── Relationships ────────────────────────────────────────────

    public function submissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class, 'form_id')->orderByDesc('created_at');
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(FormToken::class, 'form_id')->orderByDesc('created_at');
    }

    public function workflowTemplate(): BelongsTo
    {
        return $this->belongsTo(WorkflowTemplate::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ─── Helpers ──────────────────────────────────────────────────

    public function isOpen(): bool
    {
        return $this->is_active
            && (is_null($this->expires_at) || $this->expires_at->isFuture());
    }

    public static function generateSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $i    = 2;
        while (static::where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i++;
        }
        return $slug;
    }

    public function visibilityBadgeClass(): string
    {
        return match ($this->visibility) {
            'public'     => 'bg-success',
            'private'    => 'bg-secondary',
            'token_only' => 'bg-warning text-dark',
            default      => 'bg-secondary',
        };
    }

    public function typeBadgeClass(): string
    {
        return match ($this->type) {
            'feedback' => 'bg-info text-dark',
            'survey'   => 'bg-primary',
            'request'  => 'bg-warning text-dark',
            'intake'   => 'bg-secondary',
            default    => 'bg-secondary',
        };
    }

    /** Default settings applied when creating a new form */
    public static function defaultSettings(): array
    {
        return [
            'confirmation_message' => 'Thank you! Your response has been recorded.',
            'redirect_url'         => null,
            'allow_anonymous'      => false,
            'collect_email'        => false,
            'one_per_token'        => true,
            'max_submissions'      => null,
            'notify_user_ids'      => [],
            'submit_label'         => 'Submit',
        ];
    }
}
