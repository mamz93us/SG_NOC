<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PrinterBranchSetting extends Model
{
    protected $fillable = [
        'branch_id',
        'manager_email',
        'manager_name',
        'alerts_enabled',
        'toner_warning_threshold',
        'toner_critical_threshold',
        'waste_critical_threshold',
        'notes',
    ];

    protected $casts = [
        'alerts_enabled'          => 'boolean',
        'toner_warning_threshold'  => 'integer',
        'toner_critical_threshold' => 'integer',
        'waste_critical_threshold' => 'integer',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(PrinterAlertRecipient::class, 'branch_id', 'branch_id');
    }

    public function activeRecipients(): HasMany
    {
        return $this->recipients()->where('is_active', true);
    }
}
