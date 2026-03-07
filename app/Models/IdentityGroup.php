<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IdentityGroup extends Model
{
    protected $fillable = [
        'azure_id',
        'display_name',
        'description',
        'members_count',
        'group_type',
        'mail_enabled',
        'security_enabled',
    ];

    protected $casts = [
        'mail_enabled'     => 'boolean',
        'security_enabled' => 'boolean',
        'members_count'    => 'integer',
    ];

    public function typeBadgeClass(): string
    {
        if ($this->group_type === 'Unified') return 'bg-primary';
        if ($this->security_enabled)         return 'bg-warning text-dark';
        return 'bg-secondary';
    }

    public function typeLabel(): string
    {
        if ($this->group_type === 'Unified') return 'M365';
        if ($this->security_enabled)         return 'Security';
        return 'Distribution';
    }
}
