<?php

namespace App\Services\Recruitment;

use App\Models\Recruitment\RecruitmentScreening;
use RuntimeException;

/**
 * The model's evaluation of one CV, checked and normalised before it is stored.
 * Pure, so every way a reply can be wrong is tested without Azure.
 *
 * Two things are decided here rather than trusted to the model: the fit label
 * comes from the score's band, and a must-have the CV clearly does not meet
 * caps the score, so a candidate missing one never ranks above those who meet
 * them all.
 *
 * Where the applicant lives is kept apart from the evaluation, in `location`:
 * the runner stores it with their salary, encrypted.
 */
final class ScreeningResult
{
    /** The highest score an applicant with a must-have marked "no" can keep. */
    public const CAP_WHEN_MISSING = 49;

    /** Further than any trip to an office; a bigger number is a mistake. */
    private const MAX_DISTANCE_KM = 20000;

    /**
     * @param  array<string,mixed>  $evaluation
     * @param  array{lives_in: ?string, distance_km: ?int, relocation_needed: ?string}  $location
     */
    public function __construct(
        public readonly int $score,
        public readonly string $fit,
        public readonly int $mustHavesMet,
        public readonly int $mustHavesTotal,
        public readonly array $evaluation,
        public readonly array $location = ['lives_in' => null, 'distance_km' => null, 'relocation_needed' => null],
    ) {}

    /**
     * @param  list<string>  $mustHaves  the recruiter's must-haves, in the order they were given
     *
     * @throws RuntimeException when the reply was cut off, blocked, or is not the expected JSON
     */
    public static function parse(mixed $content, ?string $finishReason, array $mustHaves): self
    {
        if ($finishReason === 'length') {
            throw new RuntimeException('The evaluation was cut off before it was finished.');
        }

        if ($finishReason === 'content_filter') {
            throw new RuntimeException("Azure OpenAI's content filter blocked this CV.");
        }

        $data = is_string($content) ? json_decode($content, true) : null;

        if (! is_array($data) || ! isset($data['score']) || ! is_numeric($data['score'])) {
            throw new RuntimeException('The evaluation did not come back as the expected JSON.');
        }

        $score = max(0, min(100, (int) round((float) $data['score'])));
        $checks = self::mustHaveChecks($data['must_haves'] ?? null, $mustHaves);

        $met = count(array_filter($checks, fn (array $check) => $check['met'] === 'yes'));
        $missing = count(array_filter($checks, fn (array $check) => $check['met'] === 'no'));

        if ($missing > 0) {
            $score = min($score, self::CAP_WHEN_MISSING);
        }

        $years = $data['relevant_years'] ?? null;

        return new self($score, RecruitmentScreening::fitFor($score), $met, count($checks), [
            'summary' => self::text($data['summary'] ?? null, 800) ?? '',
            'current_role' => self::text($data['current_role'] ?? null, 200),
            'relevant_years' => is_numeric($years) ? max(0.0, round((float) $years, 1)) : null,
            'must_haves' => $checks,
            'strengths' => self::items($data['strengths'] ?? null, 5),
            'concerns' => self::items($data['concerns'] ?? null, 5),
            'skills' => self::items($data['skills'] ?? null, 12, 60),
            'languages' => self::items($data['languages'] ?? null, 6, 40),
            'education' => self::text($data['education'] ?? null, 200),
            'interview_questions' => self::items($data['interview_questions'] ?? null, 4, 300),
        ], self::location($data));
    }

    /**
     * One check per must-have the recruiter gave. The model's entry is matched
     * by the requirement's wording first, then by position, so a reply that
     * reorders them cannot pin one requirement's evidence on another.
     *
     * @param  list<string>  $mustHaves
     * @return list<array{requirement:string, met:string, evidence:?string}>
     */
    private static function mustHaveChecks(mixed $given, array $mustHaves): array
    {
        $given = is_array($given) ? array_values(array_filter($given, 'is_array')) : [];

        $byWording = [];
        foreach ($given as $entry) {
            $byWording[mb_strtolower(trim((string) ($entry['requirement'] ?? '')))] ??= $entry;
        }

        $checks = [];

        foreach ($mustHaves as $i => $requirement) {
            $entry = $byWording[mb_strtolower(trim($requirement))] ?? $given[$i] ?? [];
            $met = strtolower(trim((string) ($entry['met'] ?? '')));

            $checks[] = [
                'requirement' => $requirement,
                'met' => in_array($met, ['yes', 'no', 'unclear'], true) ? $met : 'unclear',
                'evidence' => self::text($entry['evidence'] ?? null, 300),
            ];
        }

        return $checks;
    }

    /**
     * Where the applicant lives, the estimated distance to the office, and
     * whether they would move city. A distance with no place it was measured
     * from is the reply template's 0, not an estimate, so it is dropped.
     *
     * @param  array<string,mixed>  $data
     * @return array{lives_in: ?string, distance_km: ?int, relocation_needed: ?string}
     */
    private static function location(array $data): array
    {
        $livesIn = self::text($data['lives_in'] ?? null, 120);
        $km = $data['distance_km'] ?? null;

        if (is_string($km) && preg_match('/\d+(?:\.\d+)?/', $km, $match) === 1) {
            $km = $match[0];
        }

        $relocation = is_string($data['relocation_needed'] ?? null) ? strtolower(trim($data['relocation_needed'])) : null;

        return [
            'lives_in' => $livesIn,
            'distance_km' => $livesIn !== null && is_numeric($km) ? max(0, min(self::MAX_DISTANCE_KM, (int) round((float) $km))) : null,
            'relocation_needed' => in_array($relocation, ['yes', 'no', 'unclear'], true) ? $relocation : null,
        ];
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' || strtolower($text) === 'null' ? null : mb_substr($text, 0, $max);
    }

    /** @return list<string> */
    private static function items(mixed $value, int $max, int $itemMax = 240): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = array_values(array_filter(array_map(fn ($item) => self::text($item, $itemMax), $value)));

        return array_slice(array_values(array_unique($items)), 0, $max);
    }
}
