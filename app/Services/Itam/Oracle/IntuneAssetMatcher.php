<?php

namespace App\Services\Itam\Oracle;

/**
 * Pairs one person's Oracle laptops and desktops with that person's Intune
 * devices. Pure: no database, so every rule below is unit-tested.
 *
 * A pair is scored on what the two sides share:
 *
 *   same serial            decides on its own
 *   brands differ          never a pair
 *   model keys differ      never a pair (a 20TA ThinkPad is not a 21KE one)
 *   same model keys        +50   strong
 *   same model code        +20   strong
 *   same CPU               +35   strong
 *   different CPU          −40   and no longer strong (an i5 and an i7 of one model)
 *   same brand             +10
 *   enrolled 45 days before purchase to 90 days after   +20
 *   enrolled 45–120 days before purchase                −15
 *   enrolled more than 120 days before purchase         −50   and no longer strong
 *
 * Links are made in two passes. First the strong pairs of 50 points or more,
 * best first, each side used once. Then what is left is paired only where it
 * cannot be anything else: the person's one remaining Oracle computer that
 * could be this Intune device, and the one remaining Intune device that could
 * be it, of the same known brand, bought at most four years before it was
 * enrolled and not enrolled before it was bought. Everything else stays
 * unlinked, with its closest candidates kept for a person to look at — a
 * wrong link is worse than none, because nobody looks at a laptop that shows
 * as linked.
 */
final class IntuneAssetMatcher
{
    public const METHOD_SERIAL = 'serial';

    public const METHOD_MODEL = 'model';

    public const METHOD_ONLY_PAIR = 'only_pair';

    private const STRONG_THRESHOLD = 50;

    private const ONLY_PAIR_MIN_POINTS = 10;

    private const ONLY_PAIR_MAX_AGE_YEARS = 4;

    private const SUGGESTIONS_KEPT = 3;

    /**
     * The pair's score, or null when the two cannot be the same machine.
     *
     * @return array{points: int, strong: bool, serial: bool, days: ?int, brand: ?bool, reasons: list<string>}|null
     */
    public function score(ComputerFacts $oracle, ComputerFacts $intune): ?array
    {
        $days = ($oracle->date && $intune->date) ? (int) $oracle->date->diffInDays($intune->date, false) : null;

        if ($oracle->serials !== [] && $intune->serials !== [] && array_intersect($oracle->serials, $intune->serials) !== []) {
            return ['points' => 100, 'strong' => true, 'serial' => true, 'days' => $days, 'brand' => true, 'reasons' => ['same serial number']];
        }

        $brand = ComputerFacts::brandsAgree($oracle->brand, $intune->brand);

        if ($brand === false) {
            return null;
        }

        $points = 0;
        $strong = false;
        $reasons = [];

        if ($brand === true) {
            $points += 10;
            $reasons[] = 'same brand';
        }

        if ($oracle->modelKeys !== [] && $intune->modelKeys !== []) {
            if (array_intersect($oracle->modelKeys, $intune->modelKeys) === []) {
                return null;
            }

            $points += 50;
            $strong = true;
            $reasons[] = 'same model';
        }

        if ($oracle->looseKeys !== [] && array_intersect($oracle->looseKeys, $intune->looseKeys) !== []) {
            $points += 20;
            $strong = true;
            $reasons[] = 'same model code';
        }

        $cpu = ComputerFacts::cpusAgree($oracle->cpu, $intune->cpu);

        if ($cpu === true) {
            $points += 35;
            $strong = true;
            $reasons[] = 'same CPU';
        } elseif ($cpu === false) {
            $points -= 40;
            $strong = false;
            $reasons[] = 'different CPU';
        }

        if ($days !== null) {
            if ($days < -120) {
                $points -= 50;
                $strong = false;
                $reasons[] = 'in Intune long before Oracle bought it';
            } elseif ($days < -45) {
                $points -= 15;
                $reasons[] = 'in Intune before Oracle bought it';
            } elseif ($days <= 90) {
                $points += 20;
                $reasons[] = 'enrolled within 3 months of purchase';
            }
        }

        return ['points' => $points, 'strong' => $strong, 'serial' => false, 'days' => $days, 'brand' => $brand, 'reasons' => $reasons];
    }

