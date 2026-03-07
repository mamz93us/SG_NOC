<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model
{
    use HasFactory;

    // Manual IDs
    public $incrementing = false;
    protected $keyType = 'int';

    protected $fillable = [
        'id',
        'name',
        'phone_number',
        'ucm_server_id',
        'ext_range_start',
        'ext_range_end',
        'profile_office_template',
        'profile_phone_template',
    ];

    protected $casts = [
        'ext_range_start' => 'integer',
        'ext_range_end'   => 'integer',
    ];

    // ─── Relationships ────────────────────────────────────────────

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function networkFloors(): HasMany
    {
        return $this->hasMany(NetworkFloor::class)->orderBy('sort_order')->orderBy('name');
    }

    public function networkSwitches(): HasMany
    {
        return $this->hasMany(NetworkSwitch::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function ucmServer(): BelongsTo
    {
        return $this->belongsTo(UcmServer::class, 'ucm_server_id');
    }

    // ─── Provisioning Helpers ─────────────────────────────────────
    // Each method returns the branch-specific value, falling back to
    // the global Setting when the branch field is null.

    public function effectiveUcmServer(Setting $settings): ?UcmServer
    {
        return $this->ucmServer
            ?? ($settings->default_ucm_id ? UcmServer::find($settings->default_ucm_id) : null);
    }

    public function effectiveExtRange(Setting $settings): array
    {
        return [
            'start' => $this->ext_range_start ?? (int) ($settings->ext_range_start ?? 1000),
            'end'   => $this->ext_range_end   ?? (int) ($settings->ext_range_end   ?? 1999),
        ];
    }

    public function effectiveOfficeTemplate(Setting $settings): ?string
    {
        return $this->profile_office_template ?? $settings->profile_office_template;
    }

    public function effectivePhoneTemplate(Setting $settings): ?string
    {
        return $this->profile_phone_template ?? $settings->profile_phone_template;
    }
}
