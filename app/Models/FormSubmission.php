<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FormSubmission extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'form_id', 'token_id', 'submitted_by', 'submitter_email',
        'ip_address', 'data', 'status',
        'reviewer_notes', 'reviewed_by', 'reviewed_at',
        'workflow_request_id',
    ];

    protected $casts = [
        'data'        => 'array',
        'reviewed_at' => 'datetime',
        'created_at'  => 'datetime',
    ];

    // ─── Relationships ────────────────────────────────────────────

    public function form(): BelongsTo
    {
        return $this->belongsTo(FormTemplate::class, 'form_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function token(): BelongsTo
    {
        return $this->belongsTo(FormToken::class, 'token_id');
    }

    public function workflowRequest(): BelongsTo
    {
        return $this->belongsTo(WorkflowRequest::class);
    }

    // ─── Helpers ──────────────────────────────────────────────────

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            'new'      => 'bg-primary',
            'reviewed' => 'bg-info text-dark',
            'actioned' => 'bg-success',
            'closed'   => 'bg-secondary',
            default    => 'bg-secondary',
        };
    }

    /** Return the value for a specific field name from submission data */
    public function field(string $name): mixed
    {
        return $this->data[$name] ?? null;
    }
}
