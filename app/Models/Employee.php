<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    protected $fillable = [
        'azure_id',
        'oracle_emp_no',
        'oracle_dept_no',
        'oracle_department',
        'oracle_location',
        'mobile_phone',
        'oracle_synced_at',
        'name',
        'gender',
        'email',
        'branch_id',
        'department_id',
        'manager_id',
        'supervisor_id',
        'job_title',
        'status',
        'hired_date',
        'terminated_date',
        'azure_disabled_at',
        'azure_removed_at',
        'notes',
        'card_token',
        'work_phone',
        'office_location',
        'city',
        'street_address',
        'company',
        'extension_number',
        'ucm_server_id',
        'contact_id',
        'linked_primary_employee_id',
    ];

    protected $casts = [
        'hired_date' => 'date',
        'terminated_date' => 'date',
        'azure_disabled_at' => 'datetime',
        'azure_removed_at' => 'datetime',
        'oracle_synced_at' => 'datetime',
        'ucm_server_id' => 'integer',
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

    /**
     * Extra signature roles (additional job title + department for a person who
     * holds more than one role under the same mailbox). Each becomes a selectable
     * classic-Outlook signature; the employee's own title/department is the default.
     */
    public function signatureRoles(): HasMany
    {
        return $this->hasMany(EmployeeSignatureRole::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'supervisor_id');
    }

    /**
     * The primary employee this account inherits HR data from (dual-account users:
     * e.g. a SamirGroup mailbox whose job title / department / extension / mobile are
     * maintained on the person's SSS record). Null for normal, standalone employees.
     */
    public function linkedPrimary(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'linked_primary_employee_id');
    }

    /** Secondary accounts that inherit their HR data from this employee. */
    public function linkedSecondaries(): HasMany
    {
        return $this->hasMany(Employee::class, 'linked_primary_employee_id');
    }

    /**
     * The record HR fields (job title, department, extension, mobile, gender) should
     * be read from: the linked primary when this is a secondary account, else itself.
     * Branch, name, and email always stay this account's own.
     */
    public function hrSource(): Employee
    {
        return $this->linked_primary_employee_id ? ($this->linkedPrimary ?: $this) : $this;
    }

    public function isLinkedSecondary(): bool
    {
        return (bool) $this->linked_primary_employee_id;
    }

    public function supervisees(): HasMany
    {
        return $this->hasMany(Employee::class, 'supervisor_id');
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

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function items()
    {
        return $this->hasMany(\App\Models\EmployeeItem::class);
    }

    public function activeItems()
    {
        return $this->hasMany(\App\Models\EmployeeItem::class)->whereNull('returned_date');
    }

    public function accessoryAssignments(): HasMany
    {
        return $this->hasMany(AccessoryAssignment::class);
    }

    public function activeAccessoryAssignments(): HasMany
    {
        return $this->hasMany(AccessoryAssignment::class)->whereNull('returned_date');
    }

    public function licenseAssignments()
    {
        return LicenseAssignment::where('assignable_type', Employee::class)
            ->where('assignable_id', $this->id);
    }

    // ─────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            'active' => 'bg-success',
            'on_leave' => 'bg-warning text-dark',
            'terminated' => 'bg-danger',
            default => 'bg-secondary',
        };
    }

    public function initials(): string
    {
        $parts = explode(' ', trim($this->name));
        if (count($parts) >= 2) {
            return strtoupper(substr($parts[0], 0, 1).substr(end($parts), 0, 1));
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

    /**
     * Printers manually assigned to this employee by an admin.
     */
    public function assignedPrinters(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Printer::class, 'employee_printer')
            ->withPivot(['assigned_by', 'notes'])
            ->withTimestamps();
    }
}
