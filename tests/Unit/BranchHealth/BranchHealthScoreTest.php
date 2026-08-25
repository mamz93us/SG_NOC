<?php

use App\Models\Branch;
use App\Services\HealthScoringService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\BranchHealthFixture as Fx;
use Tests\Support\BranchHealthSchema;

/**
 * The 100-point branch health score.
 *
 * Binds Tests\TestCase by hand so the suite gets a booted Laravel app WITHOUT
 * RefreshDatabase: 37 statements across this repo's migrations are MySQL-only
 * `MODIFY COLUMN` DDL and abort on the SQLite connection phpunit.xml configures.
 * BranchHealthSchema builds the tables under test directly instead — the same
 * approach VoiceMeshSchema already uses.
 */
uses(Tests\TestCase::class);

beforeEach(function () {
    BranchHealthSchema::create();
    // allBranches() memoizes for 60s; without this a test would score the
    // previous test's fixtures.
    Cache::flush();
});

function score(int $branchId = 1): array
{
    return app(HealthScoringService::class)->scoreForBranch($branchId, fresh: true);
}

/** The points a single named check earned, across all categories. */
function check(array $score, string $key): array
{
    foreach ($score['categories'] as $category) {
        foreach ($category['checks'] as $c) {
            if ($c['key'] === $key) {
                return $c;
            }
        }
    }

    throw new RuntimeException("No check [{$key}] in score");
}

// ─────────────────────────────────────────────────────────────────────
// The model itself
// ─────────────────────────────────────────────────────────────────────

it('weights exactly 100 points across three categories', function () {
    $weights = config('branch_health.weights');

    expect(array_sum($weights['voip']))->toBe(40)
        ->and(array_sum($weights['network']))->toBe(45)
        ->and(array_sum($weights['devices']))->toBe(15)
        ->and(collect($weights)->flatten()->sum())->toBe(100);
});

it('exposes all twelve checks with the documented shape', function () {
    Fx::healthy(1);
    $score = score();

    $checks = collect($score['categories'])->flatMap(fn ($c) => $c['checks']);

    expect($checks)->toHaveCount(12);

    foreach ($checks as $c) {
        expect($c)->toHaveKeys([
            'key', 'label', 'points', 'max_points', 'ratio', 'status',
            'passing', 'total', 'unknown', 'message', 'last_updated_at', 'portal_url',
        ]);
        expect($c['status'])->toBeIn(['pass', 'degraded', 'fail', 'unknown']);
        expect($c['portal_url'])->toStartWith('http');
    }
});

it('scores a fully healthy branch at 100', function () {
    Fx::healthy(1);
    $score = score();

    expect($score['total'])->toBe(100)
        ->and($score['raw_total'])->toBe(100)
        ->and($score['coverage_percent'])->toBe(100)
        ->and($score['status'])->toBe('excellent')
        ->and($score['cap_reasons'])->toBe([]);
});

// ─────────────────────────────────────────────────────────────────────
// Proportional scoring
// ─────────────────────────────────────────────────────────────────────

it('scores partial trunk availability proportionally', function () {
    BranchHealthSchema::seedBranches([[1, 'Test']]);
    Fx::ucm(1, ok: true, trunks: [
        ['T1', 'reachable'], ['T2', 'registered'],
        ['T3', 'unreachable'], ['T4', 'rejected'],
    ]);

    $c = check(score(), 'ucm_trunks');

    // 2 of 4 healthy against a 12-point check.
    expect($c['passing'])->toBe(2)
        ->and($c['total'])->toBe(4)
        ->and($c['points'])->toBe(6.0)
        ->and($c['status'])->toBe('degraded');
});

it('scores partial switch, AP, printer and biometric availability proportionally', function () {
    BranchHealthSchema::seedBranches([[1, 'Test']]);
    Fx::switches(1, up: 8, down: 2);      // 8/10 of 8 pts  = 6.4
    Fx::accessPoints(1, up: 3, down: 1);  // 3/4  of 6 pts  = 4.5
    Fx::printers(1, up: 1, down: 1);      // 1/2  of 5 pts  = 2.5
    Fx::biometrics(1, up: 1, down: 3);    // 1/4  of 6 pts  = 1.5

    $score = score();

    expect(check($score, 'switch_reachability')['points'])->toBe(6.4)
        ->and(check($score, 'access_point_reachability')['points'])->toBe(4.5)
        ->and(check($score, 'printer_reachability')['points'])->toBe(2.5)
        ->and(check($score, 'biometric_reachability')['points'])->toBe(1.5);
});

