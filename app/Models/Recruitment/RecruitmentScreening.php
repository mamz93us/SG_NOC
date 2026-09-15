<?php

namespace App\Models\Recruitment;

use App\Services\Recruitment\SalaryAnswers;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One applicant of one job, read and judged by the AI.
 *
 * `cv_text`, `answers_text` and `facts` — the salary they stated and where they
 * live — are candidate PII and are encrypted at rest. The model is excluded
 * from the automatic audit: the screening rewrites the whole row, and an audit
 * copy would be the one place the CV sat unencrypted.
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
        'score', 'fit', 'must_haves_met', 'must_haves_total', 'evaluation', 'facts',
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
        // {salary: SalaryAnswers::extract(), location: {lives_in, distance_km, relocation_needed, office}|null}
        'facts' => 'encrypted:array',
        'tokens_in' => 'integer',
        'tokens_out' => 'integer',
        'screened_at' => 'datetime',
    ];

    /** Never serialized by accident — the chat tools choose what to show. */
    protected $hidden = ['cv_text', 'answers_text', 'facts'];

    public function job(): BelongsTo
    {
        return $this->belongsTo(RecruitmentJob::class, 'recruitment_job_id');
    }

    /**
     * One figure of the salary the applicant stated: 'expected' ({min, max,
     * currency, text, from}) or 'current' ({amount, currency, text, from}).
     */
    public function salaryFigure(string $which): ?array
    {
        $figure = $this->facts['salary'][$which] ?? null;

        return is_array($figure) ? $figure : null;
    }

    /** The salary the applicant asked for: "12,000 EGP" or "10,000–12,000 SAR"; null when their answers give none. */
    public function expectedSalaryLabel(): ?string
    {
        return SalaryAnswers::label($this->salaryFigure('expected'));
    }

    /** The salary the applicant earns now, from their answers; null when they give none. */
    public function currentSalaryLabel(): ?string
    {
        return SalaryAnswers::label($this->salaryFigure('current'));
    }

    /** How much more than now they ask, in percent; null unless both figures are known in one currency. */
    public function salaryRaisePercent(): ?int
    {
        return SalaryAnswers::raisePercent($this->salaryFigure('expected'), $this->salaryFigure('current'));
    }

    /**
     * Whether the lowest salary asked for is above the top of the job's budget.
     * False when either is unknown, or when they are in different currencies.
     */
    public function aboveBudget(RecruitmentJob $job): bool
    {
        $expected = $this->salaryFigure('expected');

        if ($expected === null || ! $job->salary_budget_max) {
            return false;
        }

        $currency = $expected['currency'] ?? null;

        if ($currency !== null && $job->salary_currency !== null && $currency !== $job->salary_currency) {
            return false;
        }

        return (int) ($expected['min'] ?? 0) > $job->salary_budget_max;
    }

    /** Where the applicant lives, area and city, as the AI read it from their CV, answers or profile. */
    public function livesIn(): ?string
    {
        $place = $this->facts['location']['lives_in'] ?? null;

        return is_string($place) && $place !== '' ? $place : null;
    }

    /** The AI's estimate of the distance by road from there to the job's office, in km. */
    public function distanceKm(): ?int
    {
        $km = $this->facts['location']['distance_km'] ?? null;

        return is_numeric($km) ? (int) $km : null;
    }

    /** yes, no or unclear: whether taking the job means moving city; null when not judged. */
    public function relocationNeeded(): ?string
    {
        $answer = $this->facts['location']['relocation_needed'] ?? null;

        return is_string($answer) ? $answer : null;
    }

    /** The office (its branch name) the distance was measured to, as it was at screening. */
    public function distanceOffice(): ?string
    {
        $office = $this->facts['location']['office'] ?? null;

        return is_string($office) && $office !== '' ? $office : null;
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
