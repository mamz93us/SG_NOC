<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OffboardingDownloadAudit extends Model
{
    protected $fillable = [
        'offboarding_backup_id',
        'download_token',
        'ip',
        'user_agent',
        'bytes_sent',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'bytes_sent'   => 'integer',
        'started_at'   => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function backup(): BelongsTo
    {
        return $this->belongsTo(OffboardingBackup::class, 'offboarding_backup_id');
    }
}
