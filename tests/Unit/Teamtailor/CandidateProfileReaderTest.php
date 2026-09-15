<?php

use App\Services\Teamtailor\CandidateProfileReader;
use Illuminate\Support\Facades\Http;
use Tests\Unit\Recruitment\RecruitmentTestSchema;

// Boots the app for config, cache and the Http facade; no database.
uses(Tests\TestCase::class);

function ttProfileAnswer(string $id, array $attributes, string $questionId, string $pickedId): array
{
    return ['id' => $id, 'type' => 'answers', 'attributes' => $attributes, 'relationships' => [
        'question' => ['data' => ['type' => 'questions', 'id' => $questionId]],
        'picked-question' => ['data' => ['type' => 'picked-questions', 'id' => $pickedId]],
    ]];
}

function ttActivity(string $id, string $code, array $data, ?string $jobId, ?string $userId, string $at): array
{
    return ['id' => $id, 'type' => 'activities', 'attributes' => ['code' => $code, 'data' => json_encode((object) $data), 'created-at' => $at], 'relationships' => [
        'job' => ['data' => $jobId !== null ? ['type' => 'jobs', 'id' => $jobId] : null],
        'user' => ['data' => $userId !== null ? ['type' => 'users', 'id' => $userId] : null],
    ]];
}

/** Mona applied to Senior Accountant (job 77, at Interview) and Driver (job 88, rejected). */
function ttProfileRoutes(): array
{
    return [
        '/v1/candidates/700' => [
            'data' => ['id' => '700', 'type' => 'candidates', 'attributes' => [
                'first-name' => 'Mona', 'last-name' => 'Ali', 'email' => 'mona@example.com', 'phone' => '+966500000000',
                'city' => 'Jeddah', 'country' => 'Saudi Arabia', 'pitch' => 'Accountant with 8 years in retail.',
                'resume-summary' => 'Eight years of accounting.', 'tags' => ['Finance', ''],
                'linkedin-url' => 'javascript:alert(1)', 'resume' => 'https://s3.example/cv.pdf', 'original-resume' => 'https://s3.example/cv.docx',
                'referring-site' => 'LinkedIn', 'connected' => true, 'consent-future-jobs-at' => '2027-09-01T00:00:00Z',
                'created-at' => '2026-08-01T10:00:00Z', 'updated-at' => '2026-09-10T10:00:00Z',
            ], 'relationships' => [
                'job-applications' => ['data' => [['type' => 'job-applications', 'id' => 'ja2'], ['type' => 'job-applications', 'id' => 'ja1']]],
                'answers' => ['data' => [['type' => 'answers', 'id' => 'an1'], ['type' => 'answers', 'id' => 'an2'], ['type' => 'answers', 'id' => 'an3'], ['type' => 'answers', 'id' => 'an4']]],
                'uploads' => ['data' => [['type' => 'uploads', 'id' => 'up1']]],
            ]],
            'included' => [
                ['id' => 'ja1', 'type' => 'job-applications', 'attributes' => [
                    'created-at' => '2026-09-03T08:00:00Z', 'cover-letter' => 'Dear team', 'referring-site' => 'LinkedIn',
                    'sourced' => false, 'rejected-at' => null, 'changed-stage-at' => '2026-09-06T08:00:00Z',
                ], 'relationships' => [
                    'job' => ['data' => ['type' => 'jobs', 'id' => '77']],
                    'stage' => ['data' => ['type' => 'stages', 'id' => 'st1']],
                    'reject-reason' => ['data' => null],
                ]],
                ['id' => 'ja2', 'type' => 'job-applications', 'attributes' => [
                    'created-at' => '2026-08-20T08:00:00Z', 'rejected-at' => '2026-08-25T08:00:00Z', 'sourced' => true,
                ], 'relationships' => [
                    'job' => ['data' => ['type' => 'jobs', 'id' => '88']],
                    'stage' => ['data' => ['type' => 'stages', 'id' => 'st2']],
                    'reject-reason' => ['data' => ['type' => 'reject-reasons', 'id' => 'rr1']],
                ]],
                ['id' => '77', 'type' => 'jobs', 'attributes' => ['title' => 'Senior Accountant']],
                ['id' => '88', 'type' => 'jobs', 'attributes' => ['title' => 'Driver']],
                ['id' => 'st1', 'type' => 'stages', 'attributes' => ['name' => 'Interview']],
                ['id' => 'st2', 'type' => 'stages', 'attributes' => ['name' => 'Screening']],
                ['id' => 'rr1', 'type' => 'reject-reasons', 'attributes' => ['reason' => 'Not enough experience', 'rejected-by-company' => true]],
                ['id' => 'q1', 'type' => 'questions', 'attributes' => ['title' => 'Notice period?']],
                ['id' => 'q2', 'type' => 'questions', 'attributes' => ['title' => 'Do you have a Saudi driving licence?']],
                ['id' => 'q3', 'type' => 'questions', 'attributes' => ['title' => 'Salary expectation', 'unit' => 'SAR']],
                ['id' => 'q4', 'type' => 'questions', 'attributes' => ['title' => 'What is your current salary?']],
                ttProfileAnswer('an1', ['question-type' => 'text', 'text' => '1 month', 'answer' => '1 month'], 'q1', 'pq77a'),
                ttProfileAnswer('an2', ['question-type' => 'boolean', 'boolean' => true, 'answer' => 'true'], 'q2', 'pq88a'),
                ttProfileAnswer('an3', ['question-type' => 'number', 'number' => 9000, 'answer' => 9000], 'q3', 'pq-retired'),
                ttProfileAnswer('an4', ['question-type' => 'text', 'text' => '7,500', 'answer' => '7,500'], 'q4', 'pq77b'),
                ['id' => 'up1', 'type' => 'uploads', 'attributes' => [
                    'file-name' => 'certificate.pdf', 'url' => 'https://s3.example/certificate.pdf', 'internal' => false, 'created-at' => '2026-09-04T08:00:00Z',
                ]],
            ],
        ],
        '/v1/jobs/77/picked-questions' => ['data' => [['id' => 'pq77a', 'type' => 'picked-questions'], ['id' => 'pq77b', 'type' => 'picked-questions']]],
        '/v1/jobs/88/picked-questions' => ['data' => [['id' => 'pq88a', 'type' => 'picked-questions']]],
        '/v1/jobs/77/stages' => ['data' => [
            ['id' => 'st0', 'type' => 'stages', 'attributes' => ['name' => 'Applied']],
            ['id' => 'st1', 'type' => 'stages', 'attributes' => ['name' => 'Interview']],
        ]],
        '/v1/jobs/88/stages' => ['data' => [['id' => 'st2', 'type' => 'stages', 'attributes' => ['name' => 'Screening']]]],
        '/v1/candidates/700/activities' => [
            'data' => [
                ttActivity('ac1', 'stage', ['from_trigger' => false, 'from' => 'st0', 'to' => 'st1'], '77', 'u5', '2026-09-06T08:00:00Z'),
                ttActivity('ac2', 'rejected', ['stage_id' => 'st2'], '88', 'u5', '2026-08-25T08:00:00Z'),
                ttActivity('ac3', 'message', [
                    'subject' => 'Interview invitation', 'from' => 'hr@samirgroup.com', 'to' => ['mona@example.com'],
                    'body' => '<p>Hello <b>Mona</b></p>', 'from_trigger' => true,
                ], '77', null, '2026-09-05T08:00:00Z'),
                ttActivity('ac4', 'meeting_event_candidate_invited', [
                    'meeting_event_summary' => 'Interview', 'meeting_event_starts_at' => '2026-09-16T07:00:00Z',
                    'meeting_event_ends_at' => '2026-09-16T07:30:00Z', 'meeting_event_tzid' => 'Asia/Riyadh',
                ], '77', 'u5', '2026-09-05T09:00:00Z'),
                ttActivity('ac5', 'some_new_code', ['note' => 'short', 'count' => 3, 'from_trigger' => false], null, null, '2026-09-01T08:00:00Z'),
                ttActivity('ac6', 'created', [], '77', null, '2026-09-03T08:00:00Z'),
            ],
            'included' => [
                ['id' => 'u5', 'type' => 'users', 'attributes' => ['name' => 'Rana Recruiter']],
                ['id' => '77', 'type' => 'jobs', 'attributes' => ['title' => 'Senior Accountant']],
                ['id' => '88', 'type' => 'jobs', 'attributes' => ['title' => 'Driver']],
            ],
            'meta' => ['record-count' => 6, 'page-count' => 1],
        ],
    ];
}

