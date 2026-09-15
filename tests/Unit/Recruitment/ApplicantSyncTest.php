<?php

use App\Models\Recruitment\RecruitmentJob;
use App\Models\Recruitment\RecruitmentScreening;
use App\Services\Recruitment\ApplicantSync;
use App\Services\Teamtailor\TeamtailorApiService;
use Illuminate\Support\Facades\Http;
use Tests\Unit\Recruitment\RecruitmentTestSchema;

uses(Tests\TestCase::class);

beforeEach(fn () => RecruitmentTestSchema::create());
afterEach(fn () => RecruitmentTestSchema::drop());

it('queues every applicant, with the application and stage for this job', function () {
    RecruitmentTestSchema::fakeTeamtailor(RecruitmentTestSchema::routes());
    $job = RecruitmentJob::create(['teamtailor_job_id' => '77']);

    $counts = (new ApplicantSync(new TeamtailorApiService))->syncApplicants($job);

    $mona = $job->screenings()->where('teamtailor_candidate_id', '501')->first();
    $omar = $job->screenings()->where('teamtailor_candidate_id', '502')->first();

    expect($counts)->toBe(['applicants' => 2, 'new' => 2, 'rescreen' => 0])
        ->and($job->fresh()->applicant_count)->toBe(2)
        ->and($mona->status)->toBe(RecruitmentScreening::STATUS_PENDING)
        ->and($mona->candidate_name)->toBe('Mona Ali')
        ->and($mona->candidate_location)->toBe('Jeddah, Saudi Arabia')
        ->and($mona->teamtailor_application_id)->toBe('9002')
        ->and($mona->stage)->toBe('Interview')
        ->and($mona->rejected)->toBeFalse()
        ->and($mona->applied_at->format('Y-m-d'))->toBe('2026-09-03')
        ->and($omar->teamtailor_application_id)->toBe('9003')
        ->and($omar->stage)->toBe('Screening')
        ->and($omar->rejected)->toBeTrue();
});

it('queues again only the applicant whose CV changed in Teamtailor', function () {
    // One fake reading the date by reference: a second Http::fake() would be
    // appended behind the first, which answers every path.
    $resumeUpdatedAt = '2026-09-01T10:00:00Z';
    RecruitmentTestSchema::fakeTeamtailor([
        '/v1/jobs/77/stages' => RecruitmentTestSchema::routes()['/v1/jobs/77/stages'],
        '/v1/jobs/77/candidates' => function () use (&$resumeUpdatedAt) {
            return Http::response(RecruitmentTestSchema::routes($resumeUpdatedAt)['/v1/jobs/77/candidates'], 200);
        },
    ]);

    $job = RecruitmentJob::create(['teamtailor_job_id' => '77']);
    $sync = new ApplicantSync(new TeamtailorApiService);
    $sync->syncApplicants($job);
    $job->screenings()->update(['status' => RecruitmentScreening::STATUS_SCREENED, 'score' => 70]);

    $resumeUpdatedAt = '2026-09-10T09:30:00Z';
    $counts = $sync->syncApplicants($job);

    expect($counts)->toBe(['applicants' => 2, 'new' => 0, 'rescreen' => 1])
        ->and($job->screenings()->where('teamtailor_candidate_id', '501')->value('status'))->toBe(RecruitmentScreening::STATUS_PENDING)
        ->and($job->screenings()->where('teamtailor_candidate_id', '502')->value('status'))->toBe(RecruitmentScreening::STATUS_SCREENED);
});

it('reads an applicant\'s CV link and application answers', function () {
    RecruitmentTestSchema::fakeTeamtailor(RecruitmentTestSchema::routes());

    $candidate = (new ApplicantSync(new TeamtailorApiService))->fetchCandidate('501');

    expect($candidate['resume'])->toBe('https://s3.example/501-fresh.pdf')
        ->and($candidate['answers'])->toBe("Q: Notice period?\nA: 1 month\n\nA: Yes");

    // Only the nested include puts the question's id on each answer (see fetchCandidate()).
    Http::assertSent(fn ($request) => str_contains(urldecode($request->url()), 'include=answers,answers.question'));
});

it('still reads the CV when Teamtailor rejects the answers include', function () {
    config()->set('teamtailor.base_url', 'https://api.teamtailor.com');
    config()->set('teamtailor.api_key', 'test-key');

    Http::fake(function ($request) {
        return str_contains($request->url(), 'include=')
            ? Http::response(['errors' => [['title' => 'Invalid include']]], 400)
            : Http::response(['data' => ['id' => '503', 'attributes' => ['original-resume' => 'https://s3.example/503.pdf']]], 200);
    });

    $candidate = (new ApplicantSync(new TeamtailorApiService))->fetchCandidate('503');

    expect($candidate)->toBe(['resume' => 'https://s3.example/503.pdf', 'answers' => '']);
});
