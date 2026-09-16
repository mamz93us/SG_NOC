<?php

use App\Services\Archive\Ai\ArchiveLinks;

/**
 * Pulling document links out of an assistant answer so the widget can draw a
 * button instead.
 *
 * Every case here is one the chat actually renders as plain text, so a link left
 * in the string reaches the person as a raw URL.
 */
uses(Tests\TestCase::class);

$host = 'archive.samirgroup.net';

test('the broken markdown production produced becomes a readable sentence', function () use ($host) {
    // Exactly what the assistant replied: a space between ] and (, so it is not
    // valid markdown and rendered literally.
    [$text, $ids] = ArchiveLinks::strip(
        'The invoice 1000003739 is available. You can view it [here] (https://archive.samirgroup.net/documents/189556).',
        $host
    );

    expect($ids)->toBe([189556]);
    expect($text)->toBe('The invoice 1000003739 is available. You can view it here.');
    expect($text)->not->toContain('http');
});

test('proper markdown keeps its label too', function () use ($host) {
    [$text, $ids] = ArchiveLinks::strip('Open it [here](https://archive.samirgroup.net/documents/42).', $host);

    expect($ids)->toBe([42]);
    expect($text)->toBe('Open it here.');
});

test('a bare url is removed without leaving a gap', function () use ($host) {
    [$text, $ids] = ArchiveLinks::strip(
        'Invoice 4471: https://archive.samirgroup.net/documents/900 . Anything else?',
        $host
    );

    expect($ids)->toBe([900]);
    expect($text)->not->toContain('http');
    expect($text)->not->toContain('  ');
});

test('several documents come back in the order they were mentioned', function () use ($host) {
    [$text, $ids] = ArchiveLinks::strip(
        'Two match: [one](https://archive.samirgroup.net/documents/5) and [two](https://archive.samirgroup.net/documents/9).',
        $host
    );

    expect($ids)->toBe([5, 9]);
    expect($text)->toBe('Two match: one and two.');
});

test('the same document mentioned twice is one button', function () use ($host) {
    [, $ids] = ArchiveLinks::strip(
        '[here](https://archive.samirgroup.net/documents/7) or here https://archive.samirgroup.net/documents/7',
        $host
    );

    expect($ids)->toBe([7]);
});

test('a link to a file under the document is still that document', function () use ($host) {
    [, $ids] = ArchiveLinks::strip('https://archive.samirgroup.net/documents/12/files/3/view', $host);

    expect($ids)->toBe([12]);
});

test('an answer with no links is left exactly alone', function () use ($host) {
    $original = 'I could not find an invoice with that number. Check the number and try again.';
    [$text, $ids] = ArchiveLinks::strip($original, $host);

    expect($text)->toBe($original);
    expect($ids)->toBe([]);
});

test('another site is not touched', function () use ($host) {
    // Only OUR archive becomes a button; a knowledge-base citation stays as it is.
    $original = 'See https://noc.samirgroup.net/documents/5 for the policy.';
    [$text, $ids] = ArchiveLinks::strip($original, $host);

    expect($ids)->toBe([]);
    expect($text)->toBe($original);
});

test('a document id is not confused with a huge number', function () use ($host) {
    [, $ids] = ArchiveLinks::strip('https://archive.samirgroup.net/documents/9999999999999999', $host);

    expect($ids)->toBe([]);
});
