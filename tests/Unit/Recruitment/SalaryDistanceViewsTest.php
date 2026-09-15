<?php

use App\Models\Recruitment\RecruitmentJob;
use App\Models\Recruitment\RecruitmentScreening;

uses(Tests\TestCase::class);

/** A screened applicant as the pages list them, never saved: the facts cast needs no database. */
function screenedWithFacts(array $facts): RecruitmentScreening
{
    return (new RecruitmentScreening)->forceFill([
        'id' => 7, 'status' => 'screened', 'score' => 82, 'fit' => 'good',
        'evaluation' => ['summary' => 'Fits.', 'strengths' => [], 'concerns' => []],
        'facts' => $facts,
    ]);
}

beforeEach(function () {
    $this->facts = [
        'salary' => [
            'expected' => ['min' => 12000, 'max' => 12000, 'currency' => 'SAR', 'text' => '12,000 SAR', 'from' => null],
            'current' => ['amount' => 9000, 'currency' => 'SAR', 'text' => '9,000', 'from' => 'another application'],
        ],
        'location' => ['lives_in' => 'Al Rawdah, Jeddah', 'distance_km' => 12, 'relocation_needed' => 'no', 'office' => 'JED'],
    ];
    $this->job = new RecruitmentJob(['salary_budget_max' => 11000, 'salary_currency' => 'SAR']);
});

it('shows an applicant\'s salary, whether it is above the budget, and the distance on the job page', function () {
    $html = view('admin.recruitment-ai._facts', ['screening' => screenedWithFacts($this->facts), 'job' => $this->job])->render();

    expect($html)->toContain('Expects <strong>12,000 SAR</strong>')
        ->toContain('Above budget')
        ->toContain('Now <strong>9,000 SAR</strong>')
        ->toContain('~12 km to JED');
});

it('shows the salary answers, the raise and the distance in the evaluation', function () {
    $html = view('admin.recruitment-ai._evaluation', ['screening' => screenedWithFacts($this->facts), 'job' => $this->job])->render();

    expect($html)->toContain('Expected salary')
        ->toContain('(+33% on current)')
        ->toContain('another application')
        ->toContain('Al Rawdah, Jeddah')
        ->toContain('Distance to JED')
        ->not->toContain('Would need to move city');
});

it('shows nothing about salary or distance for an applicant who stated neither', function () {
    $html = view('admin.recruitment-ai._facts', [
        'screening' => screenedWithFacts(['salary' => ['expected' => null, 'current' => null], 'location' => null]),
        'job' => $this->job,
    ])->render();

    expect(trim($html))->toBe('');
});

it('puts the salary numbers on top of the candidate profile, and the distance only for Recruitment AI users', function () {
    $data = [
        'salary' => $this->facts['salary'],
        'profile' => ['location' => 'Jeddah, Saudi Arabia'],
        'applications' => [['job_title' => 'Senior Accountant'], ['job_title' => 'Driver']],
        'screenedLocation' => ['lives_in' => 'Al Rawdah, Jeddah', 'distance_km' => 12, 'office' => 'JED', 'relocation_needed' => 'no', 'job_title' => 'Senior Accountant'],
    ];

    $withAi = view('admin.teamtailor.candidates._salary_distance', $data + ['canUseRecruitmentAi' => true])->render();
    $withoutAi = view('admin.teamtailor.candidates._salary_distance', ['screenedLocation' => null] + $data + ['canUseRecruitmentAi' => false])->render();

    expect($withAi)->toContain('12,000 SAR')
        ->toContain('+33% on current')
        ->toContain('9,000 SAR')
        ->toContain('Given for another application')
        ->toContain('Al Rawdah, Jeddah')
        ->toContain('~12 km')
        ->toContain('to JED · estimated by Recruitment AI')
        ->and($withoutAi)->not->toContain('Distance to office')
        ->toContain('Jeddah, Saudi Arabia')
        ->toContain('From their Teamtailor profile');
});
