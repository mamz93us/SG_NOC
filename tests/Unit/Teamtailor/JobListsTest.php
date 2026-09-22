<?php

use App\Http\Controllers\Admin\Recruitment\RecruitmentAiController;
use App\Http\Controllers\Admin\Teamtailor\JobController;
use App\Models\Recruitment\RecruitmentJob;
use App\Services\Teamtailor\TeamtailorApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\Unit\Recruitment\RecruitmentTestSchema;

uses(Tests\TestCase::class);

beforeEach(fn () => RecruitmentTestSchema::create());
afterEach(fn () => RecruitmentTestSchema::drop());

/** One page of /v1/jobs as Teamtailor sends it: a published job's `status` reads `open`. */
function teamtailorJobList(): array
{
    $job = fn (string $id, string $title, string $status, string $human) => [
        'id' => $id,
        'type' => 'jobs',
        'attributes' => ['title' => $title, 'status' => $status, 'human-status' => $human, 'created-at' => '2026-09-01T10:00:00+03:00'],
    ];

    return [
        'data' => [
            $job('1', 'Driver', 'open', 'published'),
            $job('2', 'Accountant', 'unlisted', 'unlisted'),
            $job('3', 'Storekeeper', 'unlisted', 'unlisted'),
            $job('4', 'Cashier', 'archived', 'archived'),
        ],
        'meta' => ['record-count' => 4, 'page-count' => 1],
    ];
}

it('lists closed jobs on the Jobs page, or the one status asked for', function (?string $asked, string $sent) {
    RecruitmentTestSchema::fakeTeamtailor(['/v1/jobs' => teamtailorJobList()]);

    $data = app(JobController::class)
        ->index(Request::create('/admin/jobs', 'GET', array_filter(['status' => $asked])), new TeamtailorApiService)
        ->getData();

    Http::assertSent(fn ($request) => $request['filter[status]'] === $sent);

    expect($data['status'])->toBe($sent)
        ->and($data['error'])->toBeNull()
        ->and($data['jobs']->pluck('status')->all())->toBe(['published', 'unlisted', 'unlisted', 'archived']);
})->with([
    'nothing asked' => [null, 'all'],
    'archived' => ['archived', 'archived'],
    'a status Teamtailor refuses' => ['closed', 'all'],
]);

it('lists closed jobs in Recruitment AI with a count per status, and shows one status', function () {
    RecruitmentTestSchema::fakeTeamtailor(['/v1/jobs' => teamtailorJobList()]);
    RecruitmentJob::create(['teamtailor_job_id' => '2', 'title' => 'Accountant', 'job_status' => 'unlisted']);
    RecruitmentJob::create(['teamtailor_job_id' => '9', 'title' => 'Deleted in Teamtailor', 'job_status' => 'open']);

    $controller = app(RecruitmentAiController::class);
    $all = $controller->index(Request::create('/admin/recruitment-ai'), new TeamtailorApiService)->getData();
    $unlisted = $controller->index(Request::create('/admin/recruitment-ai', 'GET', ['status' => 'unlisted']), new TeamtailorApiService)->getData();

    Http::assertSent(fn ($request) => $request['filter[status]'] === 'all');

    expect($all['counts'])->toBe(['all' => 5, 'published' => 1, 'unlisted' => 2, 'archived' => 1, 'not listed' => 1])
        ->and($all['jobs']->firstWhere('id', '9')['status'])->toBe('not listed')
        ->and($unlisted['status'])->toBe('unlisted')
        ->and($unlisted['jobs']->pluck('id')->all())->toBe(['2', '3'])
        ->and($unlisted['counts'])->toBe($all['counts']);
});

it('keeps the last known status of a set-up job when Teamtailor cannot be read', function () {
    RecruitmentTestSchema::fakeTeamtailor([]);
    RecruitmentJob::create(['teamtailor_job_id' => '9', 'title' => 'Accountant', 'job_status' => 'open']);

    $data = app(RecruitmentAiController::class)->index(Request::create('/admin/recruitment-ai'), new TeamtailorApiService)->getData();

    expect($data['error'])->not->toBeNull()
        ->and($data['jobs']->pluck('status')->all())->toBe(['published']);
});

it('shows a job\'s status as a badge in Teamtailor\'s words', function () {
    $badge = fn (?string $status) => view('admin.teamtailor.jobs._status', ['jobStatus' => $status])->render();

    expect($badge('open'))->toContain('>Published</span>')->toContain('bg-success-subtle')
        ->and($badge('archived'))->toContain('>Archived</span>')
        ->and($badge('not listed'))->toContain('>Not listed</span>')
        ->and($badge(null))->toContain('—')->not->toContain('badge');
});

it('offers every status as a pill, with a count only where the page has one', function () {
    $pills = fn (array $data) => view('admin.teamtailor.jobs._status_filter', ['route' => 'admin.jobs.index', 'status' => 'archived'] + $data)->render();

    $without = $pills([]);
    $with = $pills(['counts' => ['all' => 57, 'archived' => 8]]);

    expect($without)->toContain('href="'.route('admin.jobs.index').'"')
        ->toContain('href="'.route('admin.jobs.index', ['status' => 'unlisted']).'"')
        ->toMatch('/nav-link py-1 active"\s+href="[^"]*status=archived"/')
        ->toContain('Closed jobs are')
        ->not->toContain('badge')
        ->and($with)->toContain('>57</span>')
        ->toContain('>8</span>')
        ->toContain('>0</span>');
});
