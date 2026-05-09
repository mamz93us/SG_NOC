<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrinterAlertEmail extends Model
{
    protected $fillable = [
        'noc_event_id',
        'printer_id',
        'to_emails',
        'cc_emails',
        'subject',
        'status',
        'error',
        'sent_at',
    ];

    protected $casts = [
        'to_emails' => 'array',
        'cc_emails' => 'array',
        'sent_at'   => 'datetime',
    ];

    public function nocEvent(): BelongsTo
    {
        return $this->belongsTo(NocEvent::class);
    }

    public function printer(): BelongsTo
    {
        return $this->belongsTo(Printer::class);
    }
}