it('scores voice mesh against every other active branch and names what failed', function () {
    BranchHealthSchema::seedBranches([[1, 'Test']]);
    Fx::mesh(1, peers: [[2, 'JED'], [3, 'RYD'], [4, 'KBR'], [5, 'CAI']], legOk: [
        'JED' => true, 'RYD' => true, 'KBR' => true, 'CAI' => false,
    ], allOk: true);

    $c = check(score(), 'voice_mesh');

    expect($c['passing'])->toBe(3)
        ->and($c['total'])->toBe(4)
        ->and($c['points'])->toBe(7.5)
        // Every failed check must name what is affected, or it is not actionable.
        ->and(collect($c['failures'])->pluck('label'))->toContain('CAI Node');
});

it('fails a mesh leg that connected but carried no RTP', function () {
    BranchHealthSchema::seedBranches([[1, 'Test']]);
    Fx::mesh(1, peers: [[2, 'JED']], allOk: true);
    // status ok, but zero packets received — the call set up and carried silence.
    DB::table('voice_mesh_pairs')->update(['last_rx_pkt' => 0]);

    $c = check(score(), 'voice_mesh');

    expect($c['passing'])->toBe(0)
        ->and($c['status'])->toBe('fail')
        ->and(collect($c['failures'])->pluck('detail')->implode(' '))->toContain('no RTP received');
});

// ─────────────────────────────────────────────────────────────────────
// Staleness and missing inventory
// ─────────────────────────────────────────────────────────────────────

it('treats stale telemetry as unknown rather than healthy', function () {
    BranchHealthSchema::seedBranches([[1, 'Test']]);
    $stale = now()->subHours(6);

    Fx::ucm(1, ok: true, trunks: [['T1', 'reachable']], at: $stale);
    Fx::switches(1, up: 2, down: 0, pingedAt: $stale);
    Fx::accessPoints(1, up: 2, down: 0, pingedAt: $stale);
    Fx::isp(1, success: true, checkedAt: $stale);

    $score = score();

    // Every one of these would read as a pass if freshness were ignored.
    foreach (['ucm_reachable', 'ucm_trunks', 'switch_reachability', 'access_point_reachability', 'gateway_reachable'] as $key) {
        $c = check($score, $key);
        expect($c['points'])->toBe(0.0, "{$key} awarded points for stale data");
        expect($c['status'])->not->toBe('pass', "{$key} passed on stale data");
    }

    expect($score['coverage_percent'])->toBeLessThan(100);
});

it('awards no points and no coverage when a branch has no inventory', function () {
    BranchHealthSchema::seedBranches([[1, 'Empty']]);

    $score = score();

    // 8, not 0: firewall_alerts is a boolean condition rather than a ratio, and
    // "no open critical alerts" is genuinely true for a branch with nothing
    // configured. Every ratio check below contributes exactly nothing.
    expect($score['total'])->toBe(8)
        ->and($score['raw_total'])->toBe(8)
        ->and($score['coverage_percent'])->toBe(8);

    $alerts = check($score, 'firewall_alerts');
    expect($alerts['status'])->toBe('pass')->and($alerts['points'])->toBe(8.0);

    foreach (['ucm_reachable', 'ucm_trunks', 'voice_mesh', 'mos_compliance',
        'firewall_reachable', 'gateway_reachable', 'switch_reachability',
        'access_point_reachability', 'printer_reachability',
        'biometric_reachability', 'printer_toner'] as $key) {
        $c = check($score, $key);
        expect($c['status'])->toBe('unknown', "{$key} was not unknown on an empty branch");
        expect($c['points'])->toBe(0.0, "{$key} awarded free points");
        expect($c['total'])->toBe(0);
    }
});

