<?php

use App\Models\Archive\ArchiveAiSettings;
use App\Models\Archive\ArchiveAiUsage;
use App\Services\Archive\Ai\PageReader;
use Tests\Unit\Archive\ArchiveTestSchema;

/**
 * The two caps that stand between this feature and a surprise bill, and the
 * pricing they are measured against.
 *
 * Every one of these tests exists because the thing it checks was wrong. The
 * per-person daily cap was enforced on filed documents and silently skipped on
 * uploaded scans and on asking questions; conversations were priced at a flat
 * half-page whatever they used, so the recorded spend ran below the real one —
 * and since the cap is checked against recorded spend, a figure that reads low is
 * a cap that keeps saying yes.
 *
 * The caps are checked through PageReader::maySpend(), which is deliberately the
 * single place that knows them. A path that asks a different question is a path
 * with a different limit.
 */
uses(Tests\TestCase::class);

beforeEach(function () {
    ArchiveTestSchema::create();

    $this->settings = ArchiveAiSettings::get();
    $this->settings->forceFill([
        'monthly_budget_usd' => 50.00,
        'per_user_daily_pages' => 10,
        'page_read_cost_usd' => 0.01,
        'prompt_token_cost_usd' => 0.0025,
        'completion_token_cost_usd' => 0.01,
    ])->save();

    $this->reader = new PageReader;
});

afterEach(function () {
    ArchiveTestSchema::drop();
});

// ─── The month's budget ──────────────────────────────────────────

test('a zero budget means off, not unlimited', function () {
    $this->settings->forceFill(['monthly_budget_usd' => 0])->save();

    expect($this->reader->maySpend(ArchiveAiSettings::get(), 7))
        ->toContain('No AI budget');
});

test('spending past the month stops everything, whoever is asking', function () {
    ArchiveAiUsage::record(ArchiveAiUsage::FEATURE_READ, 50.00, pages: 5000);

    // Nobody's allowance matters once the month's money is gone.
    expect($this->reader->maySpend(ArchiveAiSettings::get(), 7))->toContain('budget for this month');
    expect($this->reader->maySpend(ArchiveAiSettings::get(), null))->toContain('budget for this month');
});

// ─── One person's day ────────────────────────────────────────────

test('one person cannot spend the month in an afternoon', function () {
    ArchiveAiUsage::record(ArchiveAiUsage::FEATURE_READ, 0.10, pages: 10, userId: 7);

    expect($this->reader->maySpend(ArchiveAiSettings::get(), 7))
        ->toContain("today's limit of 10 pages");

    // Somebody else's day is their own.
    expect($this->reader->maySpend(ArchiveAiSettings::get(), 8))->toBeNull();
});

test('unattended work is not charged against anybody\'s day', function () {
    // A batch is nobody's afternoon: it runs for hours under the month's budget
    // and must not be stopped by one person's daily allowance. Passing null is
    // how the workers say so, and it must skip the per-person cap WITHOUT
    // skipping the budget (proved by the test above).
    ArchiveAiUsage::record(ArchiveAiUsage::FEATURE_READ, 5.00, pages: 500, userId: 7);

    expect($this->reader->maySpend(ArchiveAiSettings::get(), null))->toBeNull();
});

test('a cap of zero pages means no limit per person, not no pages', function () {
    // 0 is "unset" here, and reading it as "nobody may read anything" would
    // switch the whole feature off for everyone the moment somebody cleared the
    // field.
    $this->settings->forceFill(['per_user_daily_pages' => 0])->save();

    ArchiveAiUsage::record(ArchiveAiUsage::FEATURE_READ, 1.00, pages: 100, userId: 7);

    expect($this->reader->maySpend(ArchiveAiSettings::get(), 7))->toBeNull();
});

test('the daily cap counts pages, not conversations', function () {
    // Chat is recorded with pages: 0 on purpose — a question is not a page read.
    // Recording it as a page would have questions eating a reading allowance.
    ArchiveAiUsage::record(ArchiveAiUsage::FEATURE_ASK_ARCHIVE, 0.05, pages: 0, userId: 7);
    ArchiveAiUsage::record(ArchiveAiUsage::FEATURE_ASK_ARCHIVE, 0.05, pages: 0, userId: 7);

    expect(ArchiveAiUsage::pagesToday(7))->toBe(0);
    expect($this->reader->maySpend(ArchiveAiSettings::get(), 7))->toBeNull();

    // But the money still counts against the month.
    expect(ArchiveAiUsage::spentThisMonth())->toBe(0.1);
});

// ─── What a conversation costs ───────────────────────────────────

test('a conversation is priced from the tokens it actually used', function () {
    $settings = ArchiveAiSettings::get();

    // 10,000 in at $0.0025/1k and 1,000 out at $0.01/1k.
    expect($settings->chatCost(10000, 1000))->toBe(0.035);

    // The regression: this used to be pageCost * 0.5 — one flat figure for a
    // one-line question and for a six-turn tool loop over 40,000 characters of
    // scanned text alike, which is 0.005 either way.
    expect($settings->chatCost(10000, 1000))->toBeGreaterThan($settings->pageCost() * 0.5);
});

test('a trivial question costs almost nothing, and a long one costs more', function () {
    $settings = ArchiveAiSettings::get();

    $small = $settings->chatCost(400, 80);
    $large = $settings->chatCost(40000, 1200);

    expect($small)->toBeLessThan($large);
    // The whole point: the difference is visible in the month's figure rather
    // than flattened away.
    expect($large)->toBeGreaterThan($small * 10);
});

test('missing token counts cost nothing rather than throwing', function () {
    // Azure has returned a body without usage before now. A meter that fatals is
    // worse than one that records zero, because it takes the answer with it.
    expect(ArchiveAiSettings::get()->chatCost(0, 0))->toBe(0.0);
    expect(ArchiveAiSettings::get()->chatCost(-5, -5))->toBe(0.0);
});

test('conversation spend lands in the month and closes the budget', function () {
    $this->settings->forceFill(['monthly_budget_usd' => 1.00])->save();

    $settings = ArchiveAiSettings::get();
    $cost = $settings->chatCost(200000, 20000); // a very heavy day of asking

    ArchiveAiUsage::record(ArchiveAiUsage::FEATURE_ASK_ARCHIVE, $cost, pages: 0, userId: 7);

    // $0.50 in + $0.20 out = $0.70 against a $1.00 budget — visible, and the next
    // one closes it. Under the old flat price this was recorded as $0.005.
    expect($cost)->toBe(0.7);
    expect(ArchiveAiSettings::get()->withinBudget())->toBeTrue();

    ArchiveAiUsage::record(ArchiveAiUsage::FEATURE_ASK_ARCHIVE, $cost, pages: 0, userId: 7);
    expect(ArchiveAiSettings::get()->withinBudget())->toBeFalse();
});
