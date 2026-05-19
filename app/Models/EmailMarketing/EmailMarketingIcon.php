<?php

namespace App\Models\EmailMarketing;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailMarketingIcon extends Model
{
    protected $table = 'email_marketing_icons';

    protected $fillable = [
        'name', 'label', 'svg_path', 'default_color', 'default_size',
        'sort_order', 'created_by',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Build a self-contained HTML snippet that pastes nicely into an Unlayer
     * HTML block. Uses stroke-based rendering so any color works.
     */
    public function toHtmlSnippet(?string $color = null, ?int $size = null): string
    {
        $c = $color ?: $this->default_color;
        $s = $size  ?: $this->default_size;

        return '<div style="text-align:center; padding:8px;">'
             . '<svg width="'.$s.'" height="'.$s.'" viewBox="0 0 24 24" fill="none" '
             . 'stroke="'.$c.'" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
             . '<path d="'.$this->svg_path.'"/></svg></div>';
    }
}
