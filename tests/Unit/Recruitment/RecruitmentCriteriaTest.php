<?php

use App\Http\Controllers\Admin\Recruitment\RecruitmentAiController;
use App\Models\ActivityLog;
use App\Models\Recruitment\RecruitmentJob;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Unit\Rbac\RbacTestSchema;
use Tests\Unit\Recruitment\RecruitmentTestSchema;

uses(Tests\TestCase::class);

beforeEach(function () {
    RbacTestSchema::create();
    RecruitmentTestSchema::create();
    Role::clearCache();

    Role::create([
        'slug' => 'viewer', 'name' => 'Viewer', 'surfaces' => ['noc_admin'], 'landing' => 'noc_admin',
        'is_super' => false, 'is_system' => true, 'sort_order' => 40,
    ]);
    $this->recruiter = User::create(['name' => 'Recruiter', 'email' => 'recruiter@example.com', 'password' => 'x', 'role' => 'viewer']);
    Auth::setUser($this->recruiter);

    // The branches as NOC2 keeps them: Cairo's street is the whole postal address, city included.
    DB::table('branches')->insert([
        ['id' => 60, 'name' => 'CAI', 'city' => 'Cairo', 'street' => '50 Mohamed Farid Street, Heliopolis, Cairo, Egypt'],
        ['id' => 10, 'name' => 'JED', 'city' => 'Jeddah', 'street' => null],
    ]);
});

afterEach(function () {
    RecruitmentTestSchema::drop();
    RbacTestSchema::drop();
});

function recruitmentSaveCriteria(array $input): RedirectResponse
{
    $request = Request::create('/admin/recruitment-ai/jobs/77/criteria', 'POST', $input);
    $request->setUserResolver(fn () => Auth::user());
    app()->instance('request', $request);

    return app(RecruitmentAiController::class)->updateCriteria($request, '77');
}

it('saves the office and the salary budget with the must-haves, and logs what changed', function () {
    recruitmentSaveCriteria(['must_haves' => "SAP\nArabic", 'office_branch_id' => '60', 'salary_budget_min' => '8000', 'salary_budget_max' => '12000', 'salary_currency' => 'EGP']);

    $job = RecruitmentJob::where('teamtailor_job_id', '77')->firstOrFail();

    expect($job->office_branch_id)->toBe(60)
        ->and($job->officeText())->toBe('CAI — 50 Mohamed Farid Street, Heliopolis, Cairo, Egypt')
        ->and($job->budgetText())->toBe('8,000–12,000 EGP a month')
        ->and($job->mustHaveList())->toBe(['SAP', 'Arabic'])
        ->and($job->criteria_updated_by)->toBe($this->recruiter->id);

    recruitmentSaveCriteria(['must_haves' => "SAP\nArabic", 'office_branch_id' => '10', 'salary_budget_min' => '8000', 'salary_budget_max' => '12000', 'salary_currency' => 'EGP']);

    $logs = ActivityLog::where('action', 'recruitment_ai_criteria_changed')->orderBy('id')->get();

    expect($logs)->toHaveCount(2)
        ->and($logs[1]->changes['old'])->toBe(['office' => 'CAI — 50 Mohamed Farid Street, Heliopolis, Cairo, Egypt'])
        ->and($logs[1]->changes['new'])->toBe(['office' => 'JED — Jeddah'])
        ->and($logs[1]->user_id)->toBe($this->recruiter->id);
});

it('saves and logs nothing when nothing changed', function () {
    $input = ['must_haves' => 'SAP', 'office_branch_id' => '60', 'salary_budget_max' => '12000', 'salary_currency' => 'EGP'];
    recruitmentSaveCriteria($input);

    $again = recruitmentSaveCriteria($input);

    expect($again->getSession()->get('info'))->toBe('Nothing changed.')
        ->and(ActivityLog::where('action', 'recruitment_ai_criteria_changed')->count())->toBe(1);
});

it('refuses a budget with no currency or upside down, and an office that is not a branch', function (array $input, string $field) {
    try {
        recruitmentSaveCriteria($input);
        $this->fail('Expected the input to be refused.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey($field);
    }

    expect(RecruitmentJob::count())->toBe(0);
})->with([
    'no currency' => [['salary_budget_max' => '12000'], 'salary_currency'],
    'top below bottom' => [['salary_budget_min' => '15000', 'salary_budget_max' => '12000', 'salary_currency' => 'SAR'], 'salary_budget_max'],
    'not a branch' => [['office_branch_id' => '999'], 'office_branch_id'],
]);
