<?php

namespace App\Models\Recruitment;

use App\Models\Branch;
use App\Models\User;
use App\Services\Recruitment\SalaryAnswers;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Teamtailor job as Recruitment AI sees it: whether screening is switched
 * on, the recruiter's must-haves, the office the job is in and its salary
 * budget, and the hash of what applicants are judged against. The job ad
 * itself stays in Teamtailor and is read at each sync.
 *
 * The office and the budget are set here because Teamtailor rarely has them:
 * on 2026-09-15 two of eleven jobs named a location and none a salary range.
 *
 * Excluded from the automatic audit (the scheduler stamps it every sync);
 * RecruitmentAiController logs switching on, criteria and settings changes,
 * and deletes.
 */
class RecruitmentJob extends Model
{
    /** More than this many must-haves stops being a shortlist rule and becomes the ad again. */
    public const MAX_MUST_HAVES = 15;

    public const CURRENCIES = ['EGP', 'SAR', 'AED', 'USD'];

    protected $fillable = [
        'teamtailor_job_id', 'title', 'job_status', 'must_haves',
        'office_branch_id', 'salary_budget_min', 'salary_budget_max', 'salary_currency',
        'screening_enabled', 'screening_enabled_at', 'screening_enabled_by',
        'criteria_updated_at', 'criteria_updated_by', 'criteria_hash',
        'applicant_count', 'applicants_synced_at', 'last_screened_at', 'sync_error',
    ];

    protected $casts = [
        'office_branch_id' => 'integer',
        'salary_budget_min' => 'integer',
        'salary_budget_max' => 'integer',
        'screening_enabled' => 'boolean',
        'screening_enabled_at' => 'datetime',
        'criteria_updated_at' => 'datetime',
        'applicant_count' => 'integer',
        'applicants_synced_at' => 'datetime',
        'last_screened_at' => 'datetime',
    ];

    public function screenings(): HasMany
    {
        return $this->hasMany(RecruitmentScreening::class);
    }

    public function enabledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'screening_enabled_by');
    }

    /** The NOC branch the job is in: where distances are measured to. */
    public function office(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'office_branch_id');
    }

    /**
     * The must-haves as a list: one per line, bullets and numbering dropped,
     * duplicates removed, at most MAX_MUST_HAVES.
     *
     * @return list<string>
     */
    public function mustHaveList(): array
    {
        return self::parseMustHaves((string) $this->must_haves);
    }

    /** @return list<string> */
    public static function parseMustHaves(string $text): array
    {
        $items = [];

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim((string) preg_replace('/^\s*(?:[-*•·]+|\d+[.)])\s*/u', '', $line));

            if ($line !== '' && ! in_array(mb_strtolower($line), array_map('mb_strtolower', $items), true)) {
                $items[] = mb_substr($line, 0, 200);
            }
        }

        return array_slice($items, 0, self::MAX_MUST_HAVES);
    }

    /** "CAI — Heliopolis, Cairo": the office as the screening reads it; null when none is set. */
    public function officeText(): ?string
    {
        $office = $this->office;

        if (! $office) {
            return null;
        }

        $street = trim((string) $office->street);
        $city = trim((string) $office->city);

        // Several branches keep the whole postal address, city included, as the street.
        $place = $street !== '' && $city !== '' && mb_stripos($street, $city) !== false
            ? $street
            : collect([$street, $city])->filter()->implode(', ');

        return $office->name.($place !== '' ? " — {$place}" : '');
    }

    /** "8,000–12,000 EGP a month"; null when no budget is set. */
    public function budgetText(): ?string
    {
        $min = $this->salary_budget_min ?: null;
        $max = $this->salary_budget_max ?: null;
        $currency = $this->salary_currency ? ' '.$this->salary_currency : '';

        return match (true) {
            $min !== null && $max !== null => SalaryAnswers::format($min, $max).$currency.' a month',
            $max !== null => 'up to '.number_format($max).$currency.' a month',
            $min !== null => 'from '.number_format($min).$currency.' a month',
            default => null,
        };
    }

    /** The office and the budget, as they enter the criteria hash. */
    public function contextText(): string
    {
        return trim(($this->officeText() ?? '')."\n".($this->budgetText() ?? ''));
    }

    /**
     * What a screening was judged against. A change to the ad, the must-haves,
     * the office or budget, or the screening instructions makes every earlier
     * screening stale.
     */
    public static function criteriaHash(string $adText, array $mustHaves, string $instructionsVersion, string $context = ''): string
    {
        return hash('sha256', $instructionsVersion."\n".$adText."\n".implode("\n", $mustHaves)."\n".$context);
    }

    /** @return array{total:int, screened:int, pending:int, no_cv:int, failed:int} */
    public function progress(): array
    {
        $counts = $this->screenings()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'total' => (int) $counts->sum(),
            'screened' => (int) ($counts[RecruitmentScreening::STATUS_SCREENED] ?? 0),
            'pending' => (int) ($counts[RecruitmentScreening::STATUS_PENDING] ?? 0),
            'no_cv' => (int) ($counts[RecruitmentScreening::STATUS_NO_CV] ?? 0),
            'failed' => (int) ($counts[RecruitmentScreening::STATUS_FAILED] ?? 0),
        ];
    }
}
