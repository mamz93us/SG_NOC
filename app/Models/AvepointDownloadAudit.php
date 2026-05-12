<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AvepointDownloadAudit extends Model
{
    protected $fillable = [
        'avepoint_backup_id',
        'download_token',
        'user_id',
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
        return $this->belongsTo(AvepointBackup::class, 'avepoint_backup_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
