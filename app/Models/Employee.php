<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    protected $fillable = [
        'azure_id',
        'name',
        'email',
        'branch_id',
        'department_id',
        'manager_id',
        'job_title',
        'status',
        'hired_date',
        'terminated_date',
        'notes',
        'extension_number',
        'ucm_server_id',
    ];

    protected $casts = [
        'hired_date'      => 'date',
        'terminated_date' => 'date',
        'ucm_server_id'   => 'integer',
    ];

    // ─────────────────────────────────────────────────────────────
    // Relationships
    // ─────────────────────────────────────────────────────────────

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }

    public function subordinates(): HasMany
    {
        return $this->hasMany(Employee::class, 'manager_id');
    }

    public function assetAssignments(): HasMany
    {
        return $this->hasMany(EmployeeAsset::class);
    }

    public function activeAssets(): HasMany
    {
        return $this->hasMany(EmployeeAsset::class)->whereNull('returned_date');
    }

    public function identityUser(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(IdentityUser::class, 'azure_id', 'azure_id');
    }

    public function ucmServer()
    {
        return $this->belongsTo(\App\Models\UcmServer::class, 'ucm_server_id');
    }

    public function items()
    {
        return $this->hasMany(\App\Models\EmployeeItem::class);
    }

    public function activeItems()
    {
        return $this->hasMany(\App\Models\EmployeeItem::class)->whereNull('returned_date');
    }

    // ─────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            'active'     => 'bg-success',
            'on_leave'   => 'bg-warning text-dark',
            'terminated' => 'bg-danger',
            default      => 'bg-secondary',
        };
    }

    public function initials(): string
    {
        $parts = explode(' ', trim($this->name));
        if (count($parts) >= 2) {
            return strtoupper(substr($parts[0], 0, 1) . substr(end($parts), 0, 1));
        }
        return strtoupper(substr($this->name, 0, 2));
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    // ─────────────────────────────────────────────────────────────
    // Scopes
    // ─────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeTerminated($query)
    {
        return $query->where('status', 'terminated');
    }
}
