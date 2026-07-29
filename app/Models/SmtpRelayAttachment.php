<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An attachment on a relayed message. Filename/MIME come from Postfix
 * header_checks; per-file size_bytes is filled by the milter (Phase B).
 */
class SmtpRelayAttachment extends Model
{
    protected $fillable = [
        'smtp_relay_message_id',
        'filename',
        'mime',
        'size_bytes',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(SmtpRelayMessage::class, 'smtp_relay_message_id');
    }

    public function humanSize(): string
    {
        return SmtpRelayMessage::formatBytes($this->size_bytes);
    }
}
