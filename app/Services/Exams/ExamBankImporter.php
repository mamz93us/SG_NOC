<?php

namespace App\Services\Exams;

use App\Models\ActivityLog;
use App\Models\Exams\Exam;
use App\Models\Exams\ExamQuestion;
use App\Support\Audit\Auditor;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Loads a question bank — the bundled files in database/data/exams, or a JSON
 * file uploaded on the Question bank page — into an exam. Same format both
 * ways:
 *
 *   {"exam": {"code": "AZ-900", "title": …, "duration_minutes": 45,
 *             "question_count": 45, "passing_score": 700},
 *    "questions": [{"uid": "az900-001", "domain": …, "type": "single",
 *                   "question": …, "options": {"A": …}, "answer": ["B"],
 *                   "explanation": …, "reference": …, "shuffle": false}]}
 *
 * Idempotent on (exam, uid): loading a bank again updates its questions'
 * wording and answers and adds new ones. It never deletes a question and
 * never re-enables one a manager switched off, and an exam that already
 * exists keeps its own duration, length and pass mark.
 */
class ExamBankImporter
{
    /** @return list<string> the bundled bank files */
    public static function bundledFiles(): array
    {
        $files = glob(database_path('data/exams/*.json')) ?: [];
        sort($files);

        return $files;
    }

    /** @return array{exam: Exam, created: int, updated: int, unchanged: int} */
    public function importFile(string $path): array
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new InvalidArgumentException('Could not read '.basename($path).'.');
        }

        return $this->importJson($raw);
    }

    /** @return array{exam: Exam, created: int, updated: int, unchanged: int} */
    public function importJson(string $json): array
    {
        $data = json_decode($json, true);
        if (! is_array($data)) {
            throw new InvalidArgumentException('The file is not valid JSON: '.json_last_error_msg().'.');
        }

        return $this->import($data);
    }

    /** @return array{exam: Exam, created: int, updated: int, unchanged: int} */
    public function import(array $data): array
    {
        [$examData, $questions] = $this->validate($data);

        $result = DB::transaction(fn () => Auditor::withoutAuditing(function () use ($examData, $questions) {
            $exam = Exam::where('code', $examData['code'])->first();
            if (! $exam) {
                $exam = Exam::create($examData);
            } elseif (($examData['description'] ?? null) && ! $exam->description) {
                $exam->update(['description' => $examData['description']]);
            }

            $existing = $exam->questions()->whereNotNull('uid')->get()->keyBy('uid');
            $created = $updated = $unchanged = 0;

            foreach ($questions as $q) {
                $row = $existing->get($q['uid']);
                if (! $row) {
                    $exam->questions()->create($q);
                    $created++;

                    continue;
                }

                $row->fill($q);
                if ($row->isDirty()) {
                    $row->save();
                    $updated++;
                } else {
                    $unchanged++;
                }
            }

            return ['exam' => $exam, 'created' => $created, 'updated' => $updated, 'unchanged' => $unchanged];
        }));

        ActivityLog::log('exam_bank_imported', $result['exam'], [
            'exam' => $result['exam']->code,
            'created' => $result['created'],
            'updated' => $result['updated'],
            'unchanged' => $result['unchanged'],
        ]);

        return $result;
    }

    /**
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>}
     */
    private function validate(array $data): array
    {
        $exam = $data['exam'] ?? null;
        if (! is_array($exam) || blank($exam['code'] ?? null) || blank($exam['title'] ?? null)) {
            throw new InvalidArgumentException('The file needs an "exam" object with a "code" and a "title".');
        }

        $examData = [
            'code' => strtoupper(trim((string) $exam['code'])),
            'title' => trim((string) $exam['title']),
            'description' => isset($exam['description']) ? trim((string) $exam['description']) : null,
            'duration_minutes' => max(1, (int) ($exam['duration_minutes'] ?? 45)),
            'question_count' => max(0, (int) ($exam['question_count'] ?? 45)),
            'passing_score' => min(1000, max(1, (int) ($exam['passing_score'] ?? 700))),
        ];

        $list = $data['questions'] ?? null;
        if (! is_array($list) || $list === []) {
            throw new InvalidArgumentException('The file needs a non-empty "questions" list.');
        }

        $errors = [];
        $seen = [];
        $questions = [];
        foreach (array_values($list) as $i => $q) {
            $n = $i + 1;
            $uid = trim((string) ($q['uid'] ?? ''));
            $options = $q['options'] ?? null;
            $answer = $q['answer'] ?? null;
            $type = $q['type'] ?? ExamQuestion::TYPE_SINGLE;

            if ($uid === '' || strlen($uid) > 64) {
                $errors[] = "Question {$n}: \"uid\" is missing or longer than 64 characters.";
            } elseif (isset($seen[$uid])) {
                $errors[] = "Question {$n}: uid \"{$uid}\" is used twice.";
            }
            $seen[$uid] = true;

            if (blank($q['question'] ?? null) || blank($q['domain'] ?? null)) {
                $errors[] = "Question {$n} ({$uid}): \"question\" and \"domain\" are required.";
            }
            if (! in_array($type, [ExamQuestion::TYPE_SINGLE, ExamQuestion::TYPE_MULTIPLE], true)) {
                $errors[] = "Question {$n} ({$uid}): type must be \"single\" or \"multiple\".";
            }
            if (! is_array($options) || count($options) < 2 || array_is_list($options)) {
                $errors[] = "Question {$n} ({$uid}): \"options\" must be an object of at least two, like {\"A\": \"…\", \"B\": \"…\"}.";

                continue;
            }
            if (! is_array($answer) || $answer === [] || array_diff($answer, array_map('strval', array_keys($options)))) {
                $errors[] = "Question {$n} ({$uid}): \"answer\" must list keys that are in \"options\".";

                continue;
            }
            if ($type === ExamQuestion::TYPE_SINGLE && count($answer) !== 1) {
                $errors[] = "Question {$n} ({$uid}): a single-answer question has exactly one key in \"answer\".";
            }

            $questions[] = [
                'uid' => $uid,
                'domain' => trim((string) $q['domain']),
                'type' => $type,
                'question' => trim((string) $q['question']),
                'options' => array_map(fn ($t) => trim((string) $t), $options),
                'answer' => array_values(array_map('strval', $answer)),
                'explanation' => isset($q['explanation']) ? trim((string) $q['explanation']) : null,
                'reference' => isset($q['reference']) ? mb_substr(trim((string) $q['reference']), 0, 500) : null,
                'shuffle_options' => (bool) ($q['shuffle'] ?? true),
            ];
        }

        if ($errors) {
            throw new InvalidArgumentException(implode("\n", array_slice($errors, 0, 20)).(count($errors) > 20 ? "\n…and ".(count($errors) - 20).' more.' : ''));
        }

        return [$examData, $questions];
    }
}
