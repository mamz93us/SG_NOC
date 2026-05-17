<?php

namespace App\Models\EmailMarketing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailCampaignSend extends Model
{
    protected $fillable = [
        'email_campaign_id', 'email_subscriber_id', 'ses_message_id',
        'status', 'sent_at', 'delivered_at', 'error_message',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(EmailCampaign::class, 'email_campaign_id');
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(EmailSubscriber::class, 'email_subscriber_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(EmailEvent::class, 'email_campaign_send_id');
    }
}
