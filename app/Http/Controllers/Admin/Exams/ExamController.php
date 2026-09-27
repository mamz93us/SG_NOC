<?php

namespace App\Http\Controllers\Admin\Exams;

use App\Http\Controllers\Controller;
use App\Models\Exams\Exam;
use App\Models\Exams\ExamAttempt;
use App\Services\Exams\ExamSession;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Exams ▸ Take an exam: the list of exams, the instructions page, and
 * starting (or resuming) a sitting. `take-exams`.
 */
class ExamController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $exams = Exam::where('is_active', true)
            ->withCount(['questions as bank_size' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('code')
            ->get();

        $mine = ExamAttempt::with('exam:id,code,title')
            ->where('user_id', $user->id)
            ->latest('id')
            ->limit(30)
            ->get();

        $open = $mine->where('status', ExamAttempt::STATUS_IN_PROGRESS)->keyBy('exam_id');
        $best = $mine->whereNotNull('score')->groupBy('exam_id')->map(fn ($rows) => $rows->max('score'));

        return view('admin.exams.index', compact('exams', 'mine', 'open', 'best'));
    }

    /** The instructions screen shown before the clock starts. */
    public function show(Request $request, Exam $exam)
    {
        abort_unless($exam->is_active, 404);

        $bankSize = $exam->activeQuestions()->count();
        $open = ExamAttempt::where('exam_id', $exam->id)
            ->where('user_id', $request->user()->id)
            ->where('status', ExamAttempt::STATUS_IN_PROGRESS)
            ->latest('id')
            ->first();
        if ($open && $open->hasTimedOut()) {
            app(ExamSession::class)->finish($open, expired: true);
            $open = null;
        }

        return view('admin.exams.intro', [
            'exam' => $exam,
            'questionCount' => $exam->questionsPerAttempt($bankSize),
            'domains' => $exam->activeQuestions()->selectRaw('domain, count(*) as n')->groupBy('domain')->orderBy('domain')->get(),
            'open' => $open,
        ]);
    }

    public function start(Request $request, Exam $exam, ExamSession $session)
    {
        abort_unless($exam->is_active, 404);

        try {
            $attempt = $session->startOrResume($exam, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        // Resume on the first question not answered yet.
        $position = 1;
        foreach ($attempt->question_ids as $i => $id) {
            if (! $attempt->answerFor((int) $id)) {
                $position = $i + 1;
                break;
            }
        }

        return redirect()->route('admin.exams.attempts.question', [$attempt, $position]);
    }
}
