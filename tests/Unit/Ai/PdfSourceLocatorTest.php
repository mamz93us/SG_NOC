<?php

use App\Services\Ai\PdfSourceLocator;

/**
 * Citations an employee can check against the PDF: the page a chunk starts
 * on, and the heading as the PDF writes it. The imported labor law spells its
 * article numbers out in Arabic words, so the translated "Article 198" the
 * assistant cited was nowhere in the PDF.
 */
uses(Tests\TestCase::class);

function lawPages(): array
{
    return [
        [
            'language' => 'ar',
            'original' => "## المادة الأولى:\nيسمى هذا النظام نظام العمل.",
            'english' => "## Article 1:\nThis law is called the Labor Law.",
        ],
        ['language' => '', 'original' => '', 'english' => ''], // a blank page
        [
            'language' => 'ar',
            'original' => "## المادة الثامنة والتسعون بعد المائة:\nلمفتش العمل حق دخول المنشأة.\n\n## المادة التاسعة والتسعون بعد المائة:\nعلى صاحب العمل تقديم التسهيلات.",
            'english' => "## Article 198:\nA labor inspector may enter the establishment.\n\n## Article 199:\nThe employer must provide facilities.",
        ],
        [
            'language' => 'ar',
            'original' => 'وتستمر أحكام هذه المادة على كل منشأة.',
            'english' => 'The provisions of this article continue to apply to every establishment.',
        ],
    ];
}

it('gives an English chunk its page and the heading as the PDF writes it', function () {
    expect((new PdfSourceLocator(lawPages()))->locate('en', 'Article 199:', 'The employer must provide facilities.'))
        ->toBe(['page' => 3, 'heading' => 'المادة التاسعة والتسعون بعد المائة:']);
});

it('gives an Arabic chunk its page, its heading already being the original', function () {
    expect((new PdfSourceLocator(lawPages()))->locate('ar', 'المادة الثامنة والتسعون بعد المائة:', 'لمفتش العمل حق دخول المنشأة.'))
        ->toBe(['page' => 3, 'heading' => null]);
});

it('cites the page the text is on when a section runs onto the next one, counting blank pages', function () {
    expect((new PdfSourceLocator(lawPages()))->locate('en', 'Article 199:', 'The provisions of this article continue to apply to every establishment.'))
        ->toBe(['page' => 4, 'heading' => 'المادة التاسعة والتسعون بعد المائة:']);
});

it('has no page for text that is not in the PDF, such as an article edited after the import', function () {
    expect((new PdfSourceLocator(lawPages()))->locate('en', 'Article 1:', 'Rewritten by an admin after the import.'))
        ->toBe(['page' => null, 'heading' => null]);
});

it('finds the page and original heading of an article the page left unmarked', function () {
    $pages = [
        ['language' => 'ar', 'original' => "## المادة التاسعة والستون:\nلا يجوز اتهام العامل بمخالفة.", 'english' => "## Article 69:\nA worker may not be charged."],
        [
            'language' => 'ar',
            'original' => "المادة السبعون:\nلا يجوز توقيع جزاء تأديبي.\n\nالمادة الحادية والسبعون:\nيجب إبلاغ العامل كتابة.",
            'english' => "Article 70:\nNo disciplinary penalty may be imposed.\n\nArticle 71:\nThe worker must be notified in writing.",
        ],
    ];

    expect((new PdfSourceLocator($pages))->locate('en', 'Article 71:', 'The worker must be notified in writing.'))
        ->toBe(['page' => 2, 'heading' => 'المادة الحادية والسبعون:']);
});

it('gives no original heading for a document that was in English to begin with', function () {
    $pages = [['language' => 'en', 'original' => "## Connecting\nOpen the client.", 'english' => "## Connecting\nOpen the client."]];

    expect((new PdfSourceLocator($pages))->locate('en', 'Connecting', 'Open the client.'))
        ->toBe(['page' => 1, 'heading' => null]);
});
