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
        'question_ar', 'options_ar', 'explanation_ar',
    ];

    protected $casts = [
        'exam_id' => 'integer',
        'options' => 'array',
        'options_ar' => 'array',
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

    /**
     * Whether the question can be shown in Arabic: its text and every option.
     * One with only part of it stays in English rather than mixing the two.
     */
    public function hasArabic(): bool
    {
        $ar = $this->options_ar ?? [];

        return filled($this->question_ar)
            && count($ar) === count($this->options ?? [])
            && collect(array_keys($this->options ?? []))->every(fn ($key) => filled($ar[$key] ?? null));
    }

    /** The language this question is actually shown in, for the one asked. */
    public function shownIn(string $language): string
    {
        return $language === 'ar' && $this->hasArabic() ? 'ar' : 'en';
    }

    public function textIn(string $language): string
    {
        return $this->shownIn($language) === 'ar' ? $this->question_ar : $this->question;
    }

    public function optionIn(string $key, string $language): string
    {
        return (string) ($this->shownIn($language) === 'ar' ? $this->options_ar[$key] : ($this->options[$key] ?? ''));
    }

    /** A skill area's name as shown; they are stored in English. */
    public static function domainIn(string $domain, string $language): string
    {
        $names = trans('exams.domains', [], $language);

        return is_array($names) && filled($names[$domain] ?? null) ? $names[$domain] : $domain;
    }

    public function explanationIn(string $language): ?string
    {
        return $language === 'ar' && filled($this->explanation_ar) ? $this->explanation_ar : $this->explanation;
    }
}
