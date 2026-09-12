<?php

namespace App\Services\Attendance\Readers;

use App\Models\Attendance\BiotimeSource;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;

/**
 * ZKTeco legacy (ZKTime / att2000) — the owner's tables:
 *   CHECKINOUT  USERID, CHECKTIME, CHECKTYPE, VERIFYCODE, SENSORID, Memoinfo, WorkCode, sn, UserExtFmt
 *   USERINFO    USERID, BADGENUMBER, SSN, NAME, …, CardNo, …
 *
 * Two things make this table unlike the other two:
 *
 * 1. It has NO id column. Its key is the composite (USERID, CHECKTIME), so it
 *    is read by a (time, id) keyset like access control — but the ref is a
 *    NUMBER, and access control compares its ref as text. Hence this class's
 *    own compare(); see the note on it.
 *
 * 2. The punch row carries no employee code, only USERID — an internal
 *    surrogate meaningless outside this database. USERINFO is joined to turn it
 *    into the badge number a human knows. The join is LEFT: a deleted USERINFO
 *    row must not make punches disappear, so such a code lands on the mapping
 *    page as `uid:{USERID}` instead.
 *
 * CHECKTIME is the moment of the scan, and there is no write-time column, so a
 * terminal that uploads late writes rows BEHIND the watermark. The source's
 * `lookback_days` re-reads recent days each sync, and biotime:reconcile is the
 * nightly backstop.
 */
class CheckInOutReader extends PunchReader
{
    public const TABLE = 'CHECKINOUT';

    public const USER_TABLE = 'USERINFO';

    private const FLOOR = '1900-01-01 00:00:00';

    public function table(): string
    {
        return self::TABLE;
    }

    public function columns(BiotimeSource $source): array
    {
        return ['USERID', 'CHECKTIME', 'CHECKTYPE', 'SENSORID', 'sn', $source->codeColumn(), 'NAME'];
    }

    public function test(BiotimeSource $source, ConnectionInterface $db): array
    {
        $identity = $this->identityColumns($db);
        $notes = [];

        if ($identity === []) {
            $notes[] = self::USER_TABLE.' has none of BADGENUMBER, SSN or CardNo — codes will all read as uid:{USERID}.';
        } elseif (! in_array($source->codeColumn(), $identity, true)) {
            $notes[] = 'This '.self::USER_TABLE." has no {$source->codeColumn()} column — it has ".implode(' and ', $identity).'. Change the employee code column on the source.';
        }

        $notes[] = $source->codePrefix() !== ''
            ? 'Codes are looked up as Oracle numbers with "'.$source->codePrefix().'" in front — badge 512 is Oracle '.$source->codePrefix().'512.'
            : 'No code prefix set: the badge is looked up as the Oracle number exactly as it is stored.';

        // Every identity column at once, whichever one the source uses, so it
        // is read off the screen which of them actually holds the badge.
        $select = ['c.USERID as USERID', 'c.CHECKTIME as CHECKTIME', 'c.CHECKTYPE as CHECKTYPE', 'c.sn as sn'];
        foreach ($identity as $column) {
            $select[] = 'u.'.$column.' as '.$column;
        }
        $select[] = 'u.NAME as NAME';

        // The sample is keyed by these, and the page renders one column per
        // entry — so it is the aliases here, not the qualified select list.
        $columns = array_merge(['USERID', 'CHECKTIME', 'CHECKTYPE', 'sn'], $identity, ['NAME']);

        $started = microtime(true);
        $sample = $db->table(self::TABLE.' as c')
            ->leftJoin(self::USER_TABLE.' as u', 'u.USERID', '=', 'c.USERID')
            ->select($select)
            ->orderByDesc('c.CHECKTIME')
            ->limit(5)
            ->get();
        $latency = (int) round((microtime(true) - $started) * 1000);

        $devices = $db->table(self::TABLE)
            ->select(['sn', 'SENSORID'])
            ->orderByDesc('CHECKTIME')
            ->limit(500)
            ->get()
            ->map(fn ($r) => trim((string) ($r->sn ?? '')) ?: trim((string) ($r->SENSORID ?? '')))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();

        $newest = $sample->first()?->CHECKTIME;

        return [
            'table' => self::TABLE.' + '.self::USER_TABLE,
            'latency_ms' => $latency,
            'watermark' => 'Newest CHECKTIME '.($newest ? substr((string) $newest, 0, 19) : '—'),
            'newest' => self::wallClock($newest),
            'locations_label' => 'Devices seen recently',
            'locations' => $devices,
            'columns' => $columns,
            'sample' => $sample->map(fn ($row) => (array) $row)->all(),
            'clock' => $this->serverClock($db),
            'notes' => $notes,
        ];
    }

    public function startCursor(BiotimeSource $source, ConnectionInterface $db, ?string $since): array
    {
        $from = $since ?? ($source->last_time === null ? $source->import_from?->toDateString() : null);

        if ($from !== null) {
            // Everything at or after midnight of that day (user id 0 sorts first).
            return ['time' => $this->toDbTime($from.' 00:00:00', $source), 'ref' => '0'];
        }

        if ($source->last_time !== null) {
            return ['time' => (string) $source->last_time, 'ref' => (string) $source->last_ref];
        }

        return $this->floorCursor();
    }