it('reads a candidate\'s details, each application with its own questions and answers, and attachments', function () {
    RecruitmentTestSchema::fakeTeamtailor(ttProfileRoutes());

    $data = app(CandidateProfileReader::class)->read('700');

    expect($data['profile'])->toMatchArray([
        'name' => 'Mona Ali', 'location' => 'Jeddah, Saudi Arabia', 'phone' => '+966500000000',
        'linkedin' => null, 'resume' => 'https://s3.example/cv.pdf', 'tags' => ['Finance'],
        'pitch' => 'Accountant with 8 years in retail.', 'resume_summary' => 'Eight years of accounting.',
        'referring_site' => 'LinkedIn', 'connected' => true,
    ])
        ->and($data['profile']['consent_future_jobs_at']->toDateString())->toBe('2027-09-01')
        ->and(array_column($data['applications'], 'job_title'))->toBe(['Senior Accountant', 'Driver'])
        ->and($data['applications'][0])->toMatchArray([
            'stage' => 'Interview', 'rejected' => false, 'cover_letter' => 'Dear team', 'referring_site' => 'LinkedIn',
            'answers' => [['question' => 'Notice period?', 'answer' => '1 month'], ['question' => 'What is your current salary?', 'answer' => '7,500']],
        ])
        ->and($data['applications'][1])->toMatchArray([
            'stage' => 'Screening', 'rejected' => true, 'reject_reason' => 'Not enough experience', 'rejected_by_company' => true, 'sourced' => true,
            'answers' => [['question' => 'Do you have a Saudi driving licence?', 'answer' => 'Yes']],
        ])
        ->and($data['other_answers'])->toBe([['question' => 'Salary expectation', 'answer' => '9000 SAR']])
        ->and($data['uploads'])->toHaveCount(1)
        ->and($data['uploads'][0])->toMatchArray(['name' => 'certificate.pdf', 'url' => 'https://s3.example/certificate.pdf', 'internal' => false]);
});

