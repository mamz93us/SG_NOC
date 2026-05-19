<?php

namespace App\Models\EmailMarketing;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailMarketingFont extends Model
{
    protected $table = 'email_marketing_fonts';

    protected $fillable = [
        'label', 'family', 'source', 'url', 'sort_order', 'is_default', 'created_by',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Shape for Unlayer's `customFonts` init option.
     */
    public function toUnlayerCustomFont(): array
    {
        return [
            'label' => $this->label,
            'value' => $this->family,
            'url'   => (string) $this->url,
        ];
    }
}
