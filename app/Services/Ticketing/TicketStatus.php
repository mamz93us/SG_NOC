<?php

namespace App\Services\Ticketing;

/**
 * The ticketing system's `requestStatus` values, as given by its owners.
 *
 * `0` means "all" and `-1` means "open and in progress" — both are query
 * filters rather than states a ticket can be in, so they are kept out of
 * {@see labels()} and only ever used as arguments.
 */
class TicketStatus
{
    public const ALL = 0;

    /** The API's own shorthand for "still needs someone": open + in progress. */
    public const OPEN_AND_IN_PROGRESS = -1;

    public const OPEN = 1;

    public const IN_PROGRESS = 2;

    public const WAITING_FOR_USER = 3;

    public const COMPLETED = 4;

    public const CANCELLED = 5;

    public const CLOSED = 6;

    public const REJECTED = 7;

    /**
     * States a ticket still counts as "live" in. Waiting-for-user is included
     * deliberately: it is not finished, and it is the one state where the
     * person looking at the page is the one holding it up.
     */
    public const LIVE = [self::OPEN, self::IN_PROGRESS, self::WAITING_FOR_USER];

    /** @return array<int, string> */
    public static function labels(): array
    {
        return [
            self::OPEN => 'Open',
            self::IN_PROGRESS => 'In Progress',
            self::WAITING_FOR_USER => "Waiting for user's action",
            self::COMPLETED => 'Completed',
            self::CANCELLED => 'Cancelled',
            self::CLOSED => 'Closed',
            self::REJECTED => 'Rejected',
        ];
    }

    public static function label(?int $id): ?string
    {
        return self::labels()[$id] ?? null;
    }

    /** Every value accepted as a filter, including the two pseudo-states. */
    public static function filterable(): array
    {
        return array_merge([self::OPEN_AND_IN_PROGRESS, self::ALL], array_keys(self::labels()));
    }

    public static function isLive(?int $id): bool
    {
        return in_array($id, self::LIVE, true);
    }

    /**
     * Bootstrap colour per state, so the badge means the same thing on the NOC
     * page and in the portal.
     */
    public static function colour(?int $id): string
    {
        return match ($id) {
            self::OPEN => 'primary',
            self::IN_PROGRESS => 'info',
            self::WAITING_FOR_USER => 'warning',
            self::COMPLETED => 'success',
            self::CLOSED => 'secondary',
            self::CANCELLED => 'secondary',
            self::REJECTED => 'danger',
            default => 'secondary',
        };
    }
}
