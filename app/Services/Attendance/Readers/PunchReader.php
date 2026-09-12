<?php

namespace App\Services\Attendance\Readers;

use App\Models\Attendance\BiotimeSource;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;

/**
 * Reads one kind of ZKTeco punch table. BioTimeSyncService does the storing,
 * linking and day building; a reader only knows its table — which columns,
 * how to page through it incrementally, and how a row becomes a punch.
 *
 * A normalised punch has, in this order:
 *   external_id (string, unique per source), biotime_id (?int), emp_code,
 *   punch_time ('Y-m-d H:i:s', local wall clock), punch_state, terminal_sn,
 *   terminal_alias, area_alias
 *
 * A reader whose database has its own user table may also return device_name
 * and device_user_id. Both are display only — never matched on — and
 * BioTimeSyncService moves them onto biotime_employees, stripping them before
 * the punch itself is written.
 *
 * A cursor is a small array only the reader understands. The source row keeps
 * the watermark between runs.
 */
abstract class PunchReader
{
    abstract public function table(): string;

    /** @return list<string> the columns the reader selects */
    abstract public function columns(BiotimeSource $source): array;

    /**
     * What the Sources page's "Test connection" shows.
     *
     * @return array{table: string, latency_ms: int, watermark: string, newest: ?string, locations_label: string,
     *               locations: list<string>, columns: list<string>, sample: list<array<string, mixed>>,
     *               clock: array{local: string, utc: string}|null, notes: list<string>}
     */
    abstract public function test(BiotimeSource $source, ConnectionInterface $db): array;

    /** Where an incremental run starts: $since (Y-m-d) when given, else the watermark. */
    abstract public function startCursor(BiotimeSource $source, ConnectionInterface $db, ?string $since): array;

    /** Before every row — for reading a whole window. */
    abstract public function floorCursor(): array;

    /**
     * The next rows after $cursor, in cursor order.
     *
     * @param  array{0: string, 1: string}|null  $window  local wall-clock [from, to)
     */
    abstract public function fetch(BiotimeSource $source, ConnectionInterface $db, array $cursor, int $limit, ?array $window = null): Collection;

    abstract public function cursorAfter(object $row): array;

    /** Moves the source's watermark to $cursor when that is further on. */
    abstract public function advance(BiotimeSource $source, array $cursor): void;

    /** Whether a row is at or before the watermark, i.e. already synced. */
    abstract public function withinWatermark(object $row, BiotimeSource $source): bool;

    /** @return array<string, mixed>|null null to skip the row (no employee code, no usable time) */
    abstract public function normalise(object $row, BiotimeSource $source): ?array;

    // ─── Shared ──────────────────────────────────────────────────

    /**
     * The punch as local wall-clock time. A database that keeps local time is
     * read as-is — no zone, never converted; one that keeps UTC is converted
     * to the source's time zone.
     */
    protected function toWallClock(mixed $value, BiotimeSource $source): ?string
    {
        $wall = self::wallClock($value);

        if ($wall === null || ! $source->stores_utc) {
            return $wall;
        }

        return CarbonImmutable::parse($wall, 'UTC')
            ->setTimezone($source->timezone ?: config('app.timezone'))
            ->format('Y-m-d H:i:s');
    }

    /** The database's own time for a local wall-clock time — the inverse of toWallClock(). */
    protected function toDbTime(string $wall, BiotimeSource $source): string
    {
        if (! $source->stores_utc) {
            return $wall;
        }

        return CarbonImmutable::parse($wall, $source->timezone ?: config('app.timezone'))
            ->utc()
            ->format('Y-m-d H:i:s');
    }

    /**
     * A time literal SQL Server reads the same whatever the login's language:
     * 'YYYYMMDD hh:mm:ss[.fff]'. 'YYYY-MM-DD …' is read as year-day-month for
     * a DATETIME column under British or French DATEFORMAT.
     */
    protected function literal(string $time): string
    {
        return str_replace('-', '', substr($time, 0, 10)).substr($time, 10);
    }

    /** 'Y-m-d H:i:s' exactly as the database wrote it (fractions dropped), or null. */
    public static function wallClock(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})/', trim((string) $value), $m)) {
            return (int) substr($m[1], 0, 4) >= 2000 ? $m[1].' '.$m[2] : null;
        }

        return null;
    }

    protected static function text(mixed $value, int $max): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /**
     * SQL Server's own clock, local and UTC. Set next to the newest row, it
     * shows whether the table keeps local time or UTC.
     *
     * @return array{local: string, utc: string}|null
     */
    protected function serverClock(ConnectionInterface $db): ?array
    {
        try {
            $row = $db->selectOne('SELECT SYSDATETIME() AS local_now, SYSUTCDATETIME() AS utc_now');

            return ['local' => substr((string) $row->local_now, 0, 19), 'utc' => substr((string) $row->utc_now, 0, 19)];
        } catch (\Throwable) {
            return null;
        }
    }
}
