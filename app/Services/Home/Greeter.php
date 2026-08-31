<?php

namespace App\Services\Home;

use App\Models\GreetingLine;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Builds the "Good morning, Mohamed" line and its warm sub-line.
 *
 * The sub-line is picked from `greeting_lines` so IT can reword it without a
 * deploy, and falls back to a built-in set when that table is empty or missing.
 * A blank greeting on the page the whole company opens is not an acceptable
 * failure mode, so every path here returns something.
 *
 * The chosen line is stable for a given person for a given hour rather than
 * random per request — otherwise it would flicker to a different sentence on
 * every refresh, which reads as broken.
 */
class Greeter
{
    /** Used when `greeting_lines` is empty. */
    private const FALLBACK = [
        'morning' => [
            'Hope your day starts smoothly.',
            'A fresh start — make it a good one.',
            'Coffee first, then everything else.',
        ],
        'afternoon' => [
            'Hope the day is treating you well.',
            'Halfway there — keep it going.',
            'Good afternoon from all of us at Samir Group.',
        ],
        'evening' => [
            'Thanks for everything you did today.',
            'Winding down — nicely done today.',
            'Hope you finish the day on a high note.',
        ],
    ];

    /**
     * @return array{greeting:string, name:string, line:string, line_ar:?string, time_of_day:string}
     */
    public function for(?string $displayName): array
    {
        $now = now();
        $hour = (int) $now->format('G');

        $timeOfDay = match (true) {
            $hour < 12 => 'morning',
            $hour < 18 => 'afternoon',
            default => 'evening',
        };

        $greeting = match ($timeOfDay) {
            'morning' => 'Good morning',
            'afternoon' => 'Good afternoon',
            default => 'Good evening',
        };

        $first = $this->firstName($displayName);
        $line = $this->pickLine($timeOfDay, (int) $now->format('w'), $first, $hour);

        return [
            'greeting' => $greeting,
            'name' => $first,
            'line' => $line['text'],
            'line_ar' => $line['text_ar'],
            'time_of_day' => $timeOfDay,
        ];
    }

    /**
     * First name only — "Good morning, Mohamed" reads warmer than the full
     * three-part legal name most directories carry.
     */
    private function firstName(?string $displayName): string
    {
        $name = trim((string) $displayName);

        if ($name === '') {
            return 'there';
        }

        // "Zahran, Mohamed" — some directories store surname-first.
        if (str_contains($name, ',')) {
            $parts = array_map('trim', explode(',', $name));
            $name = $parts[1] ?? $parts[0];
        }

        $first = trim(explode(' ', trim($name))[0] ?? '');

        return $first !== '' ? $first : 'there';
    }

    /**
     * @return array{text:string, text_ar:?string}
     */
    private function pickLine(string $timeOfDay, int $dayOfWeek, string $seedName, int $hour): array
    {
        $pool = $this->pool($timeOfDay, $dayOfWeek);

        if ($pool === []) {
            $fallback = self::FALLBACK[$timeOfDay] ?? self::FALLBACK['morning'];
            $pool = array_map(fn ($t) => ['text' => $t, 'text_ar' => null], $fallback);
        }

        // Stable per person per hour: same sentence on a refresh, a different
        // one tomorrow. crc32 over name+date+hour is plenty here.
        $seed = crc32($seedName.'|'.now()->format('Y-m-d').'|'.$hour);

        return $pool[$seed % count($pool)];
    }

    /**
     * @return array<int, array{text:string, text_ar:?string}>
     */
    private function pool(string $timeOfDay, int $dayOfWeek): array
    {
        try {
            if (! Schema::hasTable('greeting_lines')) {
                return [];
            }

            return Cache::remember(
                "home.greeting_lines.{$timeOfDay}.{$dayOfWeek}",
                now()->addMinutes(15),
                fn () => GreetingLine::eligible($timeOfDay, $dayOfWeek)
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->get(['text', 'text_ar'])
                    ->map(fn ($l) => ['text' => $l->text, 'text_ar' => $l->text_ar])
                    ->all()
            );
        } catch (\Throwable) {
            // Table missing, cache store unavailable, migration mid-flight —
            // the caller falls back to the built-in lines.
            return [];
        }
    }
}