it('counts configured but unmonitored devices against coverage, not as passes', function () {
    BranchHealthSchema::seedBranches([[1, 'Test']]);
    // Two biometric assets registered, neither linked to a monitored host.
    Fx::biometrics(1, up: 2, down: 0, withHost: false);

    $c = check(score(), 'biometric_reachability');

    expect($c['total'])->toBe(2)
        ->and($c['passing'])->toBe(0)
        ->and($c['unknown'])->toBe(2)
        ->and($c['points'])->toBe(0.0)
        // The UI needs to say "not monitored", not "down".
        ->and(collect($c['failures'])->pluck('action'))->toContain('configuration');
});

it('does not let a stale Meraki row outrank a fresh ping', function () {
    BranchHealthSchema::seedBranches([[1, 'Test']]);
    // Meraki says online but has not reported in a day; ping says down, now.
    Fx::merakiSwitch(1, merakiStatus: 'online', merakiAt: now()->subDay(),
        hostStatus: 'down', hostAt: now());

    $c = check(score(), 'switch_reachability');

    expect($c['passing'])->toBe(0)
        ->and($c['status'])->toBe('fail')
        ->and($c['failures'][0]['detail'])->toContain('Ping reports down');
});

// ─────────────────────────────────────────────────────────────────────
// MOS
// ─────────────────────────────────────────────────────────────────────

it('measures MOS compliance against the 4.3 threshold', function () {
    BranchHealthSchema::seedBranches([[1, 'Test']]);
    Fx::mos(1, passing: 7, failing: 3);

    $c = check(score(), 'mos_compliance');

    expect($c['passing'])->toBe(7)
        ->and($c['total'])->toBe(10)
        ->and($c['points'])->toBe(5.6)   // 8 * 0.7
        ->and($c['window'])->toBe('24h');
});

