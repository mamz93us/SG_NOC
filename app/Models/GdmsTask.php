<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A record of an async GDMS device task (reboot / factory reset / config push /
 * account assignment / upgrade) requested from the NOC.
 */
class GdmsTask extends Model
{
    public const TYPE_REBOOT = 'reboot';

    public const TYPE_FACTORY_RESET = 'factory_reset';

    public const TYPE_CONFIG_PUSH = 'config_push';

    public const TYPE_ASSIGN_ACCOUNT = 'assign_account';

    public const TYPE_UPGRADE = 'upgrade';

    protected $fillable = [
        'mac',
        'device_id',
        'task_type',
        'gdms_task_id',
        'status',
        'payload',
        'result',
        'requested_by_user_id',
    ];

    protected $casts = [
        'payload' => 'array',
        'result' => 'array',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }
}
