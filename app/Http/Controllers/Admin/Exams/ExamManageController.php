<?php

namespace App\Http\Controllers\Admin\Exams;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Exams\Exam;
use App\Models\Exams\ExamAttempt;
use App\Services\Exams\ExamBankImporter;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Exams ▸ Manage exams: the exams, their settings, and loading question
 * banks (the bundled AZ-900 / AI-900 files or an uploaded JSON). `manage-exams`.
 */
class ExamManageController extends Controller
{
    public function index()
    {
        $exams = Exam::withCount([
            'questions',
            'questions as active_questions_count' => fn ($q) => $q->where('is_active', true),
            'attempts as finished_count' => fn ($q) => $q->where('status', '!=', ExamAttempt::STATUS_IN_PROGRESS),
            'attempts as passed_count' => fn ($q) => $q->where('passed', true),
        ])->orderBy('code')->get();
        $arabic = $exams->mapWithKeys(fn ($exam) => [$exam->id => $exam->arabicQuestionCount()]);

        $bundled = collect(ExamBankImporter::bundledFiles())->map(function ($path) {
            $data = json_decode((string) @file_get_contents($path), true);

            return [
                'file' => basename($path),
                'code' => $data['exam']['code'] ?? '?',
                'title' => $data['exam']['title'] ?? '',
                'questions' => count($data['questions'] ?? []),
            ];
        });

        return view('admin.exams.manage.index', compact('exams', 'bundled', 'arabic'));
    }

    public function create()
    {
        return view('admin.exams.manage.form', ['exam' => new Exam([
            'duration_minutes' => 45, 'question_count' => 45, 'passing_score' => 700,
            'show_review' => true, 'is_active' => true,
        ])]);
    }

    public function store(Request $request)
    {
        $exam = Exam::create($this->validated($request));

        return redirect()->route('admin.exams.manage.questions.index', $exam)
            ->with('success', "{$exam->code} created. Add its questions, or import a bank file.");
    }

    public function edit(Exam $exam)
    {
        return view('admin.exams.manage.form', compact('exam'));
    }

    public function update(Request $request, Exam $exam)
    {
        $exam->update($this->validated($request, $exam));

        return redirect()->route('admin.exams.manage.index')->with('success', "{$exam->code} saved.");
    }

    public function destroy(Exam $exam)
    {
        $code = $exam->code;
        $attempts = $exam->attempts()->count();
        ActivityLog::log('exam_deleted', $exam, ['exam' => $code, 'attempts_deleted' => $attempts]);
        $exam->delete();

        return redirect()->route('admin.exams.manage.index')
            ->with('success', "{$code} deleted, with its questions and {$attempts} attempt(s).");
    }

    public function loadBundled(ExamBankImporter $importer)
    {
        $lines = [];
        foreach (ExamBankImporter::bundledFiles() as $file) {
            try {
                $r = $importer->importFile($file);
                $lines[] = "{$r['exam']->code}: {$r['created']} added, {$r['updated']} updated, {$r['unchanged']} unchanged";
            } catch (InvalidArgumentException $e) {
                return back()->with('error', basename($file).': '.$e->getMessage());
            }
        }

        return back()->with('success', $lines ? implode(' · ', $lines) : 'No bundled banks found.');
    }

    public function import(Request $request, ExamBankImporter $importer)
    {
        $request->validate(['bank' => ['required', 'file', 'max:5120']]);

        try {
            $r = $importer->importJson((string) file_get_contents($request->file('bank')->getRealPath()));
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.exams.manage.questions.index', $r['exam'])
            ->with('success', "{$r['exam']->code}: {$r['created']} added, {$r['updated']} updated, {$r['unchanged']} unchanged.");
    }

    private function validated(Request $request, ?Exam $exam = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', 'unique:exams,code'.($exam ? ','.$exam->id : '')],
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'title_ar' => ['nullable', 'string', 'max:200'],
            'description_ar' => ['nullable', 'string', 'max:5000'],
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:600'],
            'question_count' => ['required', 'integer', 'min:0', 'max:500'],
            'passing_score' => ['required', 'integer', 'min:1', 'max:1000'],
        ]);

        $data['code'] = strtoupper(trim($data['code']));
        $data['show_review'] = $request->boolean('show_review');
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }
}
