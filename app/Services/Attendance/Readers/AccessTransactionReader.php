<?php

namespace App\Services\Attendance\Readers;

use App\Models\Attendance\BiotimeSource;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;

/**
 * ZKBio access control (ZKBioSecurity / ZKBio CVSecurity) — the owner's query:
 *   SELECT id, create_time, dev_alias, dev_id, dev_sn, pin FROM acc_transaction
 *
 * `pin` is the employee code, the time column is the punch, `dev_alias` names
 * the terminal (often just "IN" / "Out"). There is no area, so a punch gets
 * its branch from its terminal.
 *
 * The ids are 32-character hex strings with no usable order, so the table is
 * read incrementally by (time, id) — a keyset. Rows that share a timestamp
 * (common: several scans in one second) are neither skipped nor read twice,
 * and paging never stalls on a crowded second.
 *
 * `create_time` is when the row was written. For an online terminal that is
 * the punch; a terminal that uploads late gets the upload time instead. Where
 * the table also has `event_time` — the moment of the scan — choose it on the
 * source; the nightly reconcile then catches rows that arrive late.
 */
class AccessTransactionReader extends PunchReader
{
    public const TABLE = 'acc_transaction';

    public const TIME_COLUMNS = ['create_time', 'event_time'];

    private const FLOOR = '1900-01-01 00:00:00';

    public function table(): string
    {
        return self::TABLE;
    }

    /** Whitelisted, so it is safe to name in SQL. */
    public function timeColumn(BiotimeSource $source): string
    {
        return in_array($source->time_column, self::TIME_COLUMNS, true) ? $source->time_column : 'create_time';
    }

    public function columns(BiotimeSource $source): array
    {
        return ['id', $this->timeColumn($source), 'dev_alias', 'dev_id', 'dev_sn', 'pin'];
    }

    public function test(BiotimeSource $source, ConnectionInterface $db): array
    {
        $time = $this->timeColumn($source);
        $notes = [];

        // Which time columns the table really has — asked first, so a wrong
        // choice reads as a clear sentence rather than "Invalid column name".
        try {
            $present = $db->table('INFORMATION_SCHEMA.COLUMNS')
                ->where('TABLE_NAME', self::TABLE)
                ->whereIn('COLUMN_NAME', self::TIME_COLUMNS)
                ->pluck('COLUMN_NAME')
                ->map(fn ($c) => strtolower((string) $c))
                ->all();

            if ($present !== [] && ! in_array($time, $present, true)) {
                $notes[] = "This table has no {$time} column — it has ".implode(' and ', $present).'. Change the time column on the source.';
            } elseif ($time === 'create_time' && in_array('event_time', $present, true)) {
                $notes[] = 'The table also has event_time, the moment of the scan. With create_time, a punch a terminal uploads late gets the upload time.';
            }
        } catch (\Throwable) {
            // No metadata access; the query below will say what is wrong.
        }

        $started = microtime(true);
        $sample = $db->table(self::TABLE)
            ->select($this->columns($source))
            ->orderByDesc($time)
            ->limit(5)
            ->get();
        $latency = (int) round((microtime(true) - $started) * 1000);

        $recent = $db->table(self::TABLE)->select(['dev_alias', 'dev_sn'])->orderByDesc($time)->limit(500)->get();
        $devices = $recent
            ->map(fn ($r) => trim(trim((string) ($r->dev_alias ?? '')).' · '.trim((string) ($r->dev_sn ?? '')), ' ·'))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();

        $newest = $sample->first()?->{$time};

        return [
            'table' => self::TABLE,
            'latency_ms' => $latency,
            'watermark' => "Newest {$time} ".($newest ? substr((string) $newest, 0, 23) : '—'),
            'newest' => self::wallClock($newest),
            'locations_label' => 'Terminals seen recently',
            'locations' => $devices,
            'columns' => $this->columns($source),
            'sample' => $sample->map(fn ($row) => (array) $row)->all(),
            'clock' => $this->serverClock($db),
            'notes' => $notes,
        ];
    }

