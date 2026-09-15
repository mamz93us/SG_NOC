<?php

use App\Models\Recruitment\RecruitmentJob;
use App\Services\Recruitment\JobAd;

it('turns a Teamtailor job ad into readable text', function () {
    $ad = JobAd::fromTeamtailor(['attributes' => [
        'title' => 'Senior Accountant',
        'pitch' => 'Join <b>Samir Group</b>',
        'body' => '<p>Responsibilities:</p><ul><li>Close the month</li><li>IFRS &amp; VAT</li></ul><p>&nbsp;</p><p>Apply now</p>',
    ]]);

    expect($ad->title)->toBe('Senior Accountant')
        ->and($ad->text)->toBe("Join Samir Group\n\nResponsibilities:\n- Close the month\n- IFRS & VAT\n\nApply now");
});

it('falls back to the internal name and copes with an empty ad', function () {
    $ad = JobAd::fromTeamtailor(['attributes' => ['internal-name' => 'ACC-2026-07']]);

    expect($ad->title)->toBe('ACC-2026-07')
        ->and($ad->text)->toBe('')
        ->and($ad->forPrompt())->toContain('(the ad has no description)');
});

it('reads must-haves one per line, without bullets, numbering or repeats', function () {
    expect(RecruitmentJob::parseMustHaves("- SAP FICO\n\n2. 5+ years accounting\n• sap fico\n  * Arabic  "))
        ->toBe(['SAP FICO', '5+ years accounting', 'Arabic']);
});

it('keeps at most the maximum number of must-haves', function () {
    $lines = implode("\n", array_map(fn (int $n) => "Requirement {$n}", range(1, 20)));

    expect(RecruitmentJob::parseMustHaves($lines))->toHaveCount(RecruitmentJob::MAX_MUST_HAVES);
});

it('changes the criteria hash only when the ad, the must-haves or the instructions change', function () {
    $hash = RecruitmentJob::criteriaHash('ad', ['SAP'], 'v1');

    expect(RecruitmentJob::criteriaHash('ad', ['SAP'], 'v1'))->toBe($hash)
        ->and(RecruitmentJob::criteriaHash('ad, edited', ['SAP'], 'v1'))->not->toBe($hash)
        ->and(RecruitmentJob::criteriaHash('ad', ['SAP', 'Arabic'], 'v1'))->not->toBe($hash)
        ->and(RecruitmentJob::criteriaHash('ad', ['SAP'], 'v2'))->not->toBe($hash);
});
