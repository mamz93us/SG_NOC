<?php

namespace App\Models\Vacation;

use App\Models\Attendance\Concerns\StoresPlainDates;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One import of Oracle's vacation data — an uploaded sheet today, an API call
 * later — and what it changed. Written by Services\Vacation\VacationImporter.
 *
 * `notes` lists the rows that could not be read, with their row numbers.
 */
class VacationImport extends Model
{
    use StoresPlainDates;

    public const KIND_BALANCES = 'balances';

    public const KIND_ABSENCES = 'absences';

    public const SOURCE_SHEET = 'sheet';

    public const SOURCE_API = 'api';

    protected array $plainDates = ['as_of', 'window_from', 'window_to'];

    protected $fillable = [
        'book',
        'kind',
        'source',
        'filename',
        'as_of',
        'window_from',
        'window_to',
        'rows',
        'created',
        'updated',
        'unchanged',
        'removed',
        'restored',
        'skipped',
        'unlinked',
        'notes',
        'imported_by',
    ];

    protected $casts = [
        'as_of' => 'date',
        'window_from' => 'date',
        'window_to' => 'date',
        'rows' => 'integer',
        'created' => 'integer',
        'updated' => 'integer',
        'unchanged' => 'integer',
        'removed' => 'integer',
        'restored' => 'integer',
        'skipped' => 'integer',
        'unlinked' => 'integer',
        'notes' => 'array',
    ];

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function kindLabel(): string
    {
        return $this->kind === self::KIND_BALANCES ? 'Balances' : 'Leave records';
    }

    /** One line for the flash message and the activity log. */
    public function summary(): string
    {
        $noun = $this->kind === self::KIND_BALANCES ? 'balances' : 'leave records';
        $parts = ["{$this->rows} rows read", "{$this->created} new {$noun}", "{$this->updated} changed", "{$this->unchanged} unchanged"];

        if ($this->kind === self::KIND_ABSENCES) {
            $parts[] = "{$this->removed} no longer in Oracle";
            if ($this->restored > 0) {
                $parts[] = "{$this->restored} back in Oracle";
            }
        }
        if ($this->skipped > 0) {
            $parts[] = "{$this->skipped} rows skipped";
        }
        if ($this->unlinked > 0) {
            $parts[] = "{$this->unlinked} people not linked to an employee";
        }

        return $this->kindLabel().': '.implode(', ', $parts).'.';
    }
}
