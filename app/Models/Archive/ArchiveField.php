<?php

namespace App\Models\Archive;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One searchable field of an archive — "Invoice Number", "Supplier Name".
 *
 * For a mirrored archive these are read from ArcMate's own arcDesign.xml, where
 * `DBName` names the column the value sits in on tblDocuments (S1, S2, D1, C1).
 * That mapping is kept in `arcmate_column` so the sync copies values without
 * guessing, and `arcmate_type` keeps ArcMate's raw type number so an unexpected
 * one can be recognised later rather than silently read as text.
 */
class ArchiveField extends Model
{
    public const TYPE_TEXT = 'text';

    public const TYPE_NUMBER = 'number';

    public const TYPE_DATE = 'date';

    public const TYPE_LIST = 'list';

    public const TYPES = [
        self::TYPE_TEXT => 'Text',
        self::TYPE_NUMBER => 'Number',
        self::TYPE_DATE => 'Date',
        self::TYPE_LIST => 'List of values',
    ];

    protected $fillable = [
        'archive_id',
        'key',
        'label',
        'label_ar',
        'type',
        'required',
        'is_unique',
        'searchable',
        'options',
        'ai_hint',
        'arcmate_column',
        'arcmate_type',
        'max_length',
        'sort_order',
    ];

    protected $casts = [
        'required' => 'boolean',
        'is_unique' => 'boolean',
        'searchable' => 'boolean',
        'options' => 'array',
        'arcmate_type' => 'integer',
        'max_length' => 'integer',
        'sort_order' => 'integer',
    ];

    public function archive(): BelongsTo
    {
        return $this->belongsTo(Archive::class);
    }

    public function isDate(): bool
    {
        return $this->type === self::TYPE_DATE;
    }

    public function isNumber(): bool
    {
        return $this->type === self::TYPE_NUMBER;
    }

    public function isList(): bool
    {
        return $this->type === self::TYPE_LIST;
    }

    /** @return array<int,string> */
    public function optionList(): array
    {
        return array_values(array_filter(
            array_map('strval', (array) ($this->options ?? [])),
            fn (string $option) => trim($option) !== ''
        ));
    }

    public function label(): string
    {
        return $this->label !== '' ? $this->label : $this->key;
    }
}
