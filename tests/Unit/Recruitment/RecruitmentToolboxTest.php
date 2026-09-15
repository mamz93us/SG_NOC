<?php

use App\Models\Recruitment\RecruitmentJob;
use App\Models\Recruitment\RecruitmentScreening;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Models\UserPermission;
use App\Services\Ai\AssistantToolbox;
use App\Services\Ai\KnowledgeRetriever;
use App\Services\Recruitment\RecruitmentToolbox;
use App\Services\Ticketing\TicketRequestService;
use Tests\Unit\Rbac\RbacTestSchema;
use Tests\Unit\Recruitment\RecruitmentTestSchema;

uses(Tests\TestCase::class);

beforeEach(function () {
    RbacTestSchema::create();
    RecruitmentTestSchema::create();

    Role::clearCache();
    RolePermission::clearCache();
    User::clearOverrideCache();

    Role::create([
        'slug' => 'viewer', 'name' => 'Viewer', 'surfaces' => ['noc_admin'], 'landing' => 'noc_admin',
        'is_super' => false, 'is_system' => true, 'sort_order' => 40,
    ]);

    $this->recruiter = User::create(['name' => 'Recruiter', 'email' => 'recruiter@example.com', 'password' => 'x', 'role' => 'viewer']);
    $this->outsider = User::create(['name' => 'Outsider', 'email' => 'outsider@example.com', 'password' => 'x', 'role' => 'viewer']);
    UserPermission::create(['user_id' => $this->recruiter->id, 'permission' => RecruitmentToolbox::PERMISSION, 'effect' => 'grant']);
    User::clearOverrideCache();

    $this->job = RecruitmentJob::create([
        'teamtailor_job_id' => '77', 'title' => 'Senior Accountant', 'screening_enabled' => true,
        'must_haves' => "SAP\nArabic", 'applicant_count' => 5,
    ]);
    $this->otherJob = RecruitmentJob::create(['teamtailor_job_id' => '88', 'title' => 'Driver', 'screening_enabled' => false]);

    $candidateId = 1000;
    $screen = function (RecruitmentJob $job, string $name, ?int $score, array $extra = []) use (&$candidateId) {
        return RecruitmentScreening::create(array_merge([
            'recruitment_job_id' => $job->id,
            'teamtailor_candidate_id' => (string) $candidateId++,
            'candidate_name' => $name,
            'candidate_email' => strtolower(str_replace(' ', '.', $name)).'@example.com',
            'status' => $score === null ? RecruitmentScreening::STATUS_PENDING : RecruitmentScreening::STATUS_SCREENED,
            'score' => $score,
            'fit' => $score === null ? null : RecruitmentScreening::fitFor($score),
            'must_haves_met' => 2,
            'must_haves_total' => 2,
            'evaluation' => [
                'summary' => "{$name} summary",
                'must_haves' => [['requirement' => 'SAP', 'met' => 'yes', 'evidence' => 'SAP FICO']],
                'strengths' => ['Month-end close'],
                'concerns' => [],
                'skills' => ['SAP'],
            ],
            'cv_text' => "{$name} worked with SAP FICO and IFRS in Jeddah.",
            'applied_at' => now()->subDays(3),
        ], $extra));
    };

    $this->mona = $screen($this->job, 'Mona Ali', 91);
    $this->omar = $screen($this->job, 'Omar Hassan', 76, ['cv_text' => 'Omar: Oracle and IFRS, Arabic native']);
    $this->sara = $screen($this->job, 'Sara Nabil', 95, ['rejected' => true]);
    $this->waiting = $screen($this->job, 'Ahmed Waiting', null);
    $this->driver = $screen($this->otherJob, 'Mona Driver', 60);
});

afterEach(function () {
    RecruitmentTestSchema::drop();
    RbacTestSchema::drop();
});

it('offers and answers nothing to someone without Recruitment AI', function () {
    $toolbox = new RecruitmentToolbox($this->outsider);

    expect($toolbox->enabled())->toBeFalse()
        ->and($toolbox->definitions())->toBe([])
        ->and($toolbox->call('get_job_shortlist', ['job' => '77']))->toHaveKey('error');
});