    public function startCursor(BiotimeSource $source, ConnectionInterface $db, ?string $since): array
    {
        $from = $since ?? ($source->last_time === null ? $source->import_from?->toDateString() : null);

        if ($from !== null) {
            // Everything at or after midnight of that day (an empty id sorts first).
            return ['time' => $this->toDbTime($from.' 00:00:00', $source), 'ref' => ''];
        }

        if ($source->last_time !== null) {
            return ['time' => (string) $source->last_time, 'ref' => (string) $source->last_ref];
        }

        return $this->floorCursor();
    }

    public function floorCursor(): array
    {
        return ['time' => self::FLOOR, 'ref' => ''];
    }

    public function fetch(BiotimeSource $source, ConnectionInterface $db, array $cursor, int $limit, ?array $window = null): Collection
    {
        $column = $this->timeColumn($source);
        $after = $this->literal($cursor['time']);

        $query = $db->table(self::TABLE)
            ->select(['id', $column.' as punch_at', 'dev_alias', 'dev_id', 'dev_sn', 'pin'])
            ->where(fn ($q) => $q
                ->where($column, '>', $after)
                ->orWhere(fn ($same) => $same->where($column, '=', $after)->where('id', '>', (string) $cursor['ref'])));

        if ($window) {
            $query->where($column, '>=', $this->literal($this->toDbTime($window[0], $source)))
                ->where($column, '<', $this->literal($this->toDbTime($window[1], $source)));
        }

        return $query->orderBy($column)->orderBy('id')->limit($limit)->get();
    }

    public function cursorAfter(object $row): array
    {
        return ['time' => self::rawTime($row->punch_at), 'ref' => (string) $row->id];
    }

    public function advance(BiotimeSource $source, array $cursor): void
    {
        if ($source->last_time === null
            || self::compare([$cursor['time'], $cursor['ref']], [(string) $source->last_time, (string) $source->last_ref]) > 0) {
            $source->forceFill(['last_time' => $cursor['time'], 'last_ref' => mb_substr((string) $cursor['ref'], 0, 64)])->save();
        }
    }

    public function withinWatermark(object $row, BiotimeSource $source): bool
    {
        return $source->last_time !== null
            && self::compare([self::rawTime($row->punch_at), (string) $row->id], [(string) $source->last_time, (string) $source->last_ref]) <= 0;
    }

    public function normalise(object $row, BiotimeSource $source): ?array
    {
        $code = trim((string) ($row->pin ?? ''));
        $time = $this->toWallClock($row->punch_at ?? null, $source);

        if ($code === '' || $time === null) {
            return null;
        }

        return [
            'external_id' => mb_substr((string) $row->id, 0, 64),
            'biotime_id' => null,
            'emp_code' => mb_substr($code, 0, 50),
            'punch_time' => $time,
            'punch_state' => null,
            'terminal_sn' => self::text($row->dev_sn ?? null, 100) ?? self::text($row->dev_id ?? null, 100),
            'terminal_alias' => self::text($row->dev_alias ?? null, 150),
            'area_alias' => null,
        ];
    }

    /**
     * The time exactly as the database returned it, fractions and all — the
     * keyset must compare against the stored value, not a rounded one, or a
     * crowded second is read forever.
     */
    private static function rawTime(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s.v');
        }

        return str_replace('T', ' ', trim((string) $value));
    }

    /** Orders two (time, id) keys; fractions are padded so '…:05.5' and '…:05.500' compare equal. */
    private static function compare(array $a, array $b): int
    {
        return [self::sortable($a[0]), $a[1]] <=> [self::sortable($b[0]), $b[1]];
    }

    private static function sortable(string $time): string
    {
        [$whole, $fraction] = array_pad(explode('.', $time, 2), 2, '');

        return $whole.'.'.str_pad(substr($fraction, 0, 7), 7, '0');
    }
}
