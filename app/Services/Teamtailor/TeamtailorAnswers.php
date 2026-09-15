<?php

namespace App\Services\Teamtailor;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/**
 * One Teamtailor application answer as text, with its question. Used by the
 * candidate profile and by Recruitment AI's screening.
 *
 * Where the value is depends on the question type (checked against the live
 * account on 2026-09-15):
 * - text: `text` (and the same in `answer`);
 * - boolean: `boolean`, with a string copy in `answer`;
 * - number: `number` (and `answer`); range: `range`, with a string `answer`;
 * - choice: `choices`, a list of ids of the question's `alternatives`
 *   ({id, title}) — 24 of 24 matched by id, so they are named by title.
 *
 * The question's id is on an answer only when it was fetched with
 * include=answers.question; with include=answers,questions it is a link.
 */
final class TeamtailorAnswers
{
    /**
     * @param  array<string,mixed>  $answer  an answers resource
     * @param  Collection<string, array<string,mixed>>  $included  keyed "type:id", with the questions
     * @return array{question: ?string, answer: string, picked_question_id: ?string}|null null when nothing was answered
     */
    public static function describe(array $answer, Collection $included): ?array
    {
        $questionId = (string) Arr::get($answer, 'relationships.question.data.id', '');
        $question = $questionId !== '' ? ($included->get('questions:'.$questionId) ?? []) : [];

        $value = self::value($answer['attributes'] ?? [], $question['attributes'] ?? []);

        if ($value === '') {
            return null;
        }

        $title = trim((string) Arr::get($question, 'attributes.title', ''));
        $picked = Arr::get($answer, 'relationships.picked-question.data.id');

        return [
            'question' => $title !== '' ? $title : null,
            'answer' => $value,
            'picked_question_id' => $picked !== null && $picked !== '' ? (string) $picked : null,
        ];
    }

    /**
     * @param  array<string,mixed>  $attributes  the answer's attributes
     * @param  array<string,mixed>  $question  its question's attributes: choice titles and the unit
     */
    public static function value(array $attributes, array $question = []): string
    {
        $type = (string) ($attributes['question-type'] ?? $question['question-type'] ?? '');

        if ($type === 'boolean' && is_bool($attributes['boolean'] ?? null)) {
            return $attributes['boolean'] ? 'Yes' : 'No';
        }

        if (is_array($attributes['choices'] ?? null) && $attributes['choices'] !== []) {
            return self::choices($attributes['choices'], $question['alternatives'] ?? null, $attributes['answer'] ?? null);
        }

        foreach (['text', 'answer'] as $key) {
            $value = $attributes[$key] ?? null;

            if (is_scalar($value) && ! is_bool($value) && trim((string) $value) !== '') {
                return self::withUnit(trim((string) $value), $type, $question);
            }
        }

        if (is_bool($attributes['boolean'] ?? null)) {
            return $attributes['boolean'] ? 'Yes' : 'No';
        }

        foreach (['number', 'range'] as $key) {
            if (is_numeric($attributes[$key] ?? null)) {
                return self::withUnit((string) $attributes[$key], $type, $question);
            }
        }

        if (is_scalar($attributes['date'] ?? null) && trim((string) $attributes['date']) !== '') {
            return trim((string) $attributes['date']);
        }

        foreach (['answer', 'data'] as $key) {
            if (is_array($attributes[$key] ?? null)) {
                $parts = array_map(fn ($item) => is_array($item) ? trim((string) ($item['title'] ?? $item['text'] ?? '')) : trim((string) $item), $attributes[$key]);
                $parts = array_filter($parts, fn (string $part) => $part !== '');

                if ($parts !== []) {
                    return implode(', ', $parts);
                }
            }
        }

        return '';
    }

    /** Choice ids named by the question's alternatives; Teamtailor's own `answer` list when there are none to name them. */
    private static function choices(array $choices, mixed $alternatives, mixed $answer): string
    {
        $titles = [];

        foreach (is_array($alternatives) ? $alternatives : [] as $alternative) {
            if (is_array($alternative) && isset($alternative['id'])) {
                $titles[(string) $alternative['id']] = trim((string) ($alternative['title'] ?? $alternative['text'] ?? ''));
            }
        }

        if ($titles === [] && is_array($answer)) {
            $texts = array_filter(array_map(fn ($item) => is_string($item) && ! is_numeric($item) ? trim($item) : '', $answer));

            if ($texts !== []) {
                return implode(', ', $texts);
            }
        }

        $parts = array_map(function ($choice) use ($titles) {
            $id = is_array($choice) ? (string) ($choice['id'] ?? '') : (string) $choice;

            if (($titles[$id] ?? '') !== '') {
                return $titles[$id];
            }

            return is_array($choice) ? trim((string) ($choice['title'] ?? $choice['text'] ?? $id)) : $id;
        }, $choices);

        return implode(', ', array_filter($parts, fn (string $part) => $part !== ''));
    }

    private static function withUnit(string $value, string $type, array $question): string
    {
        $unit = is_scalar($question['unit'] ?? null) ? trim((string) $question['unit']) : '';

        return in_array($type, ['number', 'range'], true) && $unit !== '' && is_numeric($value) ? "{$value} {$unit}" : $value;
    }
}
