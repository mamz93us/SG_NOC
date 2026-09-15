<?php

use App\Services\Teamtailor\CandidateProfileReader;
use App\Services\Teamtailor\TeamtailorAnswers;

function ttAnswer(array $attributes, ?string $questionId = null, ?string $pickedId = null): array
{
    return ['id' => 'a1', 'type' => 'answers', 'attributes' => $attributes, 'relationships' => [
        'question' => ['data' => $questionId !== null ? ['type' => 'questions', 'id' => $questionId] : null],
        'picked-question' => ['data' => $pickedId !== null ? ['type' => 'picked-questions', 'id' => $pickedId] : null],
    ]];
}

it('names a choice answer by the titles of the alternatives it picked', function () {
    $included = CandidateProfileReader::index([
        ['id' => 'q1', 'type' => 'questions', 'attributes' => [
            'title' => 'Which cities could you work in?',
            'alternatives' => [['id' => 11, 'title' => 'Jeddah'], ['id' => 12, 'title' => 'Riyadh'], ['id' => 13, 'title' => 'Cairo']],
        ]],
    ]);

    $described = TeamtailorAnswers::describe(ttAnswer(['question-type' => 'choice', 'choices' => [13, 11], 'answer' => [13, 11]], 'q1', 'pq1'), $included);

    expect($described)->toBe(['question' => 'Which cities could you work in?', 'answer' => 'Cairo, Jeddah', 'picked_question_id' => 'pq1']);
});

it('reads each question type from the field that holds it', function (array $attributes, array $question, string $expected) {
    expect(TeamtailorAnswers::value($attributes, $question))->toBe($expected);
})->with([
    'boolean yes, not the string copy' => [['question-type' => 'boolean', 'boolean' => true, 'answer' => 'true'], [], 'Yes'],
    'boolean no' => [['question-type' => 'boolean', 'boolean' => false, 'answer' => 'false'], [], 'No'],
    'number with its unit' => [['question-type' => 'number', 'number' => 9000, 'answer' => 9000], ['unit' => 'SAR'], '9000 SAR'],
    'range with its unit' => [['question-type' => 'range', 'range' => 5, 'answer' => '5'], ['unit' => 'years'], '5 years'],
    'text' => [['question-type' => 'text', 'text' => 'One month', 'answer' => 'One month'], [], 'One month'],
    'choice with no alternatives to name it uses the answer text' => [['question-type' => 'choice', 'choices' => [5], 'answer' => ['Remote']], [], 'Remote'],
    'choice with nothing to name it keeps the id' => [['question-type' => 'choice', 'choices' => [5], 'answer' => [5]], [], '5'],
    'untyped boolean' => [['boolean' => true], [], 'Yes'],
]);

it('skips an answer with nothing in it', function () {
    expect(TeamtailorAnswers::describe(ttAnswer(['question-type' => 'text', 'text' => '  ', 'answer' => null]), collect()))->toBeNull();
});
