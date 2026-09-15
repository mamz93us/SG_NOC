<?php

namespace App\Models\Recruitment;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One applicant of one job, read and judged by the AI.
 *
 * `cv_text` and `answers_text` are candidate PII and are encrypted at rest.
 * The model is excluded from the automatic audit: the screening rewrites the
 * whole row, and an audit copy would be the one place the CV sat unencrypted.
 */
class RecruitmentScreening extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SCREENED = 'screened';

    /** The application has no CV attached in Teamtailor. */
    public const STATUS_NO_CV = 'no_cv';

    /** Could not be read or judged after every attempt; the error says why. */
    public const STATUS_FAILED = 'failed';

    /** A transient failure is retried this many times before the row is failed. */
    public const MAX_ATTEMPTS = 3;

    public const FIT_LABELS = [
        'strong' => 'Strong match',
        'good' => 'Good match',
        'possible' => 'Possible',
        'weak' => 'Weak match',
    ];

    protected $fillable = [
        'recruitment_job_id', 'teamtailor_candidate_id', 'teamtailor_application_id',
        'candidate_name', 'candidate_email', 'candidate_location', 'linkedin_url',
        'applied_at', 'stage', 'rejected', 'resume_updated_at',
        'status', 'attempts', 'error',
        'cv_text', 'cv_pages', 'cv_read_as', 'answers_text',
        'score', 'fit', 'must_haves_met', 'must_haves_total', 'evaluation',
        'criteria_hash', 'model', 'tokens_in', 'tokens_out', 'screened_at',
    ];

    protected $casts = [
        'applied_at' => 'datetime',
        'rejected' => 'boolean',
        'attempts' => 'integer',
        'cv_text' => 'encrypted',
        'cv_pages' => 'integer',
        'answers_text' => 'encrypted',
        'score' => 'integer',
        'must_haves_met' => 'integer',
        'must_haves_total' => 'integer',
        'evaluation' => 'array',
        'tokens_in' => 'integer',
        'tokens_out' => 'integer',
        'screened_at' => 'datetime',
    ];

    /** Never serialized by accident — the chat tools choose what to show. */
    protected $hidden = ['cv_text', 'answers_text'];

    public function job(): BelongsTo
    {
        return $this->belongsTo(RecruitmentJob::class, 'recruitment_job_id');
    }

    /** The fit label for a score: the same bands the screening instructions use. */
    public static function fitFor(int $score): string
    {
        return match (true) {
            $score >= 85 => 'strong',
            $score >= 70 => 'good',
            $score >= 50 => 'possible',
            default => 'weak',
        };
    }

    public function fitLabel(): ?string
    {
        return $this->fit ? (self::FIT_LABELS[$this->fit] ?? $this->fit) : null;
    }

    /**
     * Screened applicants, best first: score, then more must-haves met, then
     * who applied first. Rejected applications rank too unless left out.
     */
    public function scopeRanked(Builder $query, bool $includeRejected = true): Builder
    {
        return $query->where('status', self::STATUS_SCREENED)
            ->when(! $includeRejected, fn (Builder $q) => $q->where('rejected', false))
            ->orderByDesc('score')
            ->orderByDesc('must_haves_met')
            ->orderBy('applied_at')
            ->orderBy('id');
    }

    /** @return mixed one field of the evaluation, e.g. 'summary' */
    public function evaluationValue(string $key, mixed $default = null): mixed
    {
        return $this->evaluation[$key] ?? $default;
    }
}
