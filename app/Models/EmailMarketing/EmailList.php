<?php

namespace App\Models\EmailMarketing;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailList extends Model
{
    protected $fillable = [
        'name', 'description', 'double_opt_in', 'auto_domain',
        'default_from_email', 'default_from_name', 'default_reply_to',
        'created_by',
    ];

    protected $casts = [
        'double_opt_in' => 'boolean',
    ];

    public function isDynamic(): bool
    {
        return ! empty($this->auto_domain);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function subscribers(): BelongsToMany
    {
        return $this->belongsToMany(EmailSubscriber::class, 'email_list_subscriber')
            ->withPivot(['subscribed_at', 'unsubscribed_at', 'opt_in_token', 'opt_in_sent_at'])
            ->withTimestamps();
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(EmailCampaign::class);
    }

    public function activeSubscribersCount(): int
    {
        return $this->subscribers()
            ->wherePivotNull('unsubscribed_at')
            ->where('email_subscribers.status', 'subscribed')
            ->count();
    }
}
