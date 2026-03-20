<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkflowTemplate extends Model
{
    protected $fillable = [
        'type_slug', 'display_name', 'description',
        'approval_chain', 'is_system', 'is_active',
        'definition', 'trigger_event', 'version',
    ];

    protected $casts = [
        'approval_chain' => 'array',
        'definition'     => 'array',
        'is_system'      => 'boolean',
        'is_active'      => 'boolean',
        'version'        => 'integer',
    ];

    public function versions(): HasMany
    {
        return $this->hasMany(WorkflowTemplateVersion::class, 'template_id')->orderByDesc('version');
    }

    /**
     * Snapshot the current definition into version history and increment the version counter.
     */
    public function createVersion(int $changedBy): void
    {
        WorkflowTemplateVersion::create([
            'template_id'    => $this->id,
            'version'        => $this->version,
            'definition'     => $this->definition,
            'approval_chain' => $this->approval_chain,
            'changed_by'     => $changedBy,
        ]);

        $this->increment('version');
    }

    /**
     * Parse definition nodes to rebuild the approval_chain array (keeps legacy engine working).
     */
    public function extractApprovalChain(): array
    {
        if (empty($this->definition)) {
            return $this->approval_chain ?? ['it_manager'];
        }

        $nodes = data_get($this->definition, 'drawflow.Home.data', []);
        $chain = [];

        foreach ($nodes as $node) {
            if (($node['name'] ?? '') === 'approval') {
                $role = $node['data']['role'] ?? 'it_manager';
                if ($role) {
                    $chain[] = $role;
                }
            }
        }

        return empty($chain) ? ($this->approval_chain ?? ['it_manager']) : $chain;
    }

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
