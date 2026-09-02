<?php

namespace App\Models;

use App\Models\EmailMarketing\EmailCampaignSend;
use App\Models\EmailMarketing\EmailEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One message SES told us about — a queryable projection of email_events.
 *
 * Lives in App\Models (not App\Models\EmailMarketing) on purpose: this covers
 * every message the AWS account sends, transactional NOC alerts included, not
 * just campaigns.
 */
class EmailMessage extends Model
{
    protected $fillable = [
        'ses_message_id',
        'from_email', 'from_name', 'to_email', 'recipients', 'recipient_count',
        'subject', 'source', 'email_campaign_send_id',
        'status', 'bounce_type', 'bounce_subtype', 'complaint_type', 'failure_reason',
        'open_count', 'click_count',
        'sent_at', 'delivered_at', 'opened_at', 'clicked_at', 'last_event_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'opened_at' => 'datetime',
        'clicked_at' => 'datetime',
        'last_event_at' => 'datetime',
        'recipient_count' => 'integer',
        'open_count' => 'integer',
        'click_count' => 'integer',
    ];

    /**
     * Delivery states in the order SES can move through them. A later state
     * never regresses to an earlier one — events can arrive out of order, and
     * a Delivery landing after a Bounce must not repaint the row green.
     */
    public const STATUS_RANK = [
        'sent' => 0,
        'delivered' => 1,
        'rejected' => 2,
        'failed' => 2,
        'complained' => 3,
        'bounced' => 4,
    ];

    // ─── Scopes ───────────────────────────────────────────────────

    public function scopeProblem($query)
    {
        return $query->whereIn('status', ['bounced', 'complained', 'rejected', 'failed']);
    }

    // ─── Relationships ────────────────────────────────────────────

    /** Every SES event for this message, joined the way the table is indexed. */
    public function events(): HasMany
    {
        return $this->hasMany(EmailEvent::class, 'ses_message_id', 'ses_message_id')
            ->orderBy('created_at');
    }

    public function campaignSend(): BelongsTo
    {
        return $this->belongsTo(EmailCampaignSend::class, 'email_campaign_send_id');
    }

    // ─── Helpers ──────────────────────────────────────────────────

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            'delivered' => 'bg-success',
            'bounced' => 'bg-danger',
            'complained' => 'bg-warning text-dark',
            'rejected', 'failed' => 'bg-danger',
            default => 'bg-secondary',
        };
    }

    public function sourceBadgeClass(): string
    {
        return $this->source === 'marketing' ? 'bg-info text-dark' : 'bg-secondary';
    }

    public function wasOpened(): bool
    {
        return $this->open_count > 0;
    }

    /** "name@example.com" or the display name when SES gave us one. */
    public function fromLabel(): string
    {
        return $this->from_name
            ? "{$this->from_name} <{$this->from_email}>"
            : (string) $this->from_email;
    }

    public function recipientLabel(): string
    {
        if ($this->recipient_count > 1) {
            return $this->to_email.' +'.($this->recipient_count - 1);
        }

        return (string) $this->to_email;
    }
}
