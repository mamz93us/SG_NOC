<?php

namespace App\Models\Training;

use App\Models\EmailMarketing\EmailCampaign;
use App\Models\EmailMarketing\EmailTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Course extends Model
{
    protected $fillable = [
        'name',
        'description',
        'default_template_id',
        'default_subject',
        'default_preview_text',
        'default_from_email',
        'default_from_name',
        'default_reply_to',
        'created_by',
    ];

    public function certificates(): HasMany
    {
        return $this->hasMany(CourseCertificate::class);
    }

    public function defaultTemplate(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class, 'default_template_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(EmailCampaign::class);
    }
}
