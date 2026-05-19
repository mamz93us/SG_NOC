<?php

namespace App\Models\EmailMarketing;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailTemplate extends Model
{
    protected $fillable = [
        'name', 'editor_type', 'design_json', 'rendered_html', 'preview_text', 'archived_at', 'created_by',
    ];

    protected $casts = [
        'archived_at' => 'datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(EmailCampaign::class);
    }

    public function designArray(): array
    {
        if (! $this->design_json) {
            return [];
        }
        $decoded = json_decode($this->design_json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
