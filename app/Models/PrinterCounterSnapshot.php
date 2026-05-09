<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrinterCounterSnapshot extends Model
{
    protected $fillable = [
        'printer_id',
        'snapshot_date',
        'page_total',
        'page_color',
        'page_mono',
        'page_copy',
        'page_print',
        'page_scan',
        'page_fax',
    ];

    protected $casts = [
        'snapshot_date' => 'date',
        'page_total'    => 'integer',
        'page_color'    => 'integer',
        'page_mono'     => 'integer',
        'page_copy'     => 'integer',
        'page_print'    => 'integer',
        'page_scan'     => 'integer',
        'page_fax'      => 'integer',
    ];

    public function printer(): BelongsTo
    {
        return $this->belongsTo(Printer::class);
    }
}
