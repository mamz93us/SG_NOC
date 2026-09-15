<?php

use App\Models\Archive\ArchiveField;
use App\Services\Archive\Ai\FieldExtractor;

/**
 * What the AI proposes for an index field, and every way that reply is refused.
 *
 * parse() is pure and static for the same reason PageReader::parse() is: the
 * Azure call cannot be exercised offline, but every way a reply can be wrong
 * can — and here a wrong reply that is accepted becomes a proposed invoice
 * number on the wrong purchase order.
 *
 * Dropping is always the safe answer. An empty field stays empty and a person
 * fills it in; a plausible-looking wrong value is what a reviewer approves by
 * mistake.
 */
uses(Tests\TestCase::class);

/** @return array<int,ArchiveField> */
function extractorFields(): array
{
    return [
        new ArchiveField(['key' => 'invoice_no', 'label' => 'Invoice Number', 'type' => ArchiveField::TYPE_TEXT]),
        new ArchiveField(['key' => 'total', 'label' => 'Total', 'type' => ArchiveField::TYPE_NUMBER]),
        new ArchiveField(['key' => 'issued', 'label' => 'Issue Date', 'type' => ArchiveField::TYPE_DATE]),
        new ArchiveField([
            'key' => 'doc_type',
            'label' => 'Document Type',
            'type' => ArchiveField::TYPE_LIST,
            'options' => ['Invoice', 'Credit Note'],
        ]),
    ];
}

function extractorReply(array $fields): string
{
    return json_encode(['fields' => $fields]);
}

// ─── A good reply ────────────────────────────────────────────────

test('a field comes back with its value, confidence and the page it was read off', function () {
    $content = extractorReply([
        'invoice_no' => ['value' => 'SPS-4471', 'confidence' => 96, 'page' => 1],
    ]);

    $proposals = FieldExtractor::parse($content, 'stop', extractorFields());

    expect($proposals)->toHaveKey('invoice_no');
    expect($proposals['invoice_no']['value'])->toBe('SPS-4471');
    expect($proposals['invoice_no']['confidence'])->toBe(96);
    // The evidence page is what makes review possible instead of theatre.
    expect($proposals['invoice_no']['page'])->toBe(1);
});

test('a field the document does not show is simply absent', function () {
    // A missing field is a correct answer. Every rule in parse() that drops a
    // value depends on this being ordinary rather than an error.
    $proposals = FieldExtractor::parse(extractorReply([]), 'stop', extractorFields());

    expect($proposals)->toBe([]);
});

// ─── Values made to fit their field ──────────────────────────────

test('a number keeps its digits and loses its thousands separators', function () {
    $proposals = FieldExtractor::parse(
        extractorReply(['total' => ['value' => '12,500.50', 'confidence' => 90, 'page' => 2]]),
        'stop',
        extractorFields(),
    );

    expect($proposals['total']['value'])->toBe('12500.50');
});

test('a number that is not a number is dropped rather than proposed', function () {
    // "12,500.50 SAR" in a numeric column is a value that cannot be saved. Left
    // out, the field stays empty; accepted, it fails on approval instead.
    $proposals = FieldExtractor::parse(
        extractorReply(['total' => ['value' => '12,500.50 SAR', 'confidence' => 88, 'page' => 2]]),
        'stop',
        extractorFields(),
    );

    expect($proposals)->not->toHaveKey('total');
});

test('a date is normalised, and an unreadable one is dropped', function () {
    $fields = extractorFields();

    $proposals = FieldExtractor::parse(
        extractorReply(['issued' => ['value' => '2024-03-04', 'confidence' => 80, 'page' => 1]]),
        'stop',
        $fields,
    );
    expect($proposals['issued']['value'])->toBe('2024-03-04');

    $proposals = FieldExtractor::parse(
        extractorReply(['issued' => ['value' => 'sometime in March', 'confidence' => 40, 'page' => 1]]),
        'stop',
        $fields,
    );
    expect($proposals)->not->toHaveKey('issued');
});

