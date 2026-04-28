<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int         $id
 * @property string      $nasname       IP or CIDR (e.g. 10.10.4.5 or 10.10.4.0/24)
 * @property string      $shortname
 * @property string      $type          cisco|aruba|meraki|mikrotik|other
 * @property string      $secret        RADIUS shared secret
 * @property string|null $description
 * @property int|null    $branch_id
 * @property bool        $is_active
 */
class RadiusNasClient extends Model
{
    protected $fillable = [
        'nasname',
        'shortname',
        'type',
        'secret',
        'description',
        'branch_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'branch_id' => 'integer',
    ];

    protected $hidden = [
        'secret',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Display the secret as masked except for the last 4 characters.
     * Used in the admin list — the raw secret is never rendered.
     */
    public function maskedSecret(): string
    {
        $s = (string) $this->secret;
        $len = strlen($s);
        if ($len === 0) return '';
        if ($len <= 4) return str_repeat('•', $len);
        return str_repeat('•', max(4, $len - 4)) . substr($s, -4);
    }

    public function typeBadge(): string
    {
        return match ($this->type) {
            'cisco'    => 'primary',
            'aruba'    => 'warning',
            'meraki'   => 'success',
            'mikrotik' => 'info',
            default    => 'secondary',
        };
    }
}
