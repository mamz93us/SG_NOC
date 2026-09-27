<?php

use App\Http\Controllers\Admin\Exams\ExamAttemptController;
use App\Http\Controllers\Admin\Exams\ExamQuestionController;
use App\Models\Exams\Exam;
use App\Models\Exams\ExamQuestion;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\Exams\ExamBankImporter;
use App\Services\Exams\ExamSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Tests\Unit\Rbac\RbacTestSchema;

/**
 * Sitting an exam in Arabic: the bank carries the translation option for
 * option under the same keys, so the language changes what is shown and
 * never what is graded; a question not fully translated stays in English.
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
    foreach (['100001_create_exams_tables', '100002_add_exam_permissions', '120001_add_arabic_to_exams'] as $m) {
        (require database_path("migrations/2026_09_27_{$m}.php"))->up();
    }
    Role::clearCache();
    RolePermission::clearCache();
    User::clearOverrideCache();

    $this->candidate = User::forceCreate(['name' => 'Candidate', 'email' => 'c@samirgroup.com', 'password' => 'x', 'role' => 'viewer']);
    $this->actingAs($this->candidate);
});

afterEach(function () {
    foreach (['exam_attempts', 'exam_questions', 'exams'] as $table) {
        Schema::dropIfExists($table);
    }
    RbacTestSchema::drop();
});

function arabicRequest(User $user, array $data = []): Request
{
    $request = Request::create('/admin/exams', 'POST', $data);
    $request->setUserResolver(fn () => $user);
    $request->setLaravelSession(app('session.store'));

    return $request;
}

it('ships every bundled question in Arabic with the same option keys', function () {
    foreach (ExamBankImporter::bundledFiles() as $file) {
        app(ExamBankImporter::class)->importFile($file);
    }

    foreach (Exam::all() as $exam) {
        expect($exam->title_ar)->not->toBeEmpty()
            ->and($exam->arabicQuestionCount())->toBe($exam->activeQuestions()->count());
    }
});

it('shows a half-translated question in English rather than mixing the two', function () {
    $q = new ExamQuestion([
        'question' => 'Which service?', 'question_ar' => 'أي خدمة؟',
        'options' => ['A' => 'Azure Policy', 'B' => 'Azure Advisor'],
        'options_ar' => ['A' => 'Azure Policy'],
    ]);

    expect($q->hasArabic())->toBeFalse()
        ->and($q->shownIn('ar'))->toBe('en')
        ->and($q->textIn('ar'))->toBe('Which service?');

    $q->options_ar = ['A' => 'Azure Policy', 'B' => 'Azure Advisor'];
    expect($q->shownIn('ar'))->toBe('ar')
        ->and($q->textIn('ar'))->toBe('أي خدمة؟')
        ->and($q->optionIn('B', 'ar'))->toBe('Azure Advisor');
});

it('refuses Arabic options whose keys differ from the English ones', function () {
    expect(fn () => app(ExamBankImporter::class)->import([
        'exam' => ['code' => 'X-1', 'title' => 'X'],
        'questions' => [[
            'uid' => 'x1', 'domain' => 'D', 'question' => 'Q?',
            'options' => ['A' => 'a', 'B' => 'b'], 'answer' => ['A'],
            'options_ar' => ['A' => 'أ', 'C' => 'ج'],
        ]],
    ]))->toThrow(InvalidArgumentException::class, 'options_ar');
});

it('runs a sitting in Arabic right to left, with the English one click away, and grades it the same', function () {
    app(ExamBankImporter::class)->importFile(database_path('data/exams/az-900.json'));
    $exam = Exam::where('code', 'AZ-900')->sole();
    $session = app(ExamSession::class);

    $attempt = $session->startOrResume($exam, $this->candidate, 'ar');
    expect($attempt->language)->toBe('ar');

    $q = ExamQuestion::find($attempt->question_ids[0]);
    $html = app(ExamAttemptController::class)->question(arabicRequest($this->candidate), $attempt, 1)->render();

    expect($html)->toContain('dir="rtl"')
        ->and($html)->toContain('bootstrap.rtl.min.css')
        ->and($html)->toContain(e($q->question_ar))
        ->and($html)->toContain(e($q->question))       // the English original, behind the toggle
        ->and($html)->toContain('السؤال 1 من 45')
        ->and($html)->not->toContain(e($q->explanation_ar));

    foreach (ExamQuestion::whereIn('id', $attempt->question_ids)->get() as $question) {
        $session->saveAnswer($attempt, $question, $question->answer, false);
    }
    $session->finish($attempt);
    expect($attempt->fresh()->score)->toBe(1000);

    $answers = app(ExamAttemptController::class)->answers(arabicRequest($this->candidate), $attempt->fresh());
    expect($answers->getData()['rows'])->toHaveCount(45);

    // Coming back to an open sitting may switch the language; unknown ones fall back to English.
    $next = $session->startOrResume($exam, $this->candidate, 'xx');
    expect($next->language)->toBe('en');
});

it('keeps each Arabic option with its English one when the editor re-letters them', function () {
    $exam = Exam::create(['code' => 'T-1', 'title' => 'T']);
    $request = arabicRequest($this->candidate, [
        'domain' => 'D', 'type' => 'single', 'question' => 'Q?', 'question_ar' => 'س؟',
        'options' => ['A' => 'first', 'B' => '', 'C' => 'third'],
        'options_ar' => ['A' => 'الأول', 'B' => 'يتيم', 'C' => 'الثالث'],
        'answer' => ['C'], 'is_active' => '1',
    ]);

    app(ExamQuestionController::class)->store($request, $exam);
    $q = $exam->questions()->sole();

    expect($q->options)->toBe(['A' => 'first', 'B' => 'third'])
        ->and($q->options_ar)->toBe(['A' => 'الأول', 'B' => 'الثالث'])
        ->and($q->answer)->toBe(['B'])
        ->and($q->hasArabic())->toBeTrue();
});
