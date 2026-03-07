<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CredentialAccessLog extends Model
{
    public $timestamps  = false;         // only created_at column exists
    public $updatedAt   = null;

    protected $fillable = [
        'credential_id',
        'user_id',
        'action',
        'ip_address',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function credential(): BelongsTo
    {
        return $this->belongsTo(Credential::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function actionBadgeClass(): string
    {
        return match ($this->action) {
            'viewed'  => 'bg-info text-dark',
            'copied'  => 'bg-warning text-dark',
            'created' => 'bg-success',
            'edited'  => 'bg-primary',
            'deleted' => 'bg-danger',
            default   => 'bg-secondary',
        };
    }
}