it('treats a call exactly at 4.3 as passing', function () {
    BranchHealthSchema::seedBranches([[1, 'Test']]);
    DB::table('voice_quality_reports')->insert([
        'extension' => '1001', 'branch_id' => 1, 'mos_lq' => 4.3,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(check(score(), 'mos_compliance')['passing'])->toBe(1);
});

it('widens the MOS window to 7 days when the day is too quiet to judge', function () {
    BranchHealthSchema::seedBranches([[1, 'Test']]);
    Fx::mos(1, passing: 2, failing: 0, at: now()->subHours(2));   // only 2 today
    Fx::mos(1, passing: 8, failing: 2, at: now()->subDays(3));    // more this week

    $c = check(score(), 'mos_compliance');

    // Below min_samples in 24h, so the whole 7-day set is used instead.
    expect($c['window'])->toBe('7d')
        ->and($c['total'])->toBe(12)
        ->and($c['passing'])->toBe(10);
});

it('keeps the 24h window once there are enough samples', function () {
    BranchHealthSchema::seedBranches([[1, 'Test']]);
    Fx::mos(1, passing: 5, failing: 0, at: now()->subHours(2));
    Fx::mos(1, passing: 0, failing: 50, at: now()->subDays(3));

    $c = check(score(), 'mos_compliance');

    expect($c['window'])->toBe('24h')
        ->and($c['total'])->toBe(5)
        ->and($c['points'])->toBe(8.0);
});

it('attributes unlabelled MOS reports by extension range', function () {
    BranchHealthSchema::seedBranches([[1, 'One'], [2, 'Two']]);
    // branch_id NULL, as every historical row is — extension 1005 is in
    // branch 1's range (1000-1999), 2005 is in branch 2's.
    foreach ([['1005', 4.5], ['1006', 4.5], ['2005', 3.0]] as [$ext, $mos]) {
        DB::table('voice_quality_reports')->insert([
            'extension' => $ext, 'branch_id' => null, 'mos_lq' => $mos,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    expect(check(score(1), 'mos_compliance')['total'])->toBe(2);
    expect(check(score(2), 'mos_compliance')['passing'])->toBe(0);
    expect(check(score(2), 'mos_compliance')['total'])->toBe(1);
});

// ─────────────────────────────────────────────────────────────────────
// Toner
// ─────────────────────────────────────────────────────────────────────

it('passes a printer when any single toner is above 30 percent', function () {
    BranchHealthSchema::seedBranches([[1, 'Test']]);
    Fx::printers(1, up: 1, down: 0, tonerPercent: null);
    $printerId = DB::table('printers')->value('id');
    Fx::toner($printerId, [5, 2, 45, 8]);   // three nearly empty, one healthy

    $c = check(score(), 'printer_toner');

    expect($c['passing'])->toBe(1)->and($c['points'])->toBe(4.0);
});

it('fails a printer whose toners are all at or below 30 percent', function () {
    BranchHealthSchema::seedBranches([[1, 'Test']]);
    Fx::printers(1, up: 1, down: 0, tonerPercent: null);
    Fx::toner(DB::table('printers')->value('id'), [30, 12, 4]);

    $c = check(score(), 'printer_toner');

    expect($c['passing'])->toBe(0)
        ->and($c['status'])->toBe('fail')
        ->and($c['failures'][0]['detail'])->toContain('highest 30%');
});

it('marks toner unknown when the only readings are stale', function () {
    BranchHealthSchema::seedBranches([[1, 'Test']]);
    Fx::printers(1, up: 1, down: 0, tonerPercent: null);
    Fx::toner(DB::table('printers')->value('id'), [80], at: now()->subHours(4));

    $c = check(score(), 'printer_toner');

    expect($c['passing'])->toBe(0)
        ->and($c['unknown'])->toBe(1)
        ->and($c['points'])->toBe(0.0);
});

it('ignores the legacy toner sentinels for unknown and not-applicable', function () {
    BranchHealthSchema::seedBranches([[1, 'Test']]);
    Fx::printers(1, up: 1, down: 0, tonerPercent: null);
    // -1 unknown, -2 n/a, -3 some remaining. A naive comparison reads these as
    // critically empty; only the real 60% reading should count.
    DB::table('printers')->update([
        'toner_black' => 60, 'toner_cyan' => -1,
        'toner_magenta' => -2, 'toner_yellow' => -3,
    ]);

    expect(check(score(), 'printer_toner')['passing'])->toBe(1);
});

// ─────────────────────────────────────────────────────────────────────
// Caps
// ─────────────────────────────────────────────────────────────────────

it('caps the score at 59 when every firewall is down, and says why', function () {
    Fx::healthy(1);
    DB::table('branch_tunnels')->update(['state' => 'down', 'last_ping_at' => now()]);

    $score = score();

    expect($score['raw_total'])->toBeGreaterThan(59)   // uncapped value preserved
        ->and($score['total'])->toBe(59)
        ->and(collect($score['cap_reasons'])->pluck('key'))->toContain('all_firewalls_down')
        ->and($score['cap_reasons'][0]['message'])->toContain('unreachable');
});

it('caps the score at 79 for an open critical firewall alert', function () {
    Fx::healthy(1);
    Fx::criticalAlert(1);

    $score = score();

    // The alert costs the 8-point firewall_alerts check on its own merits, and
    // THEN the cap applies on top — the two are independent.
    expect($score['raw_total'])->toBe(92)
        ->and($score['total'])->toBe(79)
        ->and(collect($score['cap_reasons'])->pluck('key'))->toContain('critical_firewall_alert');
});

it('caps on an acknowledged alert too — acknowledging is not fixing', function () {
    Fx::healthy(1);
    Fx::criticalAlert(1, status: 'acknowledged');

    expect(score()['total'])->toBe(79);
});

it('reports every triggered cap and applies the lowest', function () {
    Fx::healthy(1);
    DB::table('branch_tunnels')->update(['state' => 'down', 'last_ping_at' => now()]);
    Fx::criticalAlert(1);

    $score = score();

    expect($score['total'])->toBe(59)
        ->and($score['cap_reasons'])->toHaveCount(2);
});

it('does not cap a branch for another branch alert', function () {
    Fx::healthy(1);
    BranchHealthSchema::seedBranches([[2, 'Other']]);
    Fx::criticalAlert(2);

    expect(score(1)['total'])->toBe(100);
});

// ─────────────────────────────────────────────────────────────────────
// Isolation, consistency, coverage
// ─────────────────────────────────────────────────────────────────────

it('keeps branch scores isolated from one another', function () {
    Fx::healthy(1, 'Healthy');
    BranchHealthSchema::seedBranches([[2, 'Broken']]);
    Fx::switches(2, up: 0, down: 4);

    expect(score(1)['total'])->toBe(100);

    $two = score(2);
    expect(check($two, 'switch_reachability')['points'])->toBe(0.0)
        ->and(check($two, 'switch_reachability')['total'])->toBe(4);
});

it('returns identical results from allBranches() and scoreForBranch()', function () {
    Fx::healthy(1, 'Alpha');
    BranchHealthSchema::seedBranches([[2, 'Beta']]);
    Fx::switches(2, up: 1, down: 1);
    Fx::printers(2, up: 1, down: 0, tonerPercent: 50);

    $all = app(HealthScoringService::class)->allBranches()->keyBy('id');

    foreach ([1, 2] as $id) {
        $single = app(HealthScoringService::class)->scoreForBranch($id);

        // updated_at is stamped per evaluation, so compare everything else.
        expect(Arr::except($single, 'updated_at'))
            ->toEqual(Arr::except($all[$id]->health, 'updated_at'));
    }
});

it('sorts branches worst-first, then by name', function () {
    Fx::healthy(1, 'Zulu');            // 100
    BranchHealthSchema::seedBranches([[2, 'Alpha'], [3, 'Bravo']]);
    Fx::switches(2, up: 0, down: 2);   // low
    Fx::switches(3, up: 0, down: 2);   // same low score, name breaks the tie

    $names = app(HealthScoringService::class)->allBranches()->pluck('name')->all();

    expect($names[2])->toBe('Zulu')                       // healthiest last
        ->and(array_slice($names, 0, 2))->toBe(['Alpha', 'Bravo']);
});

it('reports coverage separately from score so gaps are visible', function () {
    BranchHealthSchema::seedBranches([[1, 'Partial']]);
    // Only switches configured: 8 of 100 points are measurable.
    Fx::switches(1, up: 2, down: 0);

    $score = score();

    // firewall_alerts (8) is always evaluable, plus switches (8).
    expect($score['coverage_percent'])->toBe(16)
        ->and($score['total'])->toBe(16)
        // Coverage-normalized, so a branch is not marked down for kit it has
        // not onboarded — but coverage_percent still exposes the gap.
        ->and($score['normalized_percent'])->toBe(100);
});

it('refuses to call a barely-monitored branch healthy', function () {
    BranchHealthSchema::seedBranches([[1, 'Barely']]);
    // Only switches configured — 16 of 100 points measurable, and both of those
    // points earned. Coverage-normalizing alone would score this 100% and badge
    // it Excellent, which is the exact "green because nothing is watched"
    // failure the model exists to prevent.
    Fx::switches(1, up: 2, down: 0);

    $score = score();

    expect($score['normalized_percent'])->toBe(100)   // still reported...
        ->and($score['coverage_percent'])->toBe(16)
        ->and($score['status'])->toBe('unknown');     // ...but not claimed as health
});

it('does call a mostly-monitored branch healthy', function () {
    Fx::healthy(1);
    // Strip the two checks a branch may legitimately not have onboarded yet.
    DB::table('devices')->where('type', 'biometric')->delete();
    DB::table('voice_mesh_nodes')->delete();

    $score = score();

    // 100 - 6 (biometrics) - 10 (voice mesh) = 84 measurable, comfortably above
    // the floor, so the branch is judged on what IS monitored.
    expect($score['coverage_percent'])->toBe(84)
        ->and($score['status'])->toBe('excellent');
});

it('never lets a branch look healthy on missing telemetry', function () {
    BranchHealthSchema::seedBranches([[1, 'Dark']]);
    Fx::firewall(1, state: 'down');

    $score = score();

    expect($score['status'])->not->toBeIn(['excellent', 'good'])
        ->and($score['total'])->toBeLessThan(60);
});

it('degrades gracefully for a branch that does not exist', function () {
    $score = app(HealthScoringService::class)->scoreForBranch(999, fresh: true);

    expect($score['status'])->toBe('unknown')->and($score['total'])->toBe(0);
});

// ─────────────────────────────────────────────────────────────────────
// Query bounding
// ─────────────────────────────────────────────────────────────────────

it('issues the same number of queries for one branch as for nine', function () {
    Fx::healthy(1);
    $countFor = function () {
        Cache::flush();
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(HealthScoringService::class)->allBranches();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    $one = $countFor();

    for ($i = 2; $i <= 9; $i++) {
        BranchHealthSchema::seedBranches([[$i, 'Branch '.$i]]);
        Fx::switches($i, up: 1, down: 0);
    }

    // The whole point of the bulk loader: cost tracks sources, not branches.
    expect($countFor())->toBe($one);
});
