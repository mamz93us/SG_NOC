<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkflowTemplate extends Model
{
    protected $fillable = [
        'type_slug', 'display_name', 'description',
        'approval_chain', 'is_system', 'is_active',
    ];

    protected $casts = [
        'approval_chain' => 'array',
        'is_system'      => 'boolean',
        'is_active'      => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function approvalChainLabels(): array
    {
        $labels = [
            'hr'          => 'HR',
            'it_manager'  => 'IT Manager',
            'manager'     => 'Manager',
            'security'    => 'Security',
            'super_admin' => 'Super Admin',
        ];
        return array_map(fn($r) => $labels[$r] ?? ucfirst($r), $this->approval_chain ?? []);
    }

    public function chainBadgeClass(string $role): string
    {
        return match ($role) {
            'hr'          => 'primary',
            'it_manager'  => 'info',
            'manager'     => 'secondary',
            'security'    => 'warning',
            'super_admin' => 'danger',
            default       => 'dark',
        };
    }
}