it('lists the jobs set up, with their screening progress', function () {
    $jobs = (new RecruitmentToolbox($this->recruiter))->call('list_recruitment_jobs', [])['jobs'];

    expect($jobs[0])->toMatchArray(['job_id' => '77', 'ai_screening' => 'on', 'screened' => 3, 'waiting_to_be_screened' => 1, 'best_score' => 95])
        ->and($jobs[1])->toMatchArray(['job_id' => '88', 'ai_screening' => 'off']);
});

it('ranks a job\'s screened applicants, leaving out rejected ones unless asked', function () {
    $toolbox = new RecruitmentToolbox($this->recruiter);

    $shortlist = $toolbox->call('get_job_shortlist', ['job' => 'accountant']);

    expect(array_column($shortlist['candidates'], 'name'))->toBe(['Mona Ali', 'Omar Hassan'])
        ->and($shortlist['candidates'][0])->toMatchArray(['rank' => 1, 'candidate_ref' => 'C'.$this->mona->id, 'score' => 91, 'fit' => 'Strong match'])
        ->and($shortlist['must_haves'])->toBe(['SAP', 'Arabic'])
        ->and(implode(' ', $shortlist['notes']))->toContain('1 applicants are still waiting');

    $withRejected = $toolbox->call('get_job_shortlist', ['job' => '77', 'include_rejected' => true, 'limit' => 1]);

    expect(array_column($withRejected['candidates'], 'name'))->toBe(['Sara Nabil']);
});

it('gives one applicant\'s CV and evaluation by reference or by name, only within the job', function () {
    $toolbox = new RecruitmentToolbox($this->recruiter, $this->job);

    $byRef = $toolbox->call('get_candidate_details', ['candidate' => 'C'.$this->omar->id]);

    expect($byRef['candidate']['name'])->toBe('Omar Hassan')
        ->and($byRef['cv_text'])->toContain('Oracle')
        ->and($byRef['evaluation']['summary'])->toBe('Omar Hassan summary')
        ->and($toolbox->call('get_candidate_details', ['candidate' => 'sara'])['candidate']['name'])->toBe('Sara Nabil')
        ->and($toolbox->call('get_candidate_details', ['candidate' => 'a']))->toHaveKey('matches')
        // Another job's applicant is not reachable from this job, even by id.
        ->and($toolbox->call('get_candidate_details', ['candidate' => 'C'.$this->driver->id]))->toHaveKey('error');
});

it('finds the applicants whose CV mentions every word asked for', function () {
    $toolbox = new RecruitmentToolbox($this->recruiter);

    expect(array_column($toolbox->call('search_candidates', ['job' => '77', 'query' => 'IFRS arabic'])['matches'], 'name'))->toBe(['Omar Hassan'])
        ->and(array_column($toolbox->call('search_candidates', ['job' => '77', 'query' => 'IFRS'])['matches'], 'name'))->toBe(['Sara Nabil', 'Mona Ali', 'Omar Hassan']);
});

it('adds the recruitment tools to the Samir AI Assistant only for people who may use them', function () {
    $toolbox = fn (User $user) => new AssistantToolbox($user, null, null, app(KnowledgeRetriever::class), app(TicketRequestService::class));
    $names = fn (AssistantToolbox $box) => array_map(fn (array $tool) => $tool['function']['name'], $box->definitions());

    expect($names($toolbox($this->recruiter)))->toContain('get_job_shortlist')
        ->and($names($toolbox($this->outsider)))->not->toContain('get_job_shortlist')
        ->and($toolbox($this->recruiter)->call('get_job_shortlist', ['job' => '77']))->toHaveKey('candidates')
        ->and($toolbox($this->outsider)->call('get_job_shortlist', ['job' => '77']))->toHaveKey('error')
        ->and($toolbox($this->recruiter)->recruitmentNote())->toContain('Recruitment AI')
        ->and($toolbox($this->outsider)->recruitmentNote())->toBe('');
});
