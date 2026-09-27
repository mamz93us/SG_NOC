<?php

namespace App\Http\Controllers\Admin\Exams;

use App\Http\Controllers\Controller;
use App\Models\Exams\Exam;
use App\Models\Exams\ExamAttempt;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exams ▸ Team results: every sitting, and per person their best and latest
 * score per exam — who is ready to book the real exam. `manage-exams`.
 */
class ExamResultController extends Controller
{
    public function index(Request $request)
    {
        $exams = Exam::orderBy('code')->get(['id', 'code', 'title', 'passing_score']);
        $examId = $request->integer('exam') ?: null;

        $attempts = $this->filtered($request, $examId)
            ->with(['exam:id,code', 'user:id,name,email'])
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        // One row per person per exam, over every finished sitting.
        $summary = ExamAttempt::query()
            ->where('status', '!=', ExamAttempt::STATUS_IN_PROGRESS)
            ->when($examId, fn ($q) => $q->where('exam_id', $examId))
            ->orderBy('id')
            ->get(['id', 'exam_id', 'user_id', 'score', 'passed', 'submitted_at'])
            ->groupBy(fn ($a) => $a->user_id.'-'.$a->exam_id)
            ->map(fn ($rows) => [
                'user_id' => $rows->first()->user_id,
                'exam_id' => $rows->first()->exam_id,
                'attempts' => $rows->count(),
                'best' => $rows->max('score'),
                'latest' => $rows->last()->score,
                'latest_id' => $rows->last()->id,
                'last_at' => $rows->last()->submitted_at,
                'passes' => $rows->where('passed', true)->count(),
            ])
            ->sortByDesc('best')
            ->values();

        $users = User::whereIn('id', $summary->pluck('user_id')->unique())->get(['id', 'name', 'email'])->keyBy('id');

        return view('admin.exams.results', [
            'exams' => $exams->keyBy('id'),
            'examId' => $examId,
            'attempts' => $attempts,
            'summary' => $summary,
            'users' => $users,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $rows = $this->filtered($request, $request->integer('exam') ?: null)
            ->where('status', '!=', ExamAttempt::STATUS_IN_PROGRESS)
            ->with(['exam:id,code', 'user:id,name,email'])
            ->orderBy('id')
            ->get();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Attempt', 'Exam', 'Name', 'Email', 'Started', 'Finished', 'Minutes', 'Correct', 'Questions', 'Score', 'Pass mark', 'Result', 'Timed out']);
            foreach ($rows as $a) {
                fputcsv($out, [
                    $a->id, $a->exam?->code, $a->user?->name, $a->user?->email,
                    $a->started_at?->format('Y-m-d H:i'), $a->submitted_at?->format('Y-m-d H:i'),
                    $a->durationSeconds() !== null ? round($a->durationSeconds() / 60, 1) : '',
                    $a->correct_count, $a->total_questions, $a->score, $a->passing_score,
                    $a->passed ? 'Pass' : 'Fail',
                    $a->status === ExamAttempt::STATUS_EXPIRED ? 'Yes' : 'No',
                ]);
            }
            fclose($out);
        }, 'exam-results-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    private function filtered(Request $request, ?int $examId)
    {
        return ExamAttempt::query()
            ->when($examId, fn ($q) => $q->where('exam_id', $examId))
            ->when($request->query('result') === 'pass', fn ($q) => $q->where('passed', true))
            ->when($request->query('result') === 'fail', fn ($q) => $q->where('passed', false))
            ->when($request->query('result') === 'open', fn ($q) => $q->where('status', ExamAttempt::STATUS_IN_PROGRESS))
            ->when(trim((string) $request->query('q')), fn ($q, $s) => $q->whereHas('user', fn ($u) => $u->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%")));
    }
}