it('puts the salary the answers state on top, the newest application first', function () {
    RecruitmentTestSchema::fakeTeamtailor(ttProfileRoutes());

    $data = app(CandidateProfileReader::class)->read('700');

    expect($data['salary']['current'])->toMatchArray(['amount' => 7500, 'currency' => 'SAR', 'text' => '7,500', 'from' => 'Senior Accountant'])
        // Asked by a question since taken off its job, so tied to no application.
        ->and($data['salary']['expected'])->toMatchArray(['min' => 9000, 'max' => 9000, 'currency' => 'SAR', 'from' => null])
        ->and($data['applications'][0]['salary']['current']['amount'])->toBe(7500)
        ->and($data['applications'][1]['salary'])->toBe(['expected' => null, 'current' => null]);
});

it('turns the activity log into readable lines with the job, the user and stage names', function () {
    RecruitmentTestSchema::fakeTeamtailor(ttProfileRoutes());

    $data = app(CandidateProfileReader::class)->read('700');
    $byCode = collect($data['activities'])->keyBy('code');

    expect($data['activities_total'])->toBe(6)
        ->and($data['activity_error'])->toBeNull()
        ->and($byCode['stage'])->toMatchArray(['label' => 'Moved from Applied to Interview', 'job' => 'Senior Accountant', 'user' => 'Rana Recruiter', 'automatic' => false])
        ->and($byCode['rejected'])->toMatchArray(['label' => 'Rejected at Screening', 'job' => 'Driver', 'user' => 'Rana Recruiter'])
        ->and($byCode['message'])->toMatchArray([
            'label' => 'Message: Interview invitation', 'automatic' => true, 'user' => null, 'body' => 'Hello Mona',
            'details' => ['From: hr@samirgroup.com', 'To: mona@example.com'],
        ])
        ->and($byCode['meeting_event_candidate_invited'])->toMatchArray([
            'label' => 'Invited to a meeting: Interview', 'details' => ['When: 16 Sep 2026, 10:00-10:30 (Asia/Riyadh)'],
        ])
        ->and($byCode['some_new_code'])->toMatchArray(['label' => 'Some new code', 'details' => ['Note: short', 'Count: 3']])
        ->and($byCode['created']['label'])->toBe('Applied');
});

it('uses fewer includes when Teamtailor refuses one, and still shows the profile when the log fails', function () {
    $routes = ttProfileRoutes();
    $candidate = $routes['/v1/candidates/700'];
    $routes['/v1/candidates/700'] = fn ($request) => str_contains(urldecode($request->url()), 'reject-reason')
        ? Http::response(['errors' => [['title' => 'Invalid include']]], 400)
        : Http::response($candidate, 200);
    $routes['/v1/candidates/700/activities'] = fn () => Http::response(['errors' => [['title' => 'Server error']]], 500);
    RecruitmentTestSchema::fakeTeamtailor($routes);

    $data = app(CandidateProfileReader::class)->read('700');

    expect($data['profile']['name'])->toBe('Mona Ali')
        ->and($data['applications'])->toHaveCount(2)
        ->and($data['activities'])->toBe([])
        ->and($data['activity_error'])->toContain('500');
});

it('shows a recruiter\'s note as plain text and names sourcing', function () {
    $lookups = CandidateProfileReader::index([['id' => 'u5', 'type' => 'users', 'attributes' => ['name' => 'Rana Recruiter']]]);

    $note = CandidateProfileReader::activity(ttActivity('ac7', 'note', ['note' => '<p>Strong on <b>IFRS</b>; call back Sunday</p>', 'rich_text_enabled' => true], '77', 'u5', '2026-09-07T08:00:00Z'), $lookups);
    $sourced = CandidateProfileReader::activity(ttActivity('ac8', 'sourced', [], '77', 'u5', '2026-08-01T08:00:00Z'), $lookups);

    expect($note)->toMatchArray(['label' => 'Note', 'user' => 'Rana Recruiter', 'body' => 'Strong on IFRS; call back Sunday', 'details' => []])
        ->and($sourced)->toMatchArray(['label' => 'Added as a sourced candidate', 'user' => 'Rana Recruiter', 'body' => null]);
});
