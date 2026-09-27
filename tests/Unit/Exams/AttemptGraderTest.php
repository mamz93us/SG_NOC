<?php

use App\Services\Exams\AttemptGrader;
use App\Services\Exams\QuestionPicker;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * The scoring and the draw, both pure: a 1–1000 score with all-or-nothing
 * multiple-answer questions, and a draw weighted by skill area.
 */
function gradeQuestions(): array
{
    return [
        1 => ['domain' => 'Cloud concepts', 'answer' => ['B']],
        2 => ['domain' => 'Cloud concepts', 'answer' => ['A', 'C']],
        3 => ['domain' => 'Governance', 'answer' => ['D']],
        4 => ['domain' => 'Governance', 'answer' => ['A']],
    ];
}

it('scores on the 1000 scale and passes at the mark', function () {
    $r = (new AttemptGrader)->grade([1, 2, 3, 4], gradeQuestions(), [
        1 => ['B'], 2 => ['C', 'A'], 3 => ['D'], 4 => ['B'],
    ], 700);

    expect($r['correct'])->toBe(3)
        ->and($r['total'])->toBe(4)
        ->and($r['score'])->toBe(750)
        ->and($r['passed'])->toBeTrue()
        ->and($r['results'])->toBe(['1' => true, '2' => true, '3' => true, '4' => false]);

    $r = (new AttemptGrader)->grade([1, 2, 3, 4], gradeQuestions(), [1 => ['B'], 2 => ['A', 'C']], 700);
    expect($r['score'])->toBe(500)->and($r['passed'])->toBeFalse();
});

it('gives nothing for a multiple-answer question half right, over-picked or under-picked', function () {
    $q = gradeQuestions();

    foreach ([['A'], ['A', 'B'], ['A', 'B', 'C'], []] as $chosen) {
        expect((new AttemptGrader)->grade([2], $q, [2 => $chosen], 700)['correct'])->toBe(0);
    }
    expect(AttemptGrader::isCorrect(['A', 'C'], ['c', 'a']))->toBeTrue()
        ->and(AttemptGrader::isCorrect([], []))->toBeFalse();
});

it('reports each skill area and leaves out a question deleted before grading', function () {
    $q = gradeQuestions();
    unset($q[4]);

    $r = (new AttemptGrader)->grade([1, 2, 3, 4], $q, [1 => ['B'], 3 => ['A'], 4 => ['A']], 700);

    expect($r['total'])->toBe(3)
        ->and($r['correct'])->toBe(1)
        ->and($r['score'])->toBe(333)
        ->and($r['domains'])->toBe([
            ['domain' => 'Cloud concepts', 'correct' => 1, 'total' => 2, 'percent' => 50],
            ['domain' => 'Governance', 'correct' => 0, 'total' => 1, 'percent' => 0],
        ]);
});

it('scores an attempt with no gradable questions as a fail, not a division by zero', function () {
    $r = (new AttemptGrader)->grade([9], gradeQuestions(), [], 700);

    expect($r['score'])->toBe(0)->and($r['passed'])->toBeFalse();
});

it('draws each skill area in proportion to the bank and never repeats a question', function () {
    // 28 / 38 / 34 — the AZ-900 bank's split.
    $domains = [];
    foreach (['Cloud' => 28, 'Architecture' => 38, 'Governance' => 34] as $domain => $n) {
        for ($i = 0; $i < $n; $i++) {
            $domains[count($domains) + 1] = $domain;
        }
    }

    $picker = new QuestionPicker(new Randomizer(new Mt19937(42)));
    $ids = $picker->pick($domains, 45);

    $split = array_count_values(array_map(fn ($id) => $domains[$id], $ids));
    ksort($split);

    expect($ids)->toHaveCount(45)
        ->and(array_unique($ids))->toHaveCount(45)
        ->and($split)->toBe(['Architecture' => 17, 'Cloud' => 13, 'Governance' => 15]);
});

it('hands out the whole bank, shuffled, when it asks for as many or more', function () {
    $domains = [1 => 'A', 2 => 'A', 3 => 'B'];
    $ids = (new QuestionPicker)->pick($domains, 10);

    sort($ids);
    expect($ids)->toBe([1, 2, 3])
        ->and((new QuestionPicker)->pick($domains, 0))->toHaveCount(3);
});