    public function floorCursor(): array
    {
        return ['time' => self::FLOOR, 'ref' => '0'];
    }

    public function fetch(BiotimeSource $source, ConnectionInterface $db, array $cursor, int $limit, ?array $window = null): Collection
    {
        $after = $this->literal($cursor['time']);

        // t >= x AND (t > x OR id > y): the same rows as t > x OR (t = x AND
        // id > y), but the leading range lets SQL Server seek the time index.
        // The join hangs off the ordered table, so it stays a seek plus a
        // nested loop into USERINFO by its primary key.
        $query = $db->table(self::TABLE.' as c')
            ->leftJoin(self::USER_TABLE.' as u', 'u.USERID', '=', 'c.USERID')
            ->select([
                'c.USERID as user_id',
                'c.CHECKTIME as punch_at',
                'c.CHECKTYPE as check_type',
                'c.SENSORID as sensor_id',
                'c.sn as device_sn',
                'u.'.$source->codeColumn().' as badge',
                'u.NAME as full_name',
            ])
            ->where('c.CHECKTIME', '>=', $after)
            ->where(fn ($q) => $q
                ->where('c.CHECKTIME', '>', $after)
                ->orWhere('c.USERID', '>', (int) $cursor['ref']));

        if ($window) {
            $query->where('c.CHECKTIME', '>=', $this->literal($this->toDbTime($window[0], $source)))
                ->where('c.CHECKTIME', '<', $this->literal($this->toDbTime($window[1], $source)));
        }

        return $query->orderBy('c.CHECKTIME')->orderBy('c.USERID')->limit($limit)->get();
    }

    public function cursorAfter(object $row): array
    {
        return ['time' => self::rawTime($row->punch_at), 'ref' => (string) (int) $row->user_id];
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
            && self::compare(
                [self::rawTime($row->punch_at), (string) (int) $row->user_id],
                [(string) $source->last_time, (string) $source->last_ref]
            ) <= 0;
    }

    public function normalise(object $row, BiotimeSource $source): ?array
    {
        $userId = (int) ($row->user_id ?? 0);
        $badge = trim((string) ($row->badge ?? ''));
        $time = $this->toWallClock($row->punch_at ?? null, $source);

        // No USERINFO row: keep the punch, under a code that is visibly not a
        // badge number, rather than dropping someone's day on the floor.
        $code = $badge !== '' ? $badge : ($userId > 0 ? 'uid:'.$userId : '');

        if ($code === '' || $time === null) {
            return null;
        }

        return [
            'external_id' => $userId.':'.self::compactTime($row->punch_at),
            'biotime_id' => null,
            'emp_code' => mb_substr($code, 0, 50),
            'punch_time' => $time,
            'punch_state' => self::text($row->check_type ?? null, 10),
            'terminal_sn' => self::text($row->device_sn ?? null, 100) ?? self::text($row->sensor_id ?? null, 100),
            'terminal_alias' => null,
            'area_alias' => null,
            'device_name' => self::text($row->full_name ?? null, 150),
            'device_user_id' => $userId > 0 ? (string) $userId : null,
        ];
    }

    /**
     * Which of USERINFO's identity columns this installation has. Asked only
     * on the test page; a database that will not answer falls back to all
     * three and lets the sample query say what is wrong.
     *
     * @return list<string>
     */
    private function identityColumns(ConnectionInterface $db): array
    {
        try {
            $present = $db->table('INFORMATION_SCHEMA.COLUMNS')
                ->where('TABLE_NAME', self::USER_TABLE)
                ->pluck('COLUMN_NAME')
                ->map(fn ($c) => mb_strtolower((string) $c))
                ->all();

            if ($present === []) {
                return array_keys(BiotimeSource::CODE_COLUMNS);
            }

            return array_values(array_filter(
                array_keys(BiotimeSource::CODE_COLUMNS),
                fn ($column) => in_array(mb_strtolower($column), $present, true)
            ));
        } catch (\Throwable) {
            return array_keys(BiotimeSource::CODE_COLUMNS);
        }
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

    /** 'YYYYMMDDhhmmss' — half of external_id, and stable whatever the source's time zone setting. */
    private static function compactTime(mixed $value): string
    {
        return preg_replace('/\D/', '', substr(self::rawTime($value), 0, 19));
    }

    /**
     * Orders two (time, USERID) keys, USERID as a NUMBER.
     *
     * As text '10' sorts before '9', so within one crowded second advance()
     * would refuse to move the watermark past the numerically later user. Rows
     * are not lost — the SQL side casts, so the next run reads them again — but
     * that second is re-read on every sync from then on, and withinWatermark()
     * starts reporting synced rows as unsynced, which is exactly the number
     * biotime:reconcile compares the two sides with.
     */
    private static function compare(array $a, array $b): int
    {
        return [self::sortable($a[0]), (int) $a[1]] <=> [self::sortable($b[0]), (int) $b[1]];
    }

    private static function sortable(string $time): string
    {
        [$whole, $fraction] = array_pad(explode('.', $time, 2), 2, '');

        return $whole.'.'.str_pad(substr($fraction, 0, 7), 7, '0');
    }
}
