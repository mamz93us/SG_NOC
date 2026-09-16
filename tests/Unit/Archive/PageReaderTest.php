<?php

use App\Models\Archive\ArchiveAiSettings;
use App\Models\Archive\ArchiveAiUsage;
use App\Services\Archive\Ai\PageReader;
use Tests\Unit\Archive\ArchiveTestSchema;

/**
 * Reading a page, and the two things about it that can be checked without
 * Azure: how a reply is parsed, and when money may be spent.
 *
 * Everything else in PageReader is poppler and an HTTP call. parse() is
 * deliberately pure and static for exactly this reason — it is where a reply
 * goes wrong, and a wrong reply that is stored anyway becomes a scanned invoice
 * whose text is quietly half missing.
 */
uses(Tests\TestCase::class);

// ─── Parsing a reply ─────────────────────────────────────────────

test('a page comes back as its text', function () {
    $content = json_encode(['language' => 'en', 'text' => "INVOICE 4471\nTotal 12,500.50 SAR"]);

    expect(PageReader::parse($content, 'stop'))->toBe("INVOICE 4471\nTotal 12,500.50 SAR");
});

test('a cut-off reply is refused rather than stored as a short page', function () {
    // The dangerous case: half a page stored silently looks exactly like a page
    // that simply had little on it, and the missing half is never noticed.
    $content = json_encode(['language' => 'en', 'text' => 'INVOICE 4471, line 1 of 40...']);

    expect(fn () => PageReader::parse($content, 'length'))
        ->toThrow(RuntimeException::class, 'cut off');
});

test('a filtered page says so', function () {
    expect(fn () => PageReader::parse(json_encode(['text' => 'x']), 'content_filter'))
        ->toThrow(RuntimeException::class, 'content filter');
});

test('a reply that is not the page asked for is refused', function () {
    expect(fn () => PageReader::parse('not json at all', 'stop'))
        ->toThrow(RuntimeException::class, 'not the page');

    expect(fn () => PageReader::parse(json_encode(['language' => 'en']), 'stop'))
        ->toThrow(RuntimeException::class, 'not the page');

    // A page returned as a list of paragraphs must not pass for a blank page:
    // its content would be dropped without a word.
    expect(fn () => PageReader::parse(json_encode(['text' => ['a', 'b']]), 'stop'))
        ->toThrow(RuntimeException::class, 'not the page');

    expect(fn () => PageReader::parse(null, 'stop'))
        ->toThrow(RuntimeException::class, 'not the page');
});

test('a blank page is an empty string, not a failure', function () {
    // A genuinely blank scan — the back of a page — is a normal thing to find
    // in a 500,000-file archive and must not fail a batch.
    expect(PageReader::parse(json_encode(['language' => '', 'text' => '']), 'stop'))->toBe('');
    expect(PageReader::parse(json_encode(['text' => null]), 'stop'))->toBe('');
});

// ─── Spending ────────────────────────────────────────────────────

test('nothing is read until a budget has been set', function () {
    ArchiveTestSchema::create();

    // Zero means off, not unlimited — the safer reading of "nobody has set
    // this yet", and the difference between a quiet month and a surprise bill.
    $settings = ArchiveAiSettings::get();

    expect($settings->budget())->toBe(0.0);
    expect($settings->withinBudget())->toBeFalse();

    ArchiveTestSchema::drop();
});

test('the month is measured from what was actually spent', function () {
    ArchiveTestSchema::create();

    $settings = ArchiveAiSettings::get();
    $settings->forceFill(['monthly_budget_usd' => 1.00, 'page_read_cost_usd' => 0.01])->save();

    expect($settings->withinBudget())->toBeTrue();
    expect($settings->estimateFor(250))->toBe(2.5);

    ArchiveAiUsage::record(ArchiveAiUsage::FEATURE_READ, 0.60, pages: 60);
    expect(ArchiveAiUsage::spentThisMonth())->toBe(0.6);
    expect(ArchiveAiSettings::get()->remainingBudget())->toBe(0.4);

    // Spending past the cap closes it, rather than being noticed afterwards.
    ArchiveAiUsage::record(ArchiveAiUsage::FEATURE_READ, 0.45, pages: 45);
    expect(ArchiveAiSettings::get()->withinBudget())->toBeFalse();

    ArchiveTestSchema::drop();
});

test('one person cannot spend the month in an afternoon', function () {
    ArchiveTestSchema::create();

    ArchiveAiUsage::record(ArchiveAiUsage::FEATURE_ASK_DOCUMENT, 0.30, pages: 30, userId: 7);
    ArchiveAiUsage::record(ArchiveAiUsage::FEATURE_ASK_DOCUMENT, 0.20, pages: 20, userId: 7);

    expect(ArchiveAiUsage::pagesToday(7))->toBe(50);
    // Somebody else's reading is not counted against them.
    expect(ArchiveAiUsage::pagesToday(8))->toBe(0);
    expect(ArchiveAiUsage::pagesToday(null))->toBe(0);

    ArchiveTestSchema::drop();
});
