<?php

use App\Services\Recruitment\SalaryAnswers;

it('tells a question about the applicant\'s own salary from one that only mentions salary', function (string $question, ?string $kind) {
    expect(SalaryAnswers::kind($question))->toBe($kind);
})->with([
    // Titles of real application questions in Teamtailor (2026-09-15).
    'expected' => ['What is your expected salary?', 'expected'],
    'minimum expected monthly' => ['What is your minimum expected monthly salary?', 'expected'],
    'range expecting' => ['What salary range are you expecting for this position (in SAR)?', 'expected'],
    'current details' => ['What is your current salary details (basic salary, other incentives and benefits)', 'current'],
    'current net with currency' => ['What is your current monthly net salary? (EGP)', 'current'],
    'last' => ['What was your last salary?', 'current'],
    'both' => ['What are your current and expected salaries?', 'both'],
    'Arabic expected' => ['ما هو الراتب المتوقع؟', 'expected'],
    'Arabic current' => ['كم راتبك الحالي؟', 'current'],
    'payroll test' => ['what is the net salary of 14,000 EGP?', null],
    'deduction scenario' => ['Your manager criticizes you in front of your colleagues and deducts money from your salary for an incident you believe you were not responsible for. How would you handle the situation?', null],
    'Arabic payroll cycle' => ['كلمينى عن دورة المرتبات من اولها لاخرها', null],
    'not about pay' => ['Notice period?', null],
]);

it('reads the amounts people write', function (string $answer, array $amounts) {
    expect(SalaryAnswers::amounts($answer))->toBe($amounts);
})->with([
    'plain' => ['12000', [12000]],
    'comma' => ['12,000 EGP', [12000]],
    'dot as thousands' => ['12.500', [12500]],
    'space as thousands' => ['12 000 SAR', [12000]],
    'k' => ['15k', [15000]],
    'range in k' => ['10-12k', [10000, 12000]],
    'thousand in words' => ['15 thousand', [15000]],
    'Arabic digits and word' => ['١٥ ألف جنيه', [15000]],
    'range in Arabic' => ['من 10 الى 12 الف', [10000, 12000]],
    'a year beside it' => ['15000 (2024)', [15000]],
    'years of experience' => ['5 years', []],
    'no figure' => ['Negotiable', []],
]);

it('takes the first expected and current salary, a range as a range, and each figure\'s own currency', function () {
    $salary = SalaryAnswers::extract([
        ['question' => 'Notice period?', 'answer' => '2 months'],
        ['question' => 'What salary range are you expecting for this position (in SAR)?', 'answer' => '10,000 - 12,000'],
        ['question' => 'What is your current monthly net salary? (EGP)', 'answer' => '9000'],
        ['question' => 'What is your expected salary?', 'answer' => '20000', 'from' => 'Driver'],
    ]);

    expect($salary['expected'])->toBe(['min' => 10000, 'max' => 12000, 'currency' => 'SAR', 'text' => '10,000 - 12,000', 'from' => null])
        ->and($salary['current'])->toBe(['amount' => 9000, 'currency' => 'EGP', 'text' => '9000', 'from' => null]);
});

it('fills in a currency from the other figure, then the job\'s, and keeps where each was given', function () {
    $salary = SalaryAnswers::extract([
        ['question' => 'What is your expected salary?', 'answer' => '15k', 'from' => 'Senior Accountant'],
        ['question' => 'What is your current salary?', 'answer' => '11,000 جنيه'],
    ], 'SAR');

    expect($salary['expected'])->toMatchArray(['min' => 15000, 'max' => 15000, 'currency' => 'EGP', 'from' => 'Senior Accountant'])
        ->and($salary['current'])->toMatchArray(['amount' => 11000, 'currency' => 'EGP', 'from' => null])
        ->and(SalaryAnswers::extract([['question' => 'What is your expected salary?', 'answer' => '12000']], 'sar')['expected']['currency'])->toBe('SAR')
        ->and(SalaryAnswers::extract([['question' => 'What is your expected salary?', 'answer' => '12000']])['expected']['currency'])->toBeNull();
});

it('reads a question that asks both in the order it asks them', function () {
    $currentFirst = SalaryAnswers::extract([['question' => 'What are your current and expected salaries?', 'answer' => '9000 now, 12000 expected']]);
    $expectedFirst = SalaryAnswers::extract([['question' => 'What is your expected and current salary?', 'answer' => '12000 / 9000']]);

    expect([$currentFirst['current']['amount'], $currentFirst['expected']['min']])->toBe([9000, 12000])
        ->and([$expectedFirst['current']['amount'], $expectedFirst['expected']['min']])->toBe([9000, 12000]);
});

it('ignores figures given to questions that are not about the applicant\'s pay', function () {
    expect(SalaryAnswers::extract([
        ['question' => 'what is the net salary of 14,000 EGP?', 'answer' => '11,500'],
        ['question' => 'What is your expected salary?', 'answer' => 'Negotiable'],
    ]))->toBe(['expected' => null, 'current' => null]);
});

it('reads a bare one- or two-digit figure as thousands, and a bare 0 only as no salary now', function () {
    $salary = SalaryAnswers::extract([
        ['question' => 'What is your expected monthly net salary? (EGP)', 'answer' => '25'],
        ['question' => 'What is your current monthly net salary? (EGP)', 'answer' => '0'],
    ]);

    expect($salary['expected'])->toMatchArray(['min' => 25000, 'max' => 25000, 'currency' => 'EGP', 'text' => '25'])
        ->and($salary['current'])->toMatchArray(['amount' => 0, 'currency' => 'EGP', 'text' => '0'])
        ->and(SalaryAnswers::label($salary['current']))->toBe('0 EGP')
        ->and(SalaryAnswers::raisePercent($salary['expected'], $salary['current']))->toBeNull()
        ->and(SalaryAnswers::extract([['question' => 'What is your expected salary?', 'answer' => '0']])['expected'])->toBeNull()
        ->and(SalaryAnswers::extract([['question' => 'What is your expected salary?', 'answer' => '7k EGP']])['expected']['min'])->toBe(7000);
});

it('shows figures for people, and the raise asked for', function () {
    expect(SalaryAnswers::label(['min' => 10000, 'max' => 12000, 'currency' => 'SAR']))->toBe('10,000–12,000 SAR')
        ->and(SalaryAnswers::label(['amount' => 9000, 'currency' => null]))->toBe('9,000')
        ->and(SalaryAnswers::label(null))->toBeNull()
        ->and(SalaryAnswers::raisePercent(['min' => 12000, 'currency' => 'EGP'], ['amount' => 10000, 'currency' => 'EGP']))->toBe(20)
        ->and(SalaryAnswers::raisePercent(['min' => 12000, 'currency' => 'SAR'], ['amount' => 10000, 'currency' => 'EGP']))->toBeNull();
});
