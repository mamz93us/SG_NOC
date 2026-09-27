<?php

namespace App\Models\Exams;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A practice exam (AZ-900, AI-900, …) and its question bank.
 */
class Exam extends Model
{
    protected $fillable = [
        'code', 'title', 'description', 'duration_minutes', 'question_count',
        'passing_score', 'show_review', 'is_active',
    ];

    protected $casts = [
        'duration_minutes' => 'integer',
        'question_count' => 'integer',
        'passing_score' => 'integer',
        'show_review' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function questions(): HasMany
    {
        return $this->hasMany(ExamQuestion::class);
    }

    public function activeQuestions(): HasMany
    {
        return $this->questions()->where('is_active', true);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(ExamAttempt::class);
    }

    /** How many questions an attempt actually gets, given the bank today. */
    public function questionsPerAttempt(?int $bankSize = null): int
    {
        $bankSize ??= $this->activeQuestions()->count();

        return $this->question_count > 0 ? min($this->question_count, $bankSize) : $bankSize;
    }
}