test('a list value must be one of the archive\'s own choices', function () {
    $fields = extractorFields();

    // Spelled differently, meant the same: kept, but written the way the archive
    // writes it, or the same value arrives in two casings and stops grouping.
    $proposals = FieldExtractor::parse(
        extractorReply(['doc_type' => ['value' => 'invoice', 'confidence' => 70, 'page' => 1]]),
        'stop',
        $fields,
    );
    expect($proposals['doc_type']['value'])->toBe('Invoice');

    // Not a choice at all: dropped. A list field with a value outside its
    // options is a value no search on that field will ever match.
    $proposals = FieldExtractor::parse(
        extractorReply(['doc_type' => ['value' => 'Quotation', 'confidence' => 95, 'page' => 1]]),
        'stop',
        $fields,
    );
    expect($proposals)->not->toHaveKey('doc_type');
});

// ─── Replies that must not be trusted ────────────────────────────

test('a field nobody asked for is ignored', function () {
    // The model inventing a column is not an error to fail the document over,
    // but it must never reach a proposal: there is no field to review it against.
    $proposals = FieldExtractor::parse(
        extractorReply([
            'invoice_no' => ['value' => 'SPS-4471', 'confidence' => 90, 'page' => 1],
            'supplier_iban' => ['value' => 'SA0380000000608010167519', 'confidence' => 99, 'page' => 1],
        ]),
        'stop',
        extractorFields(),
    );

    expect($proposals)->toHaveKey('invoice_no');
    expect($proposals)->not->toHaveKey('supplier_iban');
});

test('an empty value is not a proposal', function () {
    $proposals = FieldExtractor::parse(
        extractorReply([
            'invoice_no' => ['value' => '', 'confidence' => 10, 'page' => 1],
            'total' => ['value' => '   ', 'confidence' => 10, 'page' => 1],
        ]),
        'stop',
        extractorFields(),
    );

    expect($proposals)->toBe([]);
});

test('confidence is held inside 0-100 and a missing one is zero', function () {
    $proposals = FieldExtractor::parse(
        extractorReply([
            'invoice_no' => ['value' => 'SPS-4471', 'confidence' => 140, 'page' => 1],
            'total' => ['value' => '10', 'page' => 1],
        ]),
        'stop',
        extractorFields(),
    );

    // Bulk-approve works on a confidence threshold, so a 140 would sail over
    // every threshold anybody could set.
    expect($proposals['invoice_no']['confidence'])->toBe(100);
    expect($proposals['total']['confidence'])->toBe(0);
});

test('a page that is not a page number becomes no page', function () {
    $proposals = FieldExtractor::parse(
        extractorReply([
            'invoice_no' => ['value' => 'SPS-4471', 'confidence' => 90, 'page' => 'the first one'],
            'total' => ['value' => '10', 'confidence' => 90, 'page' => 0],
        ]),
        'stop',
        extractorFields(),
    );

    // Better no page than a wrong one: the reviewer opens the page it names.
    expect($proposals['invoice_no']['page'])->toBeNull();
    expect($proposals['total']['page'])->toBeNull();
});

test('a reply that is not the fields asked for is refused', function () {
    $fields = extractorFields();

    expect(fn () => FieldExtractor::parse('not json at all', 'stop', $fields))
        ->toThrow(RuntimeException::class, 'not the fields');

    expect(fn () => FieldExtractor::parse(json_encode(['invoice_no' => 'SPS-4471']), 'stop', $fields))
        ->toThrow(RuntimeException::class, 'not the fields');

    expect(fn () => FieldExtractor::parse(null, 'stop', $fields))
        ->toThrow(RuntimeException::class, 'not the fields');
});

test('a filtered document says so', function () {
    expect(fn () => FieldExtractor::parse(extractorReply([]), 'content_filter', extractorFields()))
        ->toThrow(RuntimeException::class, 'content filter');
});

test('a cut-off reply keeps the fields it managed to propose', function () {
    // Unlike a page of text, half a set of proposals is still half a set: each
    // one is reviewed on its own, so there is nothing to be misled by.
    $proposals = FieldExtractor::parse(
        extractorReply(['invoice_no' => ['value' => 'SPS-4471', 'confidence' => 90, 'page' => 1]]),
        'length',
        extractorFields(),
    );

    expect($proposals['invoice_no']['value'])->toBe('SPS-4471');

    // Cut off before anything came back at all is a failure, though.
    expect(fn () => FieldExtractor::parse('{"fiel', 'length', extractorFields()))
        ->toThrow(RuntimeException::class, 'cut off');
});
