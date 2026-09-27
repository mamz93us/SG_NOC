<?php

namespace App\Models\Itam;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One import of Oracle's fixed-asset register and what it changed. Written by
 * Services\Itam\Oracle\OracleAssetImporter; `summary` holds the counts the
 * register page shows, `notes` the rows that could not be read.
 */
class OracleAssetImport extends Model
{
    protected $fillable = [
        'filename',
        'rows',
        'summary',
        'notes',
        'imported_by',
    ];

    protected $casts = [
        'rows' => 'integer',
        'summary' => 'array',
        'notes' => 'array',
    ];

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function stat(string $key): int
    {
        return (int) ($this->summary[$key] ?? 0);
    }

    /** One line for a flash message or the import history. */
    public function summaryLine(): string
    {
        $parts = [
            $this->rows.' rows read',
            $this->stat('computers').' laptops and desktops',
        ];

        if ($this->stat('skipped_other') > 0) {
            $parts[] = $this->stat('skipped_other').' other lines left out (software, monitors)';
        }

        $parts[] = $this->stat('created').' new, '.$this->stat('updated').' changed';

        if ($this->stat('removed') > 0) {
            $parts[] = $this->stat('removed').' no longer in Oracle';
        }

        $parts[] = $this->stat('linked_intune').' matched to Intune devices';
        $parts[] = $this->stat('created_devices').' new assets not in Intune';

        if ($this->stat('no_employee') > 0) {
            $parts[] = $this->stat('no_employee').' with a holder not in the NOC';
        }

        return implode(', ', $parts).'.';
    }
}
