<?php

namespace App\Models\EmailMarketing;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailCampaign extends Model
{
    protected $fillable = [
        'name', 'subject', 'preview_text',
        'from_email', 'from_name', 'reply_to',
        'email_template_id', 'email_list_id', 'email_segment_id', 'course_id',
        'status', 'scheduled_at', 'started_at', 'sent_at', 'archived_at',
        'requires_approval', 'submitted_for_approval_at',
        'approved_by', 'approved_at', 'rejected_by', 'rejected_at', 'rejection_reason',
        'total_recipients', 'total_sent', 'total_delivered',
        'total_opens', 'total_unique_opens',
        'total_clicks', 'total_unique_clicks',
        'total_bounces', 'total_complaints', 'total_unsubscribes',
        'created_by',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'sent_at' => 'datetime',
        'archived_at' => 'datetime',
        'requires_approval' => 'boolean',
        'submitted_for_approval_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class, 'email_template_id');
    }

    public function list(): BelongsTo
    {
        return $this->belongsTo(EmailList::class, 'email_list_id');
    }

    public function segment(): BelongsTo
    {
        return $this->belongsTo(EmailSegment::class, 'email_segment_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Training\Course::class, 'course_id');
    }

    public function sends(): HasMany
    {
        return $this->hasMany(EmailCampaignSend::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function isEditable(): bool
    {
        // 'scheduled' is intentionally NOT editable: a campaign that has cleared the
        // approval gate (or was internal-only) must not be silently re-pointed at
        // external recipients while queued. Pause it first — editing a paused
        // campaign and re-sending re-runs the approval check.
        return in_array($this->status, ['draft', 'paused']);
    }

    public function isPendingApproval(): bool
    {
        return $this->status === 'pending_approval';
    }

    public function wasRejected(): bool
    {
        return $this->rejected_at !== null && $this->status === 'draft';
    }

    public function deliveryRate(): float
    {
        return $this->total_sent > 0
            ? round($this->total_delivered / $this->total_sent * 100, 2)
            : 0.0;
    }

    public function openRate(): float
    {
        return $this->total_delivered > 0
            ? round($this->total_unique_opens / $this->total_delivered * 100, 2)
            : 0.0;
    }

    public function clickRate(): float
    {
        return $this->total_delivered > 0
            ? round($this->total_unique_clicks / $this->total_delivered * 100, 2)
            : 0.0;
    }

    public function bounceRate(): float
    {
        return $this->total_sent > 0
            ? round($this->total_bounces / $this->total_sent * 100, 2)
            : 0.0;
    }

    public function complaintRate(): float
    {
        return $this->total_sent > 0
            ? round($this->total_complaints / $this->total_sent * 100, 2)
            : 0.0;
    }
}
