<?php

use App\Http\Controllers\Admin\Exams\ExamAttemptController;
use App\Models\ActivityLog;
use App\Models\Exams\Exam;
use App\Models\Exams\ExamAttempt;
use App\Models\Exams\ExamQuestion;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\Exams\ExamBankImporter;
use App\Services\Exams\ExamSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Unit\Rbac\RbacTestSchema;

/**
 * Sitting an exam end to end against the real bundled banks: loading them,
 * the draw, saving answers, the server-side clock, grading once, and who may
 * see a sitting and its answers.
 */
uses(Tests\TestCase::class);

beforeEach(function () {
    config(['cache.default' => 'array']);
    $this->withoutVite();
    foreach (['exam_attempts', 'exam_questions', 'exams'] as $table) {
        Schema::dropIfExists($table);
    }
    RbacTestSchema::drop();
    RbacTestSchema::create();
    (require database_path('migrations/2026_09_27_100001_create_exams_tables.php'))->up();
    (require database_path('migrations/2026_09_27_100002_add_exam_permissions.php'))->up();
    (require database_path('migrations/2026_09_27_120001_add_arabic_to_exams.php'))->up();

    Role::clearCache();
    RolePermission::clearCache();
    User::clearOverrideCache();

    $this->candidate = User::forceCreate(['name' => 'Candidate', 'email' => 'c@samirgroup.com', 'password' => 'x', 'role' => 'viewer']);
    $this->other = User::forceCreate(['name' => 'Other', 'email' => 'o@samirgroup.com', 'password' => 'x', 'role' => 'admin']);
    $this->manager = User::forceCreate(['name' => 'Manager', 'email' => 'm@samirgroup.com', 'password' => 'x', 'role' => 'super_admin']);
    $this->actingAs($this->candidate);
});

afterEach(function () {
    foreach (['exam_attempts', 'exam_questions', 'exams'] as $table) {
        Schema::dropIfExists($table);
    }
    RbacTestSchema::drop();
});

function loadBanks(): void
{
    foreach (ExamBankImporter::bundledFiles() as $file) {
        app(ExamBankImporter::class)->importFile($file);
    }
}

function examRequest(User $user, string $method = 'GET', array $data = []): Request
{
    $request = Request::create('/admin/exams', $method, $data);
    $request->setUserResolver(fn () => $user);
    $request->setLaravelSession(app('session.store'));

    return $request;
}

it('loads both bundled banks and loading again changes nothing', function () {
    loadBanks();

    $az = Exam::where('code', 'AZ-900')->sole();
    $ai = Exam::where('code', 'AI-900')->sole();

    expect($az->questions()->count())->toBe(100)
        ->and($ai->questions()->count())->toBe(80)
        ->and($az->questionsPerAttempt())->toBe(45)
        ->and($az->passing_score)->toBe(700);

    // A manager switches one off and shortens the exam; a reload respects both.
    $off = $az->questions()->first();
    $off->update(['is_active' => false]);
    $az->update(['question_count' => 40]);

    $again = app(ExamBankImporter::class)->importFile(database_path('data/exams/az-900.json'));

    expect($again['created'])->toBe(0)
        ->and($again['updated'])->toBe(0)
        ->and($again['unchanged'])->toBe(100)
        ->and($off->fresh()->is_active)->toBeFalse()
        ->and($az->fresh()->question_count)->toBe(40);
});

it('refuses a bank whose answer is not one of its options', function () {
    expect(fn () => app(ExamBankImporter::class)->import([
        'exam' => ['code' => 'X-1', 'title' => 'X'],
        'questions' => [['uid' => 'x1', 'domain' => 'D', 'question' => 'Q?', 'options' => ['A' => 'a', 'B' => 'b'], 'answer' => ['C']]],
    ]))->toThrow(InvalidArgumentException::class, 'answer');

    expect(Exam::count())->toBe(0);
});

it('draws the exam length from the bank with every option key kept', function () {
    loadBanks();
    $exam = Exam::where('code', 'AZ-900')->sole();

    $attempt = app(ExamSession::class)->startOrResume($exam, $this->candidate);

    expect($attempt->question_ids)->toHaveCount(45)
        ->and(array_unique($attempt->question_ids))->toHaveCount(45)
        ->and($attempt->expires_at->diffInMinutes($attempt->started_at, true))->toEqual(45);

    foreach (ExamQuestion::whereIn('id', $attempt->question_ids)->get() as $q) {
        $order = $attempt->optionOrderFor($q);
        sort($order);
        $keys = array_keys($q->options);
        sort($keys);
        expect($order)->toBe($keys);
        if (! $q->shuffle_options) {
            expect($attempt->optionOrderFor($q))->toBe(['A', 'B']);
        }
    }

    // Starting again resumes the same sitting.
    expect(app(ExamSession::class)->startOrResume($exam, $this->candidate)->id)->toBe($attempt->id)
        ->and(ActivityLog::where('action', 'exam_started')->count())->toBe(1);
});

