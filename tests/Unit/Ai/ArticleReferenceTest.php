<?php

use App\Services\Ai\ArticleReference;

/**
 * Article numbers as the imported labor law heads them and as employees ask
 * for them. The Arabic headings below are the law's own.
 */
uses(Tests\TestCase::class);

it('reads the article a question names, however it is typed', function (string $query, ?string $key) {
    expect(ArticleReference::inQuery($query))->toBe($key);
})->with([
    ['المادة 77', '77'],
    ['الماده ٧٧', '77'],
    ['المادة رقم (77) من نظام العمل', '77'],
    ['المادة السابعة والسبعون', '77'],
    ['الماده السابعه و السبعون', '77'],
    ['السابعة و السبعون', '77'],
    ['هل المادة السابعة والسبعين ملغاة؟', '77'],
    ['Article 77', '77'],
    ['what does article 77 of the labor law say', '77'],
    ['المادة التاسعة والسبعون مكرر', '79-bis'],
    ['Article 79 bis', '79-bis'],
    ['المادة السابعة والسبعون بعد المائة', '177'],
    ['فصل الموظف', null],
    ['ما هي المادة الخاصة بالإجازات', null],
    ['annual leave article', null],
]);

it('reads the article a heading is, in words or in digits, and nothing else', function () {
    expect(ArticleReference::ofHeading('المادة السابعة والسبعون:'))->toBe('77')
        ->and(ArticleReference::ofHeading('المادة السابعة والسبعون بعد المائة:'))->toBe('177')
        ->and(ArticleReference::ofHeading('المادة الخامسة عشرة بعد المائة:'))->toBe('115')
        ->and(ArticleReference::ofHeading('المادة الحادية عشرة:'))->toBe('11')
        ->and(ArticleReference::ofHeading('المادة الأولى:'))->toBe('1')
        ->and(ArticleReference::ofHeading('المادة المائة:'))->toBe('100')
        ->and(ArticleReference::ofHeading('المادة الخامسة والأربعون بعد المائتين:'))->toBe('245')
        ->and(ArticleReference::ofHeading('المادة التاسعة والسبعون مكرر:'))->toBe('79-bis')
        ->and(ArticleReference::ofHeading('Article 77:'))->toBe('77')
        ->and(ArticleReference::ofHeading('Article 79 Bis: Compensation'))->toBe('79-bis')
        ->and(ArticleReference::ofHeading('الفصل الثاني'))->toBeNull()
        ->and(ArticleReference::ofHeading('Notes:'))->toBeNull()
        ->and(ArticleReference::ofHeading(null))->toBeNull();
});

it('takes a line for an article label only when the line is nothing but the label', function () {
    expect(ArticleReference::isLabel('المادة السبعون:'))->toBeTrue()
        ->and(ArticleReference::isLabel('**Article 70:**'))->toBeTrue()
        ->and(ArticleReference::isLabel('Article 1: This law is called the Labor Law.'))->toBeFalse()
        ->and(ArticleReference::isLabel('المادة الخامسة من هذا النظام'))->toBeFalse()
        ->and(ArticleReference::isLabel('المادة'))->toBeFalse();
});
