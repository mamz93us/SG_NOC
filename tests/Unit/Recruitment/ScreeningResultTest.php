<?php

use App\Services\Recruitment\CandidateScreener;
use App\Services\Recruitment\JobAd;
use App\Services\Recruitment\SalaryAnswers;
use App\Services\Recruitment\ScreeningResult;

it('keeps a clean evaluation and takes the fit label from the score', function () {
    $result = ScreeningResult::parse(json_encode([
        'score' => 88,
        'summary' => 'Eight years of accounts payable in a group company.',
        'current_role' => 'AP Lead, Example Co',
        'relevant_years' => 8,
        'must_haves' => [
            ['requirement' => 'SAP FICO', 'met' => 'yes', 'evidence' => 'SAP FICO since 2019'],
            ['requirement' => '5+ years accounting', 'met' => 'yes', 'evidence' => '2016-2024'],
        ],
        'strengths' => ['SAP', 'SAP'],
        'skills' => ['SAP FICO', 'IFRS'],
        'interview_questions' => ['a', 'b', 'c', 'd', 'e'],
    ]), 'stop', ['SAP FICO', '5+ years accounting']);

    expect($result->score)->toBe(88)
        ->and($result->fit)->toBe('strong')
        ->and($result->mustHavesMet)->toBe(2)
        ->and($result->mustHavesTotal)->toBe(2)
        ->and($result->evaluation['strengths'])->toBe(['SAP'])
        ->and($result->evaluation['interview_questions'])->toHaveCount(4)
        ->and($result->evaluation['relevant_years'])->toBe(8.0)
        ->and($result->evaluation['education'])->toBeNull();
});

it('caps the score when a must-have is clearly not met, whatever the model scored', function () {
    $result = ScreeningResult::parse(json_encode([
        'score' => 91,
        'must_haves' => [['requirement' => 'Saudi driving licence', 'met' => 'no', 'evidence' => 'Holds a UAE licence only']],
    ]), 'stop', ['Saudi driving licence']);

    expect($result->score)->toBe(ScreeningResult::CAP_WHEN_MISSING)
        ->and($result->fit)->toBe('weak')
        ->and($result->mustHavesMet)->toBe(0);
});

it('does not cap a must-have that is only unclear', function () {
    $result = ScreeningResult::parse(json_encode([
        'score' => 72,
        'must_haves' => [['requirement' => 'Arabic', 'met' => 'unclear', 'evidence' => 'No languages listed']],
    ]), 'stop', ['Arabic']);

    expect($result->score)->toBe(72)->and($result->fit)->toBe('good');
});

it('matches each must-have by its wording before its position', function () {
    $result = ScreeningResult::parse(json_encode([
        'score' => 60,
        'must_haves' => [
            ['requirement' => 'Arabic', 'met' => 'yes', 'evidence' => 'Native Arabic'],
            ['requirement' => 'SAP', 'met' => 'no', 'evidence' => 'No ERP experience'],
        ],
    ]), 'stop', ['SAP', 'Arabic']);

    expect($result->evaluation['must_haves'][0])->toMatchArray(['requirement' => 'SAP', 'met' => 'no', 'evidence' => 'No ERP experience'])
        ->and($result->evaluation['must_haves'][1])->toMatchArray(['requirement' => 'Arabic', 'met' => 'yes']);
});

it('treats a missing or odd must-have answer as unclear', function () {
    $result = ScreeningResult::parse(json_encode(['score' => 55, 'must_haves' => [['met' => 'maybe']]]), 'stop', ['SAP', 'Arabic']);

    expect(array_column($result->evaluation['must_haves'], 'met'))->toBe(['unclear', 'unclear']);
});

it('reads where the applicant lives and the distance, and drops a distance with no place to measure from', function () {
    $located = ScreeningResult::parse(json_encode(['score' => 70, 'lives_in' => 'Nasr City, Cairo', 'distance_km' => '18 km', 'relocation_needed' => 'No']), 'stop', []);
    $nowhere = ScreeningResult::parse(json_encode(['score' => 70, 'lives_in' => null, 'distance_km' => 0, 'relocation_needed' => 'maybe']), 'stop', []);

    expect($located->location)->toBe(['lives_in' => 'Nasr City, Cairo', 'distance_km' => 18, 'relocation_needed' => 'no'])
        ->and($located->evaluation)->not->toHaveKey('lives_in')
        ->and($nowhere->location)->toBe(['lives_in' => null, 'distance_km' => null, 'relocation_needed' => null]);
});

it('keeps the score between 0 and 100', function (int|float $given, int $kept) {
    expect(ScreeningResult::parse(json_encode(['score' => $given]), 'stop', [])->score)->toBe($kept);
})->with([
    'above' => [140, 100],
    'below' => [-5, 0],
    'decimal' => [69.6, 70],
]);

it('refuses a reply it cannot trust', function (mixed $content, ?string $finishReason) {
    ScreeningResult::parse($content, $finishReason, []);
})->with([
    'cut off' => [json_encode(['score' => 50]), 'length'],
    'blocked' => [null, 'content_filter'],
    'not JSON' => ['Here is my evaluation', 'stop'],
    'no score' => [json_encode(['summary' => 'x']), 'stop'],
])->throws(RuntimeException::class);

it('gives the screening the must-haves in order and says when the CV is a scan', function () {
    $note = CandidateScreener::note(new JobAd('Accountant', 'Close the month.'), ['SAP', 'Arabic'], '', "Q: Notice period?\nA: 1 month", true);

    expect($note)->toContain('Job title: Accountant')
        ->toContain("1. SAP\n2. Arabic")
        ->toContain('Q: Notice period?')
        ->toContain('read the attached images')
        ->not->toContain('<cv>');
});

it('gives the screening the office, the budget, the salary from the answers and the profile location', function () {
    $note = CandidateScreener::note(new JobAd('Accountant', 'Close the month.'), [], 'CV text', '', false, [
        'office' => 'CAI — Heliopolis, Cairo',
        'budget' => '8,000–12,000 EGP a month',
        'salary' => SalaryAnswers::extract([
            ['question' => 'What is your expected salary?', 'answer' => '15,000 EGP', 'from' => 'another application'],
            ['question' => 'What is your current salary?', 'answer' => '9000'],
        ]),
        'profile_location' => 'Giza, Egypt',
    ]);

    expect($note)->toContain('The office this job is in: CAI — Heliopolis, Cairo.')
        ->toContain('The salary budget for this job: 8,000–12,000 EGP a month.')
        ->toContain("The applicant's salary, read from their answers: expected 15,000 EGP (given in another application); current 9,000 EGP.")
        ->toContain('The location on their Teamtailor profile: Giza, Egypt.');

    expect(CandidateScreener::note(new JobAd('Accountant', ''), [], 'CV text', '', false))
        ->toContain('has not set the office')
        ->toContain('No salary budget is set')
        ->toContain('stated no expected or current salary')
        ->toContain('profile gives no location');
});
