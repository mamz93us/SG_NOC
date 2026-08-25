<?php

namespace App\Services;

use App\Models\Branch;
use App\Services\BranchHealth\BranchHealthEvaluator;
use App\Services\BranchHealth\BranchTelemetryLoader;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * The single owner of branch health scoring.
 *
 * Nothing else in the codebase should compute a branch health number. The NOC
 * dashboard, the branch drill-down and the welcome widget all come through here,
 * so they cannot drift apart the way they had — the welcome widget used to be
 * ISP link checks only while the NOC map averaged four modules, one of which was
 * hardcoded to 100.
 *
 * Scoring itself lives in App\Services\BranchHealth:
 *   BranchTelemetryLoader  reads every source in bulk (bounded query count)
 *   BranchHealthEvaluator  turns one branch's telemetry into points (pure)
 *
 * This class is the facade over those two, plus caching. Both entry points run
 * the SAME loader and the SAME evaluator, so scoreForBranch() and allBranches()
 * cannot disagree.
 */
class HealthScoringService
{
    public function __construct(
        private BranchTelemetryLoader $loader,
        private BranchHealthEvaluator $evaluator,
    ) {}

    /**
     * Every branch, worst-first then by name.
     *
     * Worst-first because the point of a NOC wallboard is that whatever needs
     * attention is at the top without anyone scrolling.
     *
     * @return Collection<int, Branch> each with a ->health array attached
     */
    public function allBranches(): Collection
    {
        $scores = $this->scoreMap();

        return Branch::orderBy('name')->get()
            ->each(fn (Branch $b) => $b->health = $scores[(int) $b->id] ?? $this->emptyScore())
            ->sortBy([
                fn (Branch $a, Branch $b) => $a->health['total'] <=> $b->health['total'],
                fn (Branch $a, Branch $b) => strcasecmp((string) $a->name, (string) $b->name),
            ])
            ->values();
    }

    /**
     * One branch's full score, including per-check detail.
     *
     * Reads the same cache entry allBranches() populates rather than scoring
     * independently — otherwise the drill-down could show live numbers while the
     * dashboard behind it showed a snapshot up to 60 seconds old, and the two
     * would appear to contradict each other. Pass $fresh to opt out deliberately.
     */
    public function scoreForBranch(Branch|int $branch, bool $fresh = false): array
    {
        $branchId = $branch instanceof Branch ? (int) $branch->id : (int) $branch;

        if (! $fresh) {
            $cached = $this->scoreMap();
            if (isset($cached[$branchId])) {
                return $cached[$branchId];
            }
        }

        // Cache miss: a branch created inside the TTL, or an explicit refresh.
        $model = $branch instanceof Branch && $branch->exists ? $branch : Branch::find($branchId);

        if (! $model) {
            return $this->emptyScore();
        }

        return $this->score(collect([$model]))[$branchId] ?? $this->emptyScore();
    }

    /**
     * The all-branch score map, memoized for a short window.
     *
     * One key for the whole estate, not one per branch: the cache store is the
     * database, so per-branch keys would mean N reads and N writes against the
     * very database this is meant to spare — with N independent TTLs guaranteeing
     * the branches drift out of step with each other.
     *
     * Invalidation is by expiry alone. Bump `branch_health.cache_key` when the
     * scoring model changes; a database cache store cannot be selectively
     * flushed cheaply.
     *
     * @return array<int, array>
     */
    private function scoreMap(): array
    {
        return Cache::remember(
            config('branch_health.cache_key', 'noc:branch_health:v1'),
            (int) config('branch_health.cache_ttl_seconds', 60),
            fn () => $this->score(Branch::orderBy('name')->get())
        );
    }

    /**
     * Load telemetry for these branches and evaluate each one.
     *
     * Caches a plain array, never hydrated Branch models: branches use a manual
     * non-incrementing key and a dynamic `health` attribute, and round-tripping
     * that through the cache table is needlessly fragile.
     *
     * @param  Collection<int, Branch>  $branches
     * @return array<int, array>
     */
    private function score(Collection $branches): array
    {
        return $this->loader->load($branches)
            ->map(fn ($slice) => $this->evaluator->evaluate($slice))
            ->all();
    }

    /** A branch we could not score at all — explicitly unknown, not zero-healthy. */
    private function emptyScore(): array
    {
        $categories = [];

        foreach (config('branch_health.weights', []) as $key => $checks) {
            $categories[$key] = [
                'key' => $key,
                'label' => config("branch_health.category_labels.{$key}", ucfirst($key)),
                'points' => 0.0,
                'max_points' => array_sum($checks),
                'percent' => 0,
                'evaluable_points' => 0.0,
                'checks' => [],
            ];
        }

        return [
            'total' => 0,
            'raw_total' => 0,
            'coverage_percent' => 0,
            'normalized_percent' => null,
            'status' => 'unknown',
            'cap_reasons' => [],
            'categories' => $categories,
            'updated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Bootstrap contextual colour for a 0-100 percentage.
     *
     * MUST be given a percentage, never raw points. Category points run on 0-40,
     * 0-45 and 0-15 scales, so passing those in would paint a perfect 15/15
     * Devices score red.
     */
    public function healthColor(int $percent): string
    {
        return self::healthColorStatic($percent);
    }

    public static function healthColorStatic(int $percent): string
    {
        $t = config('branch_health.status_thresholds', ['excellent' => 90, 'good' => 75, 'degraded' => 60]);

        return match (true) {
            $percent >= (int) $t['excellent'] => 'success',
            $percent >= (int) $t['good'] => 'info',
            $percent >= (int) $t['degraded'] => 'warning',
            default => 'danger',
        };
    }

    /** Bootstrap colour for a health state, so the UI never re-derives the bands. */
    public static function statusColor(string $status): string
    {
        return match ($status) {
            'excellent' => 'success',
            'good' => 'info',
            'degraded' => 'warning',
            'critical' => 'danger',
            default => 'secondary',
        };
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'excellent' => 'Excellent',
            'good' => 'Good',
            'degraded' => 'Degraded',
            'critical' => 'Critical',
            default => 'Unknown',
        };
    }
}