it('saves only real options, one for a single-answer question, and grades exactly once', function () {
    loadBanks();
    $exam = Exam::where('code', 'AI-900')->sole();
    $session = app(ExamSession::class);
    $attempt = $session->startOrResume($exam, $this->candidate);
    $questions = ExamQuestion::whereIn('id', $attempt->question_ids)->get()->keyBy('id');

    // Answer every question correctly except the first.
    foreach ($attempt->question_ids as $i => $id) {
        $q = $questions[$id];
        $keys = $i === 0 ? ['Z'] : $q->answer;
        expect($session->saveAnswer($attempt, $q, $keys, $i === 1))->toBeTrue();
    }
    $single = $questions->first(fn ($q) => ! $q->isMultiple() && ! in_array($q->id, array_slice($attempt->question_ids, 0, 2), true));
    $session->saveAnswer($attempt, $single, ['A', 'B'], false);
    expect($attempt->answerFor($single->id))->toHaveCount(1);
    $session->saveAnswer($attempt, $single, $single->answer, false);

    expect($attempt->answerFor($attempt->question_ids[0]))->toBe([])
        ->and($attempt->isFlagged($attempt->question_ids[1]))->toBeTrue();

    $session->finish($attempt);
    $session->finish($attempt);

    $attempt->refresh();
    expect($attempt->status)->toBe(ExamAttempt::STATUS_SUBMITTED)
        ->and($attempt->correct_count)->toBe(44)
        ->and($attempt->score)->toBe((int) round(44 * 1000 / 45))
        ->and($attempt->passed)->toBeTrue()
        ->and(collect($attempt->domain_results)->sum('total'))->toBe(45)
        ->and(ActivityLog::where('action', 'exam_finished')->count())->toBe(1)
        // A finished sitting takes no more answers.
        ->and($session->saveAnswer($attempt, $single, ['A'], false))->toBeFalse();
});

it('stops taking answers when the time is up and grades what was saved', function () {
    loadBanks();
    $exam = Exam::where('code', 'AI-900')->sole();
    $session = app(ExamSession::class);
    $attempt = $session->startOrResume($exam, $this->candidate);
    $first = ExamQuestion::find($attempt->question_ids[0]);
    $second = ExamQuestion::find($attempt->question_ids[1]);

    $session->saveAnswer($attempt, $first, $first->answer, false);

    $this->travel(46)->minutes();

    expect($session->saveAnswer($attempt, $second, $second->answer, false))->toBeFalse();

    $attempt->refresh();
    expect($attempt->status)->toBe(ExamAttempt::STATUS_EXPIRED)
        ->and($attempt->correct_count)->toBe(1)
        ->and($attempt->submitted_at->equalTo($attempt->expires_at))->toBeTrue()
        ->and($attempt->durationSeconds())->toBe(45 * 60);

    // "Start" after a timed-out sitting begins a fresh one.
    expect($session->startOrResume($exam, $this->candidate)->id)->not->toBe($attempt->id);
});

it('never puts the answer or explanation on the question screen', function () {
    loadBanks();
    $exam = Exam::where('code', 'AZ-900')->sole();
    $attempt = app(ExamSession::class)->startOrResume($exam, $this->candidate);
    $q = ExamQuestion::find($attempt->question_ids[0]);

    $html = app(ExamAttemptController::class)->question(examRequest($this->candidate), $attempt, 1)->render();

    expect($html)->toContain(e($q->question))
        ->and($html)->toContain('Question 1 of 45')
        ->and($html)->not->toContain(e($q->explanation));
});

it('keeps a sitting to its candidate, and the answers to them or a manager', function () {
    loadBanks();
    $exam = Exam::where('code', 'AZ-900')->sole();
    $session = app(ExamSession::class);
    $attempt = $session->startOrResume($exam, $this->candidate);
    $controller = app(ExamAttemptController::class);

    expect(fn () => $controller->question(examRequest($this->other), $attempt, 1))
        ->toThrow(HttpException::class);

    $session->finish($attempt);

    expect(fn () => $controller->result(examRequest($this->other), $attempt->fresh()))->toThrow(HttpException::class)
        ->and($controller->result(examRequest($this->manager), $attempt->fresh())->getData()['canSeeAnswers'])->toBeTrue()
        ->and($controller->answers(examRequest($this->candidate), $attempt->fresh())->getData()['rows'])->toHaveCount(45);

    // With review switched off the candidate gets the score, not the key.
    $exam->update(['show_review' => false]);
    expect(fn () => $controller->answers(examRequest($this->candidate), $attempt->fresh()))->toThrow(HttpException::class)
        ->and($controller->answers(examRequest($this->manager), $attempt->fresh())->getData()['rows'])->toHaveCount(45);
});

it('gates the bank and the team results behind manage-exams', function () {
    foreach (['manage.index', 'manage.questions.index', 'manage.questions.edit', 'manage.import', 'results.index', 'results.export'] as $name) {
        expect(Route::getRoutes()->getByName("admin.exams.{$name}")?->gatherMiddleware())->toContain('permission:manage-exams');
    }
    foreach (['index', 'show', 'start', 'attempts.question', 'attempts.answer', 'attempts.finish'] as $name) {
        expect(Route::getRoutes()->getByName("admin.exams.{$name}")?->gatherMiddleware())->toContain('permission:take-exams');
    }

    expect(RolePermission::allSlugs())->toContain('take-exams', 'manage-exams')
        ->and(RolePermission::defaultPermissions()['admin'])->toContain('take-exams')->not->toContain('manage-exams')
        ->and($this->candidate->hasPermission('take-exams'))->toBeTrue()
        ->and($this->candidate->hasPermission('manage-exams'))->toBeFalse()
        ->and(config('audit.exclude'))->toContain(ExamAttempt::class);
});
