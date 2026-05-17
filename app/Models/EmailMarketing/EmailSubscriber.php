<?php

namespace App\Models\EmailMarketing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailSubscriber extends Model
{
    protected $fillable = [
        'email', 'first_name', 'last_name', 'status', 'source',
        'attributes', 'confirmed_at', 'unsubscribed_at',
        'bounced_at', 'last_bounce_type', 'complained_at',
    ];

    protected $casts = [
        'attributes' => 'array',
        'confirmed_at' => 'datetime',
        'unsubscribed_at' => 'datetime',
        'bounced_at' => 'datetime',
        'complained_at' => 'datetime',
    ];

    public function lists(): BelongsToMany
    {
        return $this->belongsToMany(EmailList::class, 'email_list_subscriber')
            ->withPivot(['subscribed_at', 'unsubscribed_at', 'opt_in_token', 'opt_in_sent_at'])
            ->withTimestamps();
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(EmailTag::class, 'email_subscriber_tag');
    }

    public function sends(): HasMany
    {
        return $this->hasMany(EmailCampaignSend::class);
    }

    public function fullName(): string
    {
        return trim(($this->first_name ?? '').' '.($this->last_name ?? '')) ?: $this->email;
    }

    public function isReachable(): bool
    {
        return $this->status === 'subscribed';
    }
}
