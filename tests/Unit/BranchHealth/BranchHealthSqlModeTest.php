<?php

use App\Services\HealthScoringService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\BranchHealthFixture as Fx;
use Tests\Support\BranchHealthSchema;

/**
 * Guards against SQL this suite's database accepts but production's rejects.
 *
 * The tests run on SQLite; production is MySQL 8 with ONLY_FULL_GROUP_BY on by
 * default. That gap has already cost one outage: the MOS query selected a bare
 * `CASE WHEN created_at >= ?` as a recency flag and repeated it in GROUP BY.
 * MySQL cannot prove the two expressions are the same -- each `?` is a distinct
 * parameter -- so it rejected the whole statement with errno 1055, and the NOC
 * dashboard and health board both 500'd. Every unit test passed.
 *
 * These inspect the SQL the loader actually builds, so the rule is enforced on
 * whatever database happens to be running the tests.
 */
uses(Tests\TestCase::class);

beforeEach(function () {
    BranchHealthSchema::create();
    Cache::flush();
});

/** @return array<int, string> every statement the scorer issues */
function capturedSql(): array
{
    $sql = [];
    DB::listen(function ($q) use (&$sql) {
        $sql[] = $q->sql;
    });

    app(HealthScoringService::class)->allBranches();

    return $sql;
}

it('never groups by a parameterised or computed expression', function () {
    Fx::healthy(1, 'Jeddah');
    Fx::mos(1, passing: 3, failing: 1, at: now()->subDays(3));

    $offenders = [];

    foreach (capturedSql() as $sql) {
        if (! preg_match('/\bgroup by\b(.*?)(?:\border by\b|\bhaving\b|\blimit\b|$)/is', $sql, $m)) {
            continue;
        }

        $groupBy = strtolower($m[1]);

        // A placeholder in GROUP BY is the specific trap: MySQL treats each one
        // as its own parameter, so a matching expression in the select list is
        // not recognised as functionally dependent. A CASE is the same hazard in
        // a different shape.
        if (str_contains($groupBy, '?') || str_contains($groupBy, 'case')) {
            $offenders[] = $sql;
        }
    }

    // Collected, then asserted once. expect()->toContain() is variadic, so
    // passing a failure message as a second argument silently turns it into a
    // second needle and the negation stops meaning what it appears to mean --
    // which is how the first version of this guard passed against the very bug
    // it was written to catch.
    expect($offenders)->toBe([]);
});

it('keeps every conditional inside an aggregate in the MOS query', function () {
    Fx::healthy(1, 'Jeddah');
    Fx::mos(1, passing: 3, failing: 1, at: now()->subDays(3));

    // Not just any statement naming the table -- Schema::hasTable() mentions it
    // too. The aggregate is the one with a GROUP BY.
    $mos = collect(capturedSql())->first(fn ($s) => str_contains($s, 'voice_quality_reports')
        && str_contains(strtolower($s), 'group by'));

    expect($mos)->not->toBeNull();

    // Split the select list off, and check no CASE sits outside a SUM(...).
    preg_match('/^select\s+(.*?)\s+from\b/is', $mos, $m);
    $selectList = $m[1] ?? '';

    $bareCase = preg_replace('/sum\s*\([^)]*\)/i', '', $selectList);

    expect(strtolower($bareCase))->not->toContain('case',
        "a CASE outside an aggregate will fail ONLY_FULL_GROUP_BY: {$mos}");

    // And the grouping itself is plain columns.
    expect(strtolower($mos))->toContain('group by "extension", "branch_id"');
})->skip(fn () => DB::getDriverName() !== 'sqlite', 'identifier quoting is driver-specific');

it('still produces the right MOS numbers after the rewrite', function () {
    BranchHealthSchema::seedBranches([[1, 'Test']]);
    // Quiet day, busier week: the widen-to-7d path, which the old query handled
    // by emitting two rows per extension and the new one by two column sets.
    Fx::mos(1, passing: 2, failing: 0, at: now()->subHours(2));
    Fx::mos(1, passing: 6, failing: 2, at: now()->subDays(3));

    $score = app(HealthScoringService::class)->scoreForBranch(1, fresh: true);
    $check = collect($score['categories'])->firstWhere('key', 'voip')['checks'];
    $mos = collect($check)->firstWhere('key', 'mos_compliance');

    expect($mos['window'])->toBe('7d')
        ->and($mos['total'])->toBe(10)
        ->and($mos['passing'])->toBe(8);
});

it('counts only the recent window once the day is busy enough', function () {
    BranchHealthSchema::seedBranches([[1, 'Test']]);
    Fx::mos(1, passing: 6, failing: 0, at: now()->subHours(2));
    Fx::mos(1, passing: 0, failing: 40, at: now()->subDays(3));

    $score = app(HealthScoringService::class)->scoreForBranch(1, fresh: true);
    $check = collect($score['categories'])->firstWhere('key', 'voip')['checks'];
    $mos = collect($check)->firstWhere('key', 'mos_compliance');

    // The recent bucket must not be polluted by the older rows the same query
    // also aggregates.
    expect($mos['window'])->toBe('24h')
        ->and($mos['total'])->toBe(6)
        ->and($mos['passing'])->toBe(6);
});
