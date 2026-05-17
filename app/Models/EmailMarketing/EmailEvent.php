<?php

namespace App\Models\EmailMarketing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'ses_message_id', 'email_campaign_send_id', 'email_subscriber_id',
        'event_type', 'url', 'user_agent', 'ip_address',
        'bounce_type', 'bounce_subtype', 'complaint_type',
        'raw_payload', 'created_at',
    ];

    protected $casts = [
        'raw_payload' => 'array',
        'created_at' => 'datetime',
    ];

    public function send(): BelongsTo
    {
        return $this->belongsTo(EmailCampaignSend::class, 'email_campaign_send_id');
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(EmailSubscriber::class, 'email_subscriber_id');
    }
}
