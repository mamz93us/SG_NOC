<?php

namespace App\Services\Exams;

use Random\Randomizer;

/**
 * Draws an attempt's questions from the bank the way the real exam weights
 * its skill areas: each domain gets a share of the draw in proportion to its
 * share of the bank (largest remainder, so the shares always add up to the
 * count), random within the domain, then the whole draw is shuffled.
 *
 * The bank files are written with the exam's published domain weights, so
 * proportional-to-the-bank is proportional-to-the-exam.
 */
final class QuestionPicker
{
    public function __construct(private ?Randomizer $random = null)
    {
        $this->random ??= new Randomizer;
    }

    /**
     * @param  array<int, string>  $domains  question id => domain
     * @return list<int> question ids in delivery order
     */
    public function pick(array $domains, int $count): array
    {
        $ids = array_keys($domains);
        if ($count <= 0 || $count >= count($ids)) {
            return $this->shuffle($ids);
        }

        $byDomain = [];
        foreach ($domains as $id => $domain) {
            $byDomain[$domain][] = $id;
        }

        $total = count($ids);
        $quota = [];
        $remainders = [];
        foreach ($byDomain as $domain => $members) {
            $exact = $count * count($members) / $total;
            $quota[$domain] = (int) floor($exact);
            $remainders[$domain] = $exact - floor($exact);
        }

        // Hand the seats left over to the largest remainders; ties go to the
        // bigger domain, then alphabetically, so the split is deterministic.
        $left = $count - array_sum($quota);
        uksort($remainders, fn ($a, $b) => [$remainders[$b], count($byDomain[$b]), $a] <=> [$remainders[$a], count($byDomain[$a]), $b]);
        foreach (array_keys($remainders) as $domain) {
            if ($left <= 0) {
                break;
            }
            $quota[$domain]++;
            $left--;
        }

        $picked = [];
        foreach ($byDomain as $domain => $members) {
            $picked = array_merge($picked, array_slice($this->shuffle($members), 0, $quota[$domain]));
        }

        return $this->shuffle($picked);
    }

    /**
     * @template T
     *
     * @param  list<T>  $items
     * @return list<T>
     */
    public function shuffle(array $items): array
    {
        return array_values($this->random->shuffleArray(array_values($items)));
    }
}
