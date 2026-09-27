<?php

namespace App\Http\Controllers\Admin\Exams;

use App\Http\Controllers\Controller;
use App\Models\Exams\Exam;
use App\Models\Exams\ExamQuestion;
use App\Services\Ai\TextTranslator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Exams ▸ Manage exams ▸ Questions: an exam's bank, with the answers.
 * `manage-exams` only — this page is the answer key.
 */
class ExamQuestionController extends Controller
{
    /** Option keys offered on the form. */
    private const KEYS = ['A', 'B', 'C', 'D', 'E', 'F'];

    public function index(Request $request, Exam $exam)
    {
        $query = $exam->questions()->orderBy('domain')->orderBy('id');

        if ($domain = $request->query('domain')) {
            $query->where('domain', $domain);
        }
        if ($search = trim((string) $request->query('q'))) {
            $query->where('question', 'like', '%'.$search.'%');
        }
        if ($request->query('status') === 'inactive') {
            $query->where('is_active', false);
        } elseif ($request->query('status') === 'active') {
            $query->where('is_active', true);
        }

        return view('admin.exams.manage.questions', [
            'exam' => $exam,
            'questions' => $query->paginate(50)->withQueryString(),
            'domains' => $exam->questions()->selectRaw('domain, count(*) as n, sum(case when is_active then 1 else 0 end) as active')
                ->groupBy('domain')->orderBy('domain')->get(),
        ]);
    }

    public function create(Exam $exam)
    {
        return view('admin.exams.manage.question-form', [
            'exam' => $exam,
            'question' => new ExamQuestion(['type' => ExamQuestion::TYPE_SINGLE, 'options' => [], 'answer' => [], 'shuffle_options' => true, 'is_active' => true]),
            'keys' => self::KEYS,
            'domainOptions' => $exam->questions()->distinct()->orderBy('domain')->pluck('domain'),
        ]);
    }

    public function store(Request $request, Exam $exam)
    {
        $exam->questions()->create($this->validated($request));

        return redirect()->route('admin.exams.manage.questions.index', $exam)->with('success', 'Question added.');
    }

    public function edit(Exam $exam, ExamQuestion $question)
    {
        abort_unless($question->exam_id === $exam->id, 404);

        return view('admin.exams.manage.question-form', [
            'exam' => $exam,
            'question' => $question,
            'keys' => self::KEYS,
            'domainOptions' => $exam->questions()->distinct()->orderBy('domain')->pluck('domain'),
        ]);
    }

    public function update(Request $request, Exam $exam, ExamQuestion $question)
    {
        abort_unless($question->exam_id === $exam->id, 404);
        $question->update($this->validated($request));

        return redirect()->route('admin.exams.manage.questions.index', $exam)->with('success', 'Question saved.');
    }

    public function toggle(Exam $exam, ExamQuestion $question)
    {
        abort_unless($question->exam_id === $exam->id, 404);
        $question->update(['is_active' => ! $question->is_active]);

        return back()->with('success', $question->is_active ? 'Question switched on.' : 'Question switched off — new attempts will not draw it.');
    }

    public function destroy(Exam $exam, ExamQuestion $question)
    {
        abort_unless($question->exam_id === $exam->id, 404);
        $question->delete();

        return back()->with('success', 'Question deleted. Scores already given are unchanged.');
    }

    /**
     * Fills the Arabic fields from the English with the AI assistant's
     * translator. The form only shows the result; saving is a separate,
     * reviewed step.
     */
    public function translate(Request $request, TextTranslator $translator): JsonResponse
    {
        $data = $request->validate([
            'question' => ['required', 'string', 'max:5000'],
            'options' => ['nullable', 'array'],
            'options.*' => ['nullable', 'string', 'max:1000'],
            'explanation' => ['nullable', 'string', 'max:5000'],
        ]);

        try {
            $options = [];
            foreach ($data['options'] ?? [] as $key => $text) {
                if (in_array($key, self::KEYS, true) && filled($text)) {
                    $options[$key] = $translator->translate($text, 'ar');
                }
            }

            return response()->json([
                'question' => $translator->translate($data['question'], 'ar'),
                'options' => $options,
                'explanation' => filled($data['explanation'] ?? null) ? $translator->translate($data['explanation'], 'ar') : '',
            ]);
        } catch (\Throwable $e) {
            Log::warning('ExamQuestionController: translation failed', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'The translation could not be made. Check the AI Assistant settings, or type the Arabic yourself.'], 502);
        }
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'domain' => ['required', 'string', 'max:200'],
            'type' => ['required', 'in:single,multiple'],
            'question' => ['required', 'string', 'max:5000'],
            'options' => ['required', 'array'],
            'options.*' => ['nullable', 'string', 'max:1000'],
            'answer' => ['required', 'array', 'min:1'],
            'answer.*' => ['in:'.implode(',', self::KEYS)],
            'explanation' => ['nullable', 'string', 'max:5000'],
            'reference' => ['nullable', 'url', 'max:500'],
            'question_ar' => ['nullable', 'string', 'max:5000'],
            'options_ar' => ['nullable', 'array'],
            'options_ar.*' => ['nullable', 'string', 'max:1000'],
            'explanation_ar' => ['nullable', 'string', 'max:5000'],
        ], [
            'answer.required' => 'Tick the correct answer.',
        ]);

        // Keep only the filled options, re-lettered A, B, C… in order, and
        // carry the ticked answers across the re-lettering.
        $options = [];
        $optionsAr = [];
        $answer = [];
        foreach (self::KEYS as $key) {
            $text = trim((string) ($data['options'][$key] ?? ''));
            if ($text === '') {
                continue;
            }
            $newKey = self::KEYS[count($options)];
            $options[$newKey] = $text;
            // The Arabic travels with its English option through the re-lettering.
            $optionsAr[$newKey] = trim((string) ($data['options_ar'][$key] ?? ''));
            if (in_array($key, $data['answer'], true)) {
                $answer[] = $newKey;
            }
        }

        if (count($options) < 2) {
            throw ValidationException::withMessages(['options' => 'Give at least two options.']);
        }
        if (count($answer) !== count($data['answer'])) {
            throw ValidationException::withMessages(['answer' => 'A ticked answer has no text.']);
        }
        if ($data['type'] === ExamQuestion::TYPE_SINGLE && count($answer) !== 1) {
            throw ValidationException::withMessages(['answer' => 'A single-answer question has exactly one correct option. Choose "Multiple" for more.']);
        }
        if ($data['type'] === ExamQuestion::TYPE_MULTIPLE && count($answer) < 2) {
            throw ValidationException::withMessages(['answer' => 'A multiple-answer question needs at least two correct options.']);
        }

        return [
            'domain' => trim($data['domain']),
            'type' => $data['type'],
            'question' => trim($data['question']),
            'options' => $options,
            'answer' => $answer,
            'explanation' => $data['explanation'] ?? null,
            'reference' => $data['reference'] ?? null,
            'question_ar' => filled($data['question_ar'] ?? null) ? trim($data['question_ar']) : null,
            'options_ar' => array_filter($optionsAr) ? $optionsAr : null,
            'explanation_ar' => filled($data['explanation_ar'] ?? null) ? trim($data['explanation_ar']) : null,
            'shuffle_options' => $request->boolean('shuffle_options'),
            'is_active' => $request->boolean('is_active'),
        ];
    }
}
