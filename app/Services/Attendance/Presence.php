<?php

namespace App\Services\Attendance;

use Carbon\CarbonImmutable;

/**
 * Whether someone is in the building right now, as their fingerprint punches
 * since the start of their day show it. Pure: the caller hands over the
 * punches, oldest first, and nothing here reads the database or the clock.
 *
 * The day's attendance (AttendanceDayBuilder) ignores direction on purpose —
 * check-in is the earliest punch and check-out the latest. Presence cannot:
 * the latest punch of a day still running is either someone walking in or
 * someone walking out. So the direction is read from the one place it is
 * reliable, the TERMINAL: most sites have a door reader for each way, named
 * so (Riyadh_IN / Riyadh_Out, Jeddah_IN / Jeddah_OUT, biosec's IN / Out).
 * BioTime's numeric punch_state is not: on NOC2 in September 2026 Jeddah_OUT
 * recorded 0, "check in", on 2,402 of its 2,403 punches, and the IN readers
 * at Abha and Khobar record 255. Only the legacy table's CHECKTYPE letters
 * (Cairo's I / O, pressed on a terminal with no name) are taken, for want of
 * anything better there.
 *
 * A punch on a reader that records neither (the warehouses, RYD_CES) decides
 * nothing, so the status says "probably" and why: an odd number of punches
 * since the start of the day usually means still inside.
 *
 * A fingerprint records a punch and nothing else. Someone who walks out
 * behind a colleague without punching still shows as in the building, which
 * is why every status goes out with the punch it rests on.
 */
final class Presence
{
    public const IN = 'in_building';

    public const LEFT = 'left';

    public const PROBABLY_IN = 'probably_in';

    public const PROBABLY_LEFT = 'probably_left';

    public const NO_PUNCH = 'no_punch_today';

    public const DIRECTION_IN = 'in';

    public const DIRECTION_OUT = 'out';

    /** A terminal name holding one of these words is a door reader for that way. */
    private const IN_WORDS = ['in', 'entry', 'entrance', 'enter'];

    private const OUT_WORDS = ['out', 'exit'];

    /**
     * @param  string|null  $arrived  the first punch, Y-m-d H:i:s
     * @param  string|null  $lastPunch  the latest punch, Y-m-d H:i:s
     */
    public function __construct(
        public readonly string $status,
        public readonly ?string $arrived = null,
        public readonly ?string $lastPunch = null,
        public readonly ?string $lastTerminal = null,
        public readonly ?string $lastDirection = null,
        public readonly int $punches = 0,
    ) {}

    /**
     * @param  iterable<array{time: string|\DateTimeInterface, terminal?: ?string, state?: ?string}>  $punches  oldest first
     */
    public static function from(iterable $punches): self
    {
        $list = [];

        foreach ($punches as $punch) {
            $list[] = $punch;
        }

        if ($list === []) {
            return new self(self::NO_PUNCH);
        }

        $first = $list[0];
        $last = $list[count($list) - 1];
        $direction = self::direction($last['terminal'] ?? null, $last['state'] ?? null);

        $status = match ($direction) {
            self::DIRECTION_IN => self::IN,
            self::DIRECTION_OUT => self::LEFT,
            default => count($list) % 2 === 1 ? self::PROBABLY_IN : self::PROBABLY_LEFT,
        };

        return new self(
            $status,
            self::time($first['time']),
            self::time($last['time']),
            ($terminal = trim((string) ($last['terminal'] ?? ''))) !== '' ? $terminal : null,
            $direction,
            count($list),
        );
    }

    /** "in", "out", or null when neither the terminal nor the punch says. */
    public static function direction(?string $terminal, ?string $state): ?string
    {
        // Riyadh_IN, ABHA_OUT, "Main Entrance", CheckIn: split on anything that
        // is not a letter, and between a lower-case letter and a capital.
        $spaced = preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', (string) $terminal) ?? '';
        $words = preg_split('/[^a-z]+/', strtolower($spaced), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($words as $word) {
            if (in_array($word, self::IN_WORDS, true)) {
                return self::DIRECTION_IN;
            }

            if (in_array($word, self::OUT_WORDS, true)) {
                return self::DIRECTION_OUT;
            }
        }

        return match (strtoupper(trim((string) $state))) {
            'I' => self::DIRECTION_IN,
            'O' => self::DIRECTION_OUT,
            default => null,
        };
    }

    public function isIn(): bool
    {
        return in_array($this->status, [self::IN, self::PROBABLY_IN], true);
    }

    /** What the status rests on, in words the assistant can pass on. */
    public function basis(): string
    {
        $at = $this->lastPunch ? CarbonImmutable::parse($this->lastPunch)->format('H:i') : null;
        $on = $this->lastTerminal ? " on {$this->lastTerminal}" : '';

        return match ($this->status) {
            self::NO_PUNCH => 'No fingerprint punch since the start of the day.',
            self::IN => "Last punch was IN{$on} at {$at}.",
            self::LEFT => "Last punch was OUT{$on} at {$at}.",
            default => "Last punch was at {$at}{$on}, a terminal that records neither in nor out; "
                ."{$this->punches} punch(es) since the start of the day, and an odd number usually means still inside.",
        };
    }

    private static function time(string|\DateTimeInterface $time): string
    {
        return $time instanceof \DateTimeInterface
            ? $time->format('Y-m-d H:i:s')
            : CarbonImmutable::parse($time)->format('Y-m-d H:i:s');
    }
}
