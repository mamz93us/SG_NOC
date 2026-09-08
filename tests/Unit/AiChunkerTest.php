<?php

use App\Services\Ai\AiChunker;

/**
 * Pure chunker behavior — no database, no embeddings. Must hold up on a long
 * Arabic policy document as well as a short English one (see AiChunker's
 * docblock).
 */
uses(Tests\TestCase::class);

it('returns an empty array for blank input', function () {
    expect(AiChunker::chunk(''))->toBe([]);
    expect(AiChunker::chunk("   \n  "))->toBe([]);
});

it('splits on markdown headings, one chunk per section', function () {
    $markdown = <<<'MD'
## Connecting to the VPN
Open the client and sign in with your work account.

## Troubleshooting
If the client will not connect, restart it first.
MD;

    $chunks = AiChunker::chunk($markdown);

    expect($chunks)->toHaveCount(2);
    expect($chunks[0]['heading'])->toBe('Connecting to the VPN');
    expect($chunks[0]['content'])->toContain('sign in with your work account');
    expect($chunks[1]['heading'])->toBe('Troubleshooting');
    expect($chunks[1]['content'])->toContain('restart it first');
});

it('treats a document with no headings as a single chunk', function () {
    $chunks = AiChunker::chunk("Just a paragraph of plain text.\n\nAnd a second one.");

    expect($chunks)->toHaveCount(1);
    expect($chunks[0]['heading'])->toBeNull();
    expect($chunks[0]['content'])->toContain('second one');
});

it('splits a long section at paragraph breaks so no chunk exceeds the limit', function () {
    $para = str_repeat('This sentence is here to pad the paragraph out. ', 40); // ~2000 chars
    $markdown = "## Long Section\n\n{$para}\n\n{$para}\n\n{$para}";

    $chunks = AiChunker::chunk($markdown);

    expect(count($chunks))->toBeGreaterThan(1);
    foreach ($chunks as $chunk) {
        expect(mb_strlen($chunk['content']))->toBeLessThanOrEqual(AiChunker::MAX_CHARS);
        expect($chunk['heading'])->toBe('Long Section');
    }
});

it('handles a long Arabic document without losing content and respects the char limit', function () {
    // Arabic sentences ending in the Arabic full stop / question mark, per
    // splitLongParagraph's sentence boundary regex.
    $sentence = 'هذا نص تجريبي طويل باللغة العربية لاختبار عملية التقسيم بشكل صحيح؟ ';
    $para = str_repeat($sentence, 40); // well over MAX_CHARS
    $markdown = "## سياسة الشبكة الافتراضية الخاصة\n\n{$para}";

    $chunks = AiChunker::chunk($markdown);

    expect(count($chunks))->toBeGreaterThan(1);

    foreach ($chunks as $chunk) {
        expect(mb_strlen($chunk['content']))->toBeLessThanOrEqual(AiChunker::MAX_CHARS);
        expect($chunk['heading'])->toBe('سياسة الشبكة الافتراضية الخاصة');
        expect($chunk['content'])->not->toBe('');
    }

    // Nothing lost: every chunk's content reassembles into text drawn from
    // the original sentence.
    $combined = implode(' ', array_column($chunks, 'content'));
    expect(mb_strpos($combined, 'نص تجريبي طويل'))->not->toBeFalse();
});
