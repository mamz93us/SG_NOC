<?php

namespace App\Http\Controllers\Admin\Exams;

use App\Http\Controllers\Controller;
use App\Models\Exams\ExamAttempt;
use App\Models\Exams\ExamQuestion;
use App\Services\Exams\ExamSession;
use Illuminate\Http\Request;

/**
 * Sitting an exam: one question per screen, a mark-for-review flag, the
 * review grid and Finish — then the score report and, where the exam allows
 * it, the answers with explanations.
 *
 * Only the candidate can open their own sitting while it runs. The score
 * report and answers are also open to `manage-exams`.
 */
class ExamAttemptController extends Controller
{
    public function __construct(private ExamSession $session) {}

    public function question(Request $request, ExamAttempt $attempt, int $position)
    {
        $this->ownOnly($request, $attempt);
        if ($redirect = $this->closedRedirect($attempt)) {
            return $redirect;
        }

        $ids = array_map('intval', $attempt->question_ids);
        abort_unless($position >= 1 && $position <= count($ids), 404);

        $question = ExamQuestion::find($ids[$position - 1]);

        return view('admin.exams.take.question', [
            'attempt' => $attempt->load('exam'),
            'position' => $position,
            'total' => count($ids),
            'question' => $question,
            'order' => $question ? $attempt->optionOrderFor($question) : [],
            'chosen' => $question ? $attempt->answerFor($question->id) : [],
            'flagged' => $question ? $attempt->isFlagged($question->id) : false,
        ]);
    }

    public function answer(Request $request, ExamAttempt $attempt, int $position)
    {
        $this->ownOnly($request, $attempt);
        if ($redirect = $this->closedRedirect($attempt)) {
            return $redirect;
        }

        $ids = array_map('intval', $attempt->question_ids);
        abort_unless($position >= 1 && $position <= count($ids), 404);

        $data = $request->validate([
            'keys' => ['nullable', 'array'],
            'keys.*' => ['string', 'max:5'],
            'flagged' => ['nullable', 'boolean'],
            'go' => ['nullable', 'string', 'max:20'],
        ]);

        $go = $data['go'] ?? 'next';

        $question = ExamQuestion::find($ids[$position - 1]);
        if ($question && ! $this->session->saveAnswer($attempt, $question, $data['keys'] ?? [], (bool) ($data['flagged'] ?? false))) {
            $attempt->refresh();

            return $go === 'stay'
                ? response()->json(['saved' => false, 'redirect' => route('admin.exams.attempts.result', $attempt)])
                : $this->closedRedirect($attempt);
        }

        // The page saves each click in the background, so an answer is kept
        // even if the clock runs out before the candidate moves on.
        if ($go === 'stay') {
            return response()->json(['saved' => true, 'seconds_left' => $attempt->secondsLeft()]);
        }

        if ($go === 'finish') {
            $this->session->finish($attempt);

            return redirect()->route('admin.exams.attempts.result', $attempt);
        }
        if ($go === 'review') {
            return redirect()->route('admin.exams.attempts.review', $attempt);
        }

        $target = match (true) {
            $go === 'prev' => $position - 1,
            ctype_digit($go) => (int) $go,
            default => $position + 1,
        };

        // Past the last question the real exam goes to the review screen.
        if ($target > count($ids)) {
            return redirect()->route('admin.exams.attempts.review', $attempt);
        }

        return redirect()->route('admin.exams.attempts.question', [$attempt, max(1, $target)]);
    }

    public function review(Request $request, ExamAttempt $attempt)
    {
        $this->ownOnly($request, $attempt);
        if ($redirect = $this->closedRedirect($attempt)) {
            return $redirect;
        }

        return view('admin.exams.take.review', ['attempt' => $attempt->load('exam')]);
    }

    public function finish(Request $request, ExamAttempt $attempt)
    {
        $this->ownOnly($request, $attempt);
        $this->session->finish($attempt);

        return redirect()->route('admin.exams.attempts.result', $attempt);
    }

    public function result(Request $request, ExamAttempt $attempt)
    {
        $this->ownOrManager($request, $attempt);
        if ($attempt->isInProgress() && ! $this->session->expireIfDue($attempt)) {
            return (int) $request->user()->id === (int) $attempt->user_id
                ? redirect()->route('admin.exams.attempts.review', $attempt)
                : back()->with('error', 'That exam is still in progress.');
        }

        $attempt->load('exam', 'user');

        $history = ExamAttempt::where('exam_id', $attempt->exam_id)
            ->where('user_id', $attempt->user_id)
            ->whereNotNull('score')
            ->orderBy('id')
            ->get(['id', 'score', 'passed', 'submitted_at']);

        return view('admin.exams.result', [
            'attempt' => $attempt,
            'history' => $history,
            'canSeeAnswers' => $this->canSeeAnswers($request, $attempt),
        ]);
    }

    public function answers(Request $request, ExamAttempt $attempt)
    {
        $this->ownOrManager($request, $attempt);
        abort_unless($attempt->isFinished() && $this->canSeeAnswers($request, $attempt), 403);

        $attempt->load('exam', 'user');
        $questions = ExamQuestion::whereIn('id', $attempt->question_ids)->get()->keyBy('id');
        $filter = $request->query('show') === 'wrong' ? 'wrong' : 'all';

        $rows = [];
        foreach (array_map('intval', $attempt->question_ids) as $i => $id) {
            $right = (bool) ($attempt->results[(string) $id] ?? false);
            if ($filter === 'wrong' && $right) {
                continue;
            }
            $rows[] = [
                'position' => $i + 1,
                'question' => $questions->get($id),
                'chosen' => $attempt->answerFor($id),
                'right' => $right,
                'flagged' => $attempt->isFlagged($id),
            ];
        }

        return view('admin.exams.answers', compact('attempt', 'rows', 'filter'));
    }

    private function ownOnly(Request $request, ExamAttempt $attempt): void
    {
        // 404, not 403: a sitting's id says nothing to anyone else.
        abort_unless((int) $attempt->user_id === (int) $request->user()->id, 404);
    }

    private function ownOrManager(Request $request, ExamAttempt $attempt): void
    {
        abort_unless((int) $attempt->user_id === (int) $request->user()->id || $request->user()->can('manage-exams'), 404);
    }

    private function canSeeAnswers(Request $request, ExamAttempt $attempt): bool
    {
        return $request->user()->can('manage-exams') || (bool) $attempt->exam?->show_review;
    }

    /** Where to send someone who reaches a sitting that is over. */
    private function closedRedirect(ExamAttempt $attempt)
    {
        if ($attempt->isInProgress() && ! $this->session->expireIfDue($attempt)) {
            return null;
        }

        return redirect()->route('admin.exams.attempts.result', $attempt)
            ->with($attempt->status === ExamAttempt::STATUS_EXPIRED ? 'warning' : 'info',
                $attempt->status === ExamAttempt::STATUS_EXPIRED
                    ? 'Time is up. The exam was scored with the answers you had saved.'
                    : 'This exam has already been submitted.');
    }
}
