<?php

namespace App\Models\Exams;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One sitting of an exam. Out of the automatic audit: every answer rewrites
 * the row. Starting and finishing are logged by hand (ExamSession).
 */
class ExamAttempt extends Model
{
    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'exam_id', 'user_id', 'status', 'language', 'started_at', 'expires_at', 'submitted_at',
        'question_ids', 'option_orders', 'answers', 'flagged', 'total_questions',
        'correct_count', 'score', 'passing_score', 'passed', 'results', 'domain_results',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'expires_at' => 'datetime',
        'submitted_at' => 'datetime',
        'exam_id' => 'integer',
        'user_id' => 'integer',
        'question_ids' => 'array',
        'option_orders' => 'array',
        'answers' => 'array',
        'flagged' => 'array',
        'results' => 'array',
        'domain_results' => 'array',
        'total_questions' => 'integer',
        'correct_count' => 'integer',
        'score' => 'integer',
        'passing_score' => 'integer',
        'passed' => 'boolean',
    ];

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public const LANGUAGES = ['en' => 'English', 'ar' => 'العربية'];

    public function isArabic(): bool
    {
        return $this->language === 'ar';
    }

    public function isInProgress(): bool
    {
        return $this->status === self::STATUS_IN_PROGRESS;
    }

    public function isFinished(): bool
    {
        return ! $this->isInProgress();
    }

    public function hasTimedOut(?Carbon $now = null): bool
    {
        return ($now ?? now())->greaterThanOrEqualTo($this->expires_at);
    }

    public function secondsLeft(): int
    {
        return max(0, (int) now()->diffInSeconds($this->expires_at, false));
    }

    /** @return list<string> the keys chosen for a question, in original key form */
    public function answerFor(int $questionId): array
    {
        return array_values($this->answers[(string) $questionId] ?? []);
    }

    public function isFlagged(int $questionId): bool
    {
        return in_array($questionId, array_map('intval', $this->flagged ?? []), true);
    }

    /** @return list<string> option keys in the order this attempt shows them */
    public function optionOrderFor(ExamQuestion $question): array
    {
        $order = $this->option_orders[(string) $question->id] ?? null;
        $keys = array_map('strval', array_keys($question->options ?? []));

        // An option added or removed since the attempt started: fall back to
        // the question's own order rather than showing a key that is gone.
        if (! is_array($order) || count($order) !== count($keys) || array_diff($keys, $order)) {
            return $keys;
        }

        return array_values(array_map('strval', $order));
    }

    public function answeredCount(): int
    {
        return count(array_filter($this->answers ?? [], fn ($keys) => ! empty($keys)));
    }

    public function durationSeconds(): ?int
    {
        if (! $this->submitted_at) {
            return null;
        }

        return (int) min(
            $this->started_at->diffInSeconds($this->submitted_at),
            $this->started_at->diffInSeconds($this->expires_at),
        );
    }
}
