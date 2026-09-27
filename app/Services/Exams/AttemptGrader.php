<?php

namespace App\Services\Exams;

/**
 * Scores an attempt, Microsoft-style: 1–1000 with the pass mark on the exam
 * (700). Pure — it is given the questions and the answers and touches
 * nothing.
 *
 * A question is right only when the chosen keys are exactly the correct
 * keys: a "choose two" with one right and one wrong earns nothing, and so
 * does one with only one of the two picked. Microsoft's real scale is not
 * published and is not linear; this one is, which is the honest choice for
 * practice.
 *
 * A question deleted from the bank before grading is left out of the total
 * rather than counted wrong — the candidate never had a fair chance at it.
 */
final class AttemptGrader
{
    public const MAX_SCORE = 1000;

    /**
     * @param  list<int>  $questionIds  in delivery order
     * @param  array<int, array{domain: string, answer: list<string>}>  $questions
     * @param  array<string|int, list<string>>  $answers  question id => chosen keys
     * @return array{total: int, correct: int, score: int, passed: bool, results: array<string, bool>, domains: list<array{domain: string, correct: int, total: int, percent: int}>}
     */
    public function grade(array $questionIds, array $questions, array $answers, int $passingScore): array
    {
        $results = [];
        $domains = [];
        $correct = 0;

        foreach ($questionIds as $id) {
            $id = (int) $id;
            if (! isset($questions[$id])) {
                continue;
            }

            $right = self::isCorrect($questions[$id]['answer'], $answers[$id] ?? $answers[(string) $id] ?? []);
            $results[(string) $id] = $right;
            $correct += $right ? 1 : 0;

            $domain = $questions[$id]['domain'];
            $domains[$domain] ??= ['domain' => $domain, 'correct' => 0, 'total' => 0];
            $domains[$domain]['total']++;
            $domains[$domain]['correct'] += $right ? 1 : 0;
        }

        $total = count($results);
        $score = $total > 0 ? (int) round($correct * self::MAX_SCORE / $total) : 0;

        $domainRows = array_values(array_map(fn ($d) => $d + [
            'percent' => (int) round($d['correct'] * 100 / max(1, $d['total'])),
        ], $domains));
        usort($domainRows, fn ($a, $b) => strcmp($a['domain'], $b['domain']));

        return [
            'total' => $total,
            'correct' => $correct,
            'score' => $score,
            'passed' => $total > 0 && $score >= $passingScore,
            'results' => $results,
            'domains' => $domainRows,
        ];
    }

    /**
     * @param  list<string>  $correct
     * @param  list<string>  $chosen
     */
    public static function isCorrect(array $correct, array $chosen): bool
    {
        $normalise = function (array $keys): array {
            $keys = array_values(array_unique(array_map(fn ($k) => strtoupper(trim((string) $k)), $keys)));
            sort($keys);

            return $keys;
        };

        $correct = $normalise($correct);

        return $correct !== [] && $correct === $normalise($chosen);
    }
}
