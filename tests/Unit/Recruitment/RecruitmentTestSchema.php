<?php

namespace Tests\Unit\Recruitment;

use Closure;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * Recruitment AI's tables, and a fake Teamtailor for the tests that sync.
 *
 * The recruitment tables come from the real migration, so its SQL is exercised
 * too; ai_conversations, which it alters, is built by hand first. Not
 * RefreshDatabase — see tests/Unit/Rbac/RbacTestSchema.php for why.
 */
class RecruitmentTestSchema
{
    public static function create(): void
    {
        Schema::create('ai_conversations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->string('locale', 5)->nullable();
            $table->string('title')->nullable();
            $table->unsignedInteger('message_count')->default(0);
            $table->unsignedBigInteger('total_tokens')->default(0);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
        });

        (require base_path('database/migrations/2026_09_15_100001_create_recruitment_ai_tables.php'))->up();
    }

    public static function drop(): void
    {
        foreach (['recruitment_screenings', 'recruitment_jobs', 'ai_conversations'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    /**
     * Answers Teamtailor API paths from $routes (path => JSON body, or a Closure
     * returning a response); anything else is a 404.
     *
     * @param  array<string, array<string,mixed>|Closure>  $routes
     */
    public static function fakeTeamtailor(array $routes): void
    {
        config()->set('teamtailor.base_url', 'https://api.teamtailor.com');
        config()->set('teamtailor.api_key', 'test-key');
        config()->set('teamtailor.api_version', '20240904');

        Http::fake(function (Request $request) use ($routes) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if (array_key_exists($path, $routes)) {
                $body = $routes[$path];

                return $body instanceof Closure ? $body($request) : Http::response($body, 200);
            }

            return Http::response(['errors' => [['title' => 'Not found']]], 404);
        });
    }

    /**
     * Job 77 with two applicants: Mona (a CV, applied to job 88 as well) and
     * Omar (no CV, rejected).
     *
     * @return array<string, array<string,mixed>>
     */
    public static function routes(string $monaResumeUpdatedAt = '2026-09-01T10:00:00Z'): array
    {
        return [
            '/v1/jobs/77' => ['data' => ['id' => '77', 'type' => 'jobs', 'attributes' => [
                'title' => 'Senior Accountant',
                'status' => 'open',
                'body' => '<p>IFRS, VAT and SAP.</p>',
            ]]],
            '/v1/jobs/77/stages' => ['data' => [
                ['id' => 's1', 'type' => 'stages', 'attributes' => ['name' => 'Interview']],
                ['id' => 's2', 'type' => 'stages', 'attributes' => ['name' => 'Screening']],
            ]],
            '/v1/jobs/77/candidates' => [
                'data' => [
                    ['id' => '501', 'type' => 'candidates', 'attributes' => [
                        'first-name' => 'Mona', 'last-name' => 'Ali', 'email' => 'mona@example.com',
                        'city' => 'Jeddah', 'country' => 'Saudi Arabia',
                        'linkedin-url' => 'https://www.linkedin.com/in/mona',
                        'resume' => 'https://s3.example/501.pdf',
                        'resume-updated-at' => $monaResumeUpdatedAt,
                    ], 'relationships' => ['job-applications' => ['data' => [
                        ['id' => '9001', 'type' => 'job-applications'],
                        ['id' => '9002', 'type' => 'job-applications'],
                    ]]]],
                    ['id' => '502', 'type' => 'candidates', 'attributes' => [
                        'first-name' => 'Omar', 'last-name' => 'Hassan', 'email' => 'omar@example.com',
                        'resume' => null, 'resume-updated-at' => null,
                    ], 'relationships' => ['job-applications' => ['data' => [
                        ['id' => '9003', 'type' => 'job-applications'],
                    ]]]],
                ],
                'included' => [
                    ['id' => '9001', 'type' => 'job-applications', 'attributes' => ['created-at' => '2026-09-02T08:00:00Z', 'rejected-at' => null],
                        'relationships' => ['job' => ['data' => ['id' => '88']], 'stage' => ['data' => ['id' => 's9']]]],
                    ['id' => '9002', 'type' => 'job-applications', 'attributes' => ['created-at' => '2026-09-03T08:00:00Z', 'rejected-at' => null],
                        'relationships' => ['job' => ['data' => ['id' => '77']], 'stage' => ['data' => ['id' => 's1']]]],
                    ['id' => '9003', 'type' => 'job-applications', 'attributes' => ['created-at' => '2026-09-04T08:00:00Z', 'rejected-at' => '2026-09-05T08:00:00Z'],
                        'relationships' => ['job' => ['data' => ['id' => '77']], 'stage' => ['data' => ['id' => 's2']]]],
                ],
                'meta' => ['record-count' => 2, 'page-count' => 1],
            ],
            '/v1/candidates/501' => [
                'data' => ['id' => '501', 'type' => 'candidates', 'attributes' => ['resume' => 'https://s3.example/501-fresh.pdf']],
                'included' => [
                    ['id' => 'q1', 'type' => 'questions', 'attributes' => ['title' => 'Notice period?']],
                    ['id' => 'a1', 'type' => 'answers', 'attributes' => ['text' => '1 month'], 'relationships' => ['question' => ['data' => ['id' => 'q1']]]],
                    ['id' => 'a2', 'type' => 'answers', 'attributes' => ['boolean' => true], 'relationships' => ['question' => ['data' => ['id' => 'q2']]]],
                ],
            ],
            '/v1/candidates/502' => [
                'data' => ['id' => '502', 'type' => 'candidates', 'attributes' => ['resume' => null, 'original-resume' => null]],
            ],
        ];
    }
}
