<?php

namespace App\Services\Exams;

use App\Models\ActivityLog;
use App\Models\Exams\Exam;
use App\Models\Exams\ExamAttempt;
use App\Models\Exams\ExamQuestion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Starts, runs and finishes a sitting. The clock is the server's: an answer
 * arriving after `expires_at` is not saved, and the attempt is graded as it
 * stood — the page's countdown only mirrors that.
 */
class ExamSession
{
    public function __construct(
        private QuestionPicker $picker = new QuestionPicker,
        private AttemptGrader $grader = new AttemptGrader,
    ) {}

    /**
     * The candidate's open attempt at this exam, or a new one. An open
     * attempt whose time ran out is graded first, so "start" never hands back
     * a sitting with no time left.
     */
    public function startOrResume(Exam $exam, User $user): ExamAttempt
    {
        $open = ExamAttempt::where('exam_id', $exam->id)
            ->where('user_id', $user->id)
            ->where('status', ExamAttempt::STATUS_IN_PROGRESS)
            ->latest('id')
            ->first();

        if ($open && ! $this->expireIfDue($open)) {
            return $open;
        }

        $questions = $exam->activeQuestions()->get(['id', 'domain', 'options', 'shuffle_options']);
        if ($questions->isEmpty()) {
            throw new RuntimeException("{$exam->code} has no active questions yet.");
        }

        $ids = $this->picker->pick(
            $questions->pluck('domain', 'id')->all(),
            $exam->questionsPerAttempt($questions->count()),
        );

        $orders = [];
        foreach ($questions->whereIn('id', $ids) as $q) {
            $keys = array_map('strval', array_keys($q->options ?? []));
            $orders[(string) $q->id] = $q->shuffle_options ? $this->picker->shuffle($keys) : $keys;
        }

        $now = now();
        $attempt = ExamAttempt::create([
            'exam_id' => $exam->id,
            'user_id' => $user->id,
            'status' => ExamAttempt::STATUS_IN_PROGRESS,
            'started_at' => $now,
            'expires_at' => $now->copy()->addMinutes(max(1, $exam->duration_minutes)),
            'question_ids' => $ids,
            'option_orders' => $orders,
            'answers' => [],
            'flagged' => [],
            'total_questions' => count($ids),
            'passing_score' => $exam->passing_score,
        ]);

        ActivityLog::log('exam_started', $attempt, [
            'exam' => $exam->code,
            'questions' => count($ids),
            'minutes' => $exam->duration_minutes,
        ]);

        return $attempt;
    }

    /**
     * Saves the answer and review flag for one question.
     *
     * @param  list<string>  $keys
     * @return bool false when the time had already run out (and the attempt
     *              has now been graded)
     */
    public function saveAnswer(ExamAttempt $attempt, ExamQuestion $question, array $keys, bool $flagged): bool
    {
        if (! $attempt->isInProgress() || $this->expireIfDue($attempt)) {
            return false;
        }

        if (! in_array($question->id, array_map('intval', $attempt->question_ids ?? []), true)) {
            throw new RuntimeException('That question is not part of this attempt.');
        }

        $valid = array_map('strval', array_keys($question->options ?? []));
        $keys = array_values(array_unique(array_intersect(array_map('strval', $keys), $valid)));
        if (! $question->isMultiple()) {
            $keys = array_slice($keys, 0, 1);
        }

        $answers = $attempt->answers ?? [];
        if ($keys === []) {
            unset($answers[(string) $question->id]);
        } else {
            $answers[(string) $question->id] = $keys;
        }

        $flags = array_values(array_diff(array_map('intval', $attempt->flagged ?? []), [$question->id]));
        if ($flagged) {
            $flags[] = $question->id;
        }

        $attempt->forceFill(['answers' => $answers, 'flagged' => $flags])->save();

        return true;
    }

    /** Grades the attempt if its time is up. True when it did. */
    public function expireIfDue(ExamAttempt $attempt): bool
    {
        if ($attempt->isInProgress() && $attempt->hasTimedOut()) {
            $this->finish($attempt, expired: true);

            return true;
        }

        return false;
    }

    /**
     * Grades and closes the attempt. Safe to call twice (a double-clicked
     * Finish, or the countdown firing as the candidate clicks): only the
     * first call grades.
     */
    public function finish(ExamAttempt $attempt, bool $expired = false): ExamAttempt
    {
        return DB::transaction(function () use ($attempt, $expired) {
            $fresh = ExamAttempt::whereKey($attempt->id)->lockForUpdate()->first();
            if (! $fresh || ! $fresh->isInProgress()) {
                $attempt->refresh();

                return $attempt;
            }

            $questions = ExamQuestion::whereIn('id', $fresh->question_ids ?? [])
                ->get(['id', 'domain', 'answer'])
                ->mapWithKeys(fn ($q) => [$q->id => ['domain' => $q->domain, 'answer' => $q->answer ?? []]])
                ->all();

            $graded = $this->grader->grade($fresh->question_ids ?? [], $questions, $fresh->answers ?? [], $fresh->passing_score);

            $now = now();
            $fresh->forceFill([
                'status' => $expired ? ExamAttempt::STATUS_EXPIRED : ExamAttempt::STATUS_SUBMITTED,
                'submitted_at' => $expired && $now->greaterThan($fresh->expires_at) ? $fresh->expires_at : $now,
                'total_questions' => $graded['total'],
                'correct_count' => $graded['correct'],
                'score' => $graded['score'],
                'passed' => $graded['passed'],
                'results' => $graded['results'],
                'domain_results' => $graded['domains'],
            ])->save();

            ActivityLog::log('exam_finished', $fresh, [
                'exam' => $fresh->exam?->code,
                'score' => $graded['score'],
                'correct' => $graded['correct'],
                'total' => $graded['total'],
                'passed' => $graded['passed'],
                'timed_out' => $expired,
            ]);

            $attempt->setRawAttributes($fresh->getAttributes(), true);

            return $attempt;
        });
    }
}
