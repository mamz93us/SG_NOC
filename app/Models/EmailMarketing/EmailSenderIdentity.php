<?php

namespace App\Models\EmailMarketing;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailSenderIdentity extends Model
{
    protected $fillable = [
        'email', 'name', 'reply_to', 'is_default', 'is_active', 'notes', 'created_by',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active'  => 'boolean',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }

    public function fullAddress(): string
    {
        return $this->name." <{$this->email}>";
    }
}
