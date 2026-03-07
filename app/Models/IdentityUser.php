<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IdentityUser extends Model
{
    protected $fillable = [
        'azure_id',
        'manager_azure_id',
        'display_name',
        'user_principal_name',
        'mail',
        'job_title',
        'department',
        'company_name',
        'account_enabled',
        'licenses_count',
        'groups_count',
        'usage_location',
        'phone_number',
        'mobile_phone',
        'office_location',
        'street_address',
        'city',
        'postal_code',
        'country',
        'assigned_licenses',
        'member_of',
        'raw_data',
    ];

    protected $casts = [
        'account_enabled'  => 'boolean',
        'assigned_licenses'=> 'array',
        'member_of'        => 'array',
        'raw_data'         => 'array',
        'licenses_count'   => 'integer',
        'groups_count'     => 'integer',
    ];

    public function statusBadgeClass(): string
    {
        return $this->account_enabled ? 'bg-success' : 'bg-danger';
    }

    public function statusLabel(): string
    {
        return $this->account_enabled ? 'Enabled' : 'Disabled';
    }

    public function initials(): string
    {
        $parts = explode(' ', $this->display_name);
        $init  = strtoupper(substr($parts[0] ?? '', 0, 1));
        if (count($parts) > 1) {
            $init .= strtoupper(substr($parts[count($parts) - 1], 0, 1));
        }
        return $init ?: '?';
    }
}