    /**
     * @param  array<int|string, ComputerFacts>  $oracle  the person's Oracle computers without a NOC asset yet
     * @param  array<int|string, ComputerFacts>  $intune  the person's Intune devices no Oracle unit holds yet
     * @return array{
     *     links: array<int|string, array{candidate: int|string, method: string, points: int, reasons: list<string>}>,
     *     suggestions: array<int|string, list<array{candidate: int|string, points: int, reasons: list<string>}>>
     * }
     */
    public function assign(array $oracle, array $intune): array
    {
        $pairs = [];

        foreach ($oracle as $o => $oracleFacts) {
            foreach ($intune as $i => $intuneFacts) {
                $score = $this->score($oracleFacts, $intuneFacts);

                if ($score !== null) {
                    $pairs[] = ['o' => $o, 'i' => $i] + $score;
                }
            }
        }

        // Serial first, then strong, then points, then the enrollment nearest the purchase.
        usort($pairs, fn (array $a, array $b) => [$b['serial'], $b['strong'], $b['points'], abs($a['days'] ?? PHP_INT_MAX)]
            <=> [$a['serial'], $a['strong'], $a['points'], abs($b['days'] ?? PHP_INT_MAX)]);

        $links = [];
        $takenOracle = [];
        $takenIntune = [];

        foreach ($pairs as $pair) {
            if (! $pair['serial'] && (! $pair['strong'] || $pair['points'] < self::STRONG_THRESHOLD)) {
                continue;
            }
            if (isset($takenOracle[$pair['o']]) || isset($takenIntune[$pair['i']])) {
                continue;
            }

            $links[$pair['o']] = [
                'candidate' => $pair['i'],
                'method' => $pair['serial'] ? self::METHOD_SERIAL : self::METHOD_MODEL,
                'points' => $pair['points'],
                'reasons' => $pair['reasons'],
            ];
            $takenOracle[$pair['o']] = true;
            $takenIntune[$pair['i']] = true;
        }

        $open = array_values(array_filter($pairs, fn (array $pair) => ! isset($takenOracle[$pair['o']])
            && ! isset($takenIntune[$pair['i']])
            && $pair['points'] >= self::ONLY_PAIR_MIN_POINTS));

        $perOracle = [];
        $perIntune = [];

        foreach ($open as $pair) {
            $perOracle[$pair['o']][] = $pair;
            $perIntune[$pair['i']][] = $pair;
        }

        foreach ($open as $pair) {
            if (count($perOracle[$pair['o']]) !== 1 || count($perIntune[$pair['i']]) !== 1) {
                continue;
            }
            if ($pair['brand'] !== true || ! $this->plausibleAge($oracle[$pair['o']], $intune[$pair['i']])) {
                continue;
            }

            $links[$pair['o']] = [
                'candidate' => $pair['i'],
                'method' => self::METHOD_ONLY_PAIR,
                'points' => $pair['points'],
                'reasons' => array_merge($pair['reasons'], ['the only one of that brand on both sides']),
            ];
            $takenOracle[$pair['o']] = true;
            $takenIntune[$pair['i']] = true;
        }

        $suggestions = [];

        foreach ($pairs as $pair) {
            if (isset($takenOracle[$pair['o']]) || $pair['points'] < 0) {
                continue;
            }
            if (count($suggestions[$pair['o']] ?? []) >= self::SUGGESTIONS_KEPT) {
                continue;
            }

            $suggestions[$pair['o']][] = ['candidate' => $pair['i'], 'points' => $pair['points'], 'reasons' => $pair['reasons']];
        }

        return ['links' => $links, 'suggestions' => $suggestions];
    }

    /** Bought no more than four years before Intune enrolled it, and not enrolled before it was bought. */
    private function plausibleAge(ComputerFacts $oracle, ComputerFacts $intune): bool
    {
        if (! $oracle->date || ! $intune->date) {
            return true;
        }

        return $intune->date->greaterThanOrEqualTo($oracle->date->subDays(45))
            && $oracle->date->greaterThanOrEqualTo($intune->date->subYears(self::ONLY_PAIR_MAX_AGE_YEARS));
    }
}
