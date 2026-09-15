<?php

namespace App\Models\Recruitment;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Teamtailor job as Recruitment AI sees it: whether screening is switched
 * on, the recruiter's must-haves, and the hash of what applicants are judged
 * against. The job ad itself stays in Teamtailor and is read at each sync.
 *
 * Excluded from the automatic audit (the scheduler stamps it every sync);
 * RecruitmentAiController logs switching on, criteria changes and deletes.
 */
class RecruitmentJob extends Model
{
    /** More than this many must-haves stops being a shortlist rule and becomes the ad again. */
    public const MAX_MUST_HAVES = 15;

    protected $fillable = [
        'teamtailor_job_id', 'title', 'job_status', 'must_haves',
        'screening_enabled', 'screening_enabled_at', 'screening_enabled_by',
        'criteria_updated_at', 'criteria_updated_by', 'criteria_hash',
        'applicant_count', 'applicants_synced_at', 'last_screened_at', 'sync_error',
    ];

    protected $casts = [
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

    /**
     * What a screening was judged against. A change to the ad, the must-haves or
     * the screening instructions makes every earlier screening stale.
     */
    public static function criteriaHash(string $adText, array $mustHaves, string $instructionsVersion): string
    {
        return hash('sha256', $instructionsVersion."\n".$adText."\n".implode("\n", $mustHaves));
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
