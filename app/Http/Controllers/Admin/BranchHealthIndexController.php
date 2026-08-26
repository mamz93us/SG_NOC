<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\VoiceMeshNode;
use App\Services\BranchHealth\BranchHealthConfig;
use App\Services\HealthScoringService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * The Branch Health Index board.
 *
 * A read-only view over HealthScoringService -- it computes nothing of its own,
 * it only shapes what the scorer already produced into the fleet roll-up, the
 * per-branch cards and the check matrix. If a number here disagrees with the NOC
 * dashboard or the branch drill-down, the bug is in the scorer, not here.
 *
 * The whole payload is handed to the page as JSON in one go: filtering, sorting
 * and the detail drawer are client-side, so changing a filter costs no request
 * and the board can sit on a wallboard without hammering the database.
 */
class BranchHealthIndexController extends Controller
{
    public function __construct(private HealthScoringService $health) {}

    public function index()
    {
        $branches = $this->health->allBranches();
        $codes = $this->branchCodes($branches);

        $rows = $branches->map(fn (Branch $b) => $this->shapeBranch($b, $codes))->values();

        return view('admin.noc.health-index', [
            'branches' => $rows,
            'fleet' => $this->shapeFleet($rows),
            'model' => $this->shapeModel(),
            'generatedAt' => now(),
        ]);
    }

    /**
     * A short code per branch.
     *
     * `branches` has no code column, so prefer the voice mesh node's -- those are
     * the codes people here actually use for a site (JED, RYD, CAI) -- and fall
     * back to a padded id rather than inventing anything.
     *
     * @return array<int, string>
     */
    private function branchCodes(Collection $branches): array
    {
        $codes = [];

        if (Schema::hasTable('voice_mesh_nodes')) {
            $codes = VoiceMeshNode::whereNotNull('branch_id')
                ->orderBy('sort_order')->orderBy('id')
                ->pluck('code', 'branch_id')
                ->all();
        }

        foreach ($branches as $b) {
            $codes[$b->id] ??= 'BR-'.str_pad((string) $b->id, 2, '0', STR_PAD_LEFT);
        }

        return $codes;
    }

    private function shapeBranch(Branch $branch, array $codes): array
    {
        $h = $branch->health;

        return [
            'id' => $branch->id,
            'name' => $branch->name,
            'code' => $codes[$branch->id] ?? '',
            'region' => $branch->city ?: null,
            'url' => route('admin.noc.branch', $branch->id),
            'total' => $h['total'],
            'raw_total' => $h['raw_total'],
            // What the board is really asking: how much is this site down by.
            'lost' => round(100 - $h['total'], 1),
            'coverage' => $h['coverage_percent'],
            'normalized' => $h['normalized_percent'],
            'status' => $h['status'],
            'status_label' => HealthScoringService::statusLabel($h['status']),
            'capped' => $h['cap_reasons'] !== [],
            'cap_reasons' => array_map(fn ($r) => $r['message'], $h['cap_reasons']),
            'categories' => collect($h['categories'])->map(fn ($c) => [
                'key' => $c['key'],
                'label' => $c['label'],
                'blurb' => $this->categoryBlurb($c['key']),
                'points' => $c['points'],
                'max_points' => $c['max_points'],
                'percent' => $c['percent'],
                'checks' => collect($c['checks'])->values()->map(fn ($check, $i) => [
                    // V1..V4 / N1..N5 / D1..D3 -- stable short handles, so an
                    // operator can say "N4 is red at Jeddah" and be understood.
                    'code' => strtoupper(substr($c['key'], 0, 1)).($i + 1),
                    'key' => $check['key'],
                    'label' => $check['label'],
                    'points' => $check['points'],
                    'max_points' => $check['max_points'],
                    'percent' => $check['max_points'] > 0
                        ? (int) round($check['points'] / $check['max_points'] * 100)
                        : 0,
                    'status' => $check['status'],
                    'passing' => $check['passing'],
                    'total' => $check['total'],
                    'unknown' => $check['unknown'],
                    'message' => $check['message'],
                    'failures' => array_slice($check['failures'] ?? [], 0, 8),
                    'portal_url' => $check['portal_url'],
                    'last_updated_at' => $check['last_updated_at'],
                ])->values(),
            ])->values(),
        ];
    }

