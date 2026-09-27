<?php

namespace App\Models\Exams;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One question in an exam's bank. `options` is {"A": text, …} and `answer`
 * the list of correct keys; a `multiple` question is right only when exactly
 * those keys are chosen, as on the real exam.
 */
class ExamQuestion extends Model
{
    public const TYPE_SINGLE = 'single';

    public const TYPE_MULTIPLE = 'multiple';

    protected $fillable = [
        'exam_id', 'uid', 'domain', 'type', 'question', 'options', 'answer',
        'explanation', 'reference', 'shuffle_options', 'is_active',
    ];

    protected $casts = [
        'exam_id' => 'integer',
        'options' => 'array',
        'answer' => 'array',
        'shuffle_options' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function isMultiple(): bool
    {
        return $this->type === self::TYPE_MULTIPLE;
    }

    /** How many options a candidate must pick. */
    public function requiredSelections(): int
    {
        return $this->isMultiple() ? count($this->answer ?? []) : 1;
    }
}
