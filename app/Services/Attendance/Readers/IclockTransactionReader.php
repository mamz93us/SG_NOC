<?php

namespace App\Services\Attendance\Readers;

use App\Models\Attendance\BiotimeSource;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;

/**
 * ZKTeco BioTime attendance — the owner's query:
 *   SELECT id, emp_code, punch_time, punch_state, terminal_sn, terminal_alias, area_alias
 *   FROM iclock_transaction
 *
 * Incremental by the numeric id, never by punch_time: an offline terminal
 * uploads last week's punches today, with new ids but old times.
 */
class IclockTransactionReader extends PunchReader
{
    public const TABLE = 'iclock_transaction';

    public const COLUMNS = ['id', 'emp_code', 'punch_time', 'punch_state', 'terminal_sn', 'terminal_alias', 'area_alias'];

    public function table(): string
    {
        return self::TABLE;
    }

    public function columns(BiotimeSource $source): array
    {
        return self::COLUMNS;
    }

    public function test(BiotimeSource $source, ConnectionInterface $db): array
    {
        $started = microtime(true);
        $sample = $db->table(self::TABLE)->select(self::COLUMNS)->orderByDesc('id')->limit(5)->get();
        $latency = (int) round((microtime(true) - $started) * 1000);

        $maxId = (int) $db->table(self::TABLE)->max('id');

        // Only recent rows: a DISTINCT over years of punches is a full scan.
        $areas = $db->table(self::TABLE)
            ->where('id', '>', max(0, $maxId - 200000))
            ->whereNotNull('area_alias')
            ->distinct()
            ->orderBy('area_alias')
            ->limit(100)
            ->pluck('area_alias')
            ->map(fn ($a) => (string) $a)
            ->all();

        return [
            'table' => self::TABLE,
            'latency_ms' => $latency,
            'watermark' => 'Latest id '.number_format($maxId),
            'newest' => self::wallClock($sample->first()?->punch_time),
            'locations_label' => 'Areas seen recently',
            'locations' => $areas,
            'columns' => self::COLUMNS,
            'sample' => $sample->map(fn ($row) => (array) $row)->all(),
            'clock' => $this->serverClock($db),
            'notes' => [],
        ];
    }

    public function startCursor(BiotimeSource $source, ConnectionInterface $db, ?string $since): array
    {
        $watermark = (int) $source->last_id;

        // An explicit --since re-reads from that date (safe: upserts). import_from
        // only shapes a source's very first run, so it is not years of history.
        $from = $since ?? ($watermark === 0 ? $source->import_from?->toDateString() : null);
        if ($from === null) {
            return ['id' => $watermark];
        }

        $firstId = $db->table(self::TABLE)
            ->where('punch_time', '>=', $this->literal($this->toDbTime($from.' 00:00:00', $source)))
            ->min('id');

        if ($firstId === null) {
            // Nothing that recent: start at the end so later runs read only new punches.
            return ['id' => $since === null ? (int) $db->table(self::TABLE)->max('id') : $watermark];
        }

        return ['id' => max(0, (int) $firstId - 1)];
    }

    public function floorCursor(): array
    {
        return ['id' => 0];
    }

    public function fetch(BiotimeSource $source, ConnectionInterface $db, array $cursor, int $limit, ?array $window = null): Collection
    {
        $query = $db->table(self::TABLE)->select(self::COLUMNS)->where('id', '>', (int) $cursor['id']);

        if ($window) {
            $query->where('punch_time', '>=', $this->literal($this->toDbTime($window[0], $source)))
                ->where('punch_time', '<', $this->literal($this->toDbTime($window[1], $source)));
        }

        return $query->orderBy('id')->limit($limit)->get();
    }

    public function cursorAfter(object $row): array
    {
        return ['id' => (int) $row->id];
    }

    public function advance(BiotimeSource $source, array $cursor): void
    {
        if ((int) $cursor['id'] > (int) $source->last_id) {
            $source->forceFill(['last_id' => (int) $cursor['id']])->save();
        }
    }

    public function withinWatermark(object $row, BiotimeSource $source): bool
    {
        return (int) $row->id <= (int) $source->last_id;
    }

    public function normalise(object $row, BiotimeSource $source): ?array
    {
        $code = trim((string) ($row->emp_code ?? ''));
        $time = $this->toWallClock($row->punch_time ?? null, $source);

        if ($code === '' || $time === null) {
            return null;
        }

        return [
            'external_id' => (string) (int) $row->id,
            'biotime_id' => (int) $row->id,
            'emp_code' => mb_substr($code, 0, 50),
            'punch_time' => $time,
            'punch_state' => self::text($row->punch_state ?? null, 10),
            'terminal_sn' => self::text($row->terminal_sn ?? null, 100),
            'terminal_alias' => self::text($row->terminal_alias ?? null, 150),
            'area_alias' => self::text($row->area_alias ?? null, 100),
        ];
    }
}