    private function categoryBlurb(string $key): string
    {
        return match ($key) {
            'voip' => 'Call platform & media path',
            'network' => 'Branch connectivity & security',
            'devices' => 'Endpoint availability',
            default => '',
        };
    }

    /**
     * The fleet roll-up.
     *
     * The headline is the MEAN of the branch scores, not a re-scored estate.
     * Averaging the rings is the honest summary of "how are my sites doing";
     * pooling every device into one 100-point score would let one large branch
     * mask a small one that is completely dark.
     */
    private function shapeFleet(Collection $rows): array
    {
        $scored = $rows->reject(fn ($r) => $r['status'] === 'unknown');
        $basis = $scored->isNotEmpty() ? $scored : $rows;

        $categories = [];
        foreach (array_keys(BranchHealthConfig::get('weights', [])) as $key) {
            $points = $basis->map(fn ($r) => collect($r['categories'])->firstWhere('key', $key)['points'] ?? 0);

            $categories[] = [
                'key' => $key,
                'label' => BranchHealthConfig::get("category_labels.{$key}", ucfirst($key)),
                'points' => $basis->isEmpty() ? 0.0 : round($points->avg(), 1),
                'max_points' => array_sum(BranchHealthConfig::get("weights.{$key}", [])),
            ];
        }

        $bands = ['healthy' => 0, 'degraded' => 0, 'at_risk' => 0, 'critical' => 0, 'unknown' => 0];
        foreach ($rows as $r) {
            $bands[$r['status']] = ($bands[$r['status']] ?? 0) + 1;
        }

        $t = BranchHealthConfig::get('status_thresholds');

        return [
            'score' => $basis->isEmpty() ? 0.0 : round($basis->avg('total'), 1),
            'branch_count' => $rows->count(),
            'scored_count' => $basis->count(),
            'lost' => round($basis->sum('lost'), 1),
            'weakest' => $rows->sortBy('total')->first()['name'] ?? null,
            'categories' => $categories,
            'bands' => [
                ['key' => 'healthy', 'label' => 'Healthy', 'hint' => '≥'.$t['healthy'], 'count' => $bands['healthy']],
                ['key' => 'degraded', 'label' => 'Degraded', 'hint' => '≥'.$t['degraded'], 'count' => $bands['degraded']],
                ['key' => 'at_risk', 'label' => 'At risk', 'hint' => '≥'.$t['at_risk'], 'count' => $bands['at_risk']],
                ['key' => 'critical', 'label' => 'Critical', 'hint' => '<'.$t['at_risk'], 'count' => $bands['critical']],
                ['key' => 'unknown', 'label' => 'Unknown', 'hint' => 'not enough data', 'count' => $bands['unknown']],
            ],
        ];
    }

    /**
     * The scoring model, read straight from config so the tab documenting the
     * weights cannot drift from the weights actually being applied.
     */
    private function shapeModel(): array
    {
        $labels = [];
        foreach (BranchHealthConfig::array('weights') as $key => $checks) {
            foreach (array_keys($checks) as $i => $checkKey) {
                $labels[$checkKey] = strtoupper(substr($key, 0, 1)).($i + 1);
            }
        }

        $out = [];
        foreach (BranchHealthConfig::array('weights') as $key => $checks) {
            $rows = [];
            foreach ($checks as $checkKey => $points) {
                $rows[] = ['code' => $labels[$checkKey], 'key' => $checkKey, 'points' => $points];
            }
            $out[] = [
                'key' => $key,
                'label' => BranchHealthConfig::get("category_labels.{$key}", ucfirst($key)),
                'blurb' => $this->categoryBlurb($key),
                'max_points' => array_sum($checks),
                'checks' => $rows,
            ];
        }

        return [
            'categories' => $out,
            'freshness' => BranchHealthConfig::get('freshness'),
            'caps' => BranchHealthConfig::get('caps'),
            'min_coverage' => BranchHealthConfig::get('min_coverage_for_status'),
        ];
    }
}
