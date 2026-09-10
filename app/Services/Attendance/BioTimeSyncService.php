<?php

namespace App\Services\Attendance;

use App\Models\Attendance\AttendancePunch;
use App\Models\Attendance\BiotimeArea;
use App\Models\Attendance\BiotimeEmployee;
use App\Models\Attendance\BiotimeSource;
use App\Models\Attendance\BiotimeTerminal;
use App\Models\AzureBranchMapping;
use App\Models\NocEvent;
use App\Support\BranchKeywordMatcher;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Copies punches from one BioTime database into attendance_punches.
 *
 * Incremental by iclock_transaction.id, never by punch_time: a terminal that
 * was offline uploads last week's punches today, and they arrive with new ids
 * but old times. Reading "punches since my last run" by time would skip them.
 *
 * Idempotent — rows are upserted on (source, biotime_id) — and resumable: the
 * watermark is saved after every chunk, so a big first backfill that is cut
 * short by max-rows or a crash simply carries on next run.
 */
class BioTimeSyncService
{
    public const CHUNK = 5000;

    public const DEFAULT_MAX_ROWS = 200000;

    public const FAILURES_BEFORE_ALERT = 3;

    private ?Collection $branchMappings = null;

    public function __construct(
        private BioTimeConnection $bioTime,
        private EmployeeLinker $linker,
        private AttendanceDayProcessor $processor,
    ) {}

    /**
     * @param  string|null  $since  re-read from this date (Y-m-d) instead of the watermark
     * @return array{status: string, rows: int, skipped: int, new_codes: int, days: int, last_id: int, done: bool}
     */
    public function sync(BiotimeSource $source, ?string $since = null, int $maxRows = self::DEFAULT_MAX_ROWS): array
    {
        // The page's "Sync now" and the scheduler must not run one source twice at once.
        $lock = Cache::lock('biotime-sync:'.$source->id, 900);
        if (! $lock->get()) {
            return ['status' => 'busy', 'rows' => 0, 'skipped' => 0, 'new_codes' => 0, 'days' => 0,
                'last_id' => (int) $source->last_id, 'done' => false];
        }

        try {
            $connection = $this->bioTime->connection($source);
            $start = $this->startId($source, $connection, $since);
            $result = $this->pull($source, $connection, $start, $maxRows, null, true);
        } catch (\Throwable $e) {
            $this->recordFailure($source, $e);
            throw $e;
        } finally {
            $lock->release();
        }

        $source->forceFill([
            'last_sync_at' => now(),
            'last_sync_status' => 'ok',
            'last_sync_error' => null,
            'last_sync_rows' => $result['rows'],
            'consecutive_failures' => 0,
        ])->save();
        $this->resolveEvent($source, 'biotime_source');

        return $result;
    }

    /**
     * Re-reads every punch in a date range regardless of the watermark — used
     * by biotime:reconcile when BioTime has punches the NOC does not.
     */
    public function pullRange(BiotimeSource $source, string $fromDate, string $toDate): array
    {
        $connection = $this->bioTime->connection($source);
        $window = [
            BioTimeConnection::sqlDateTime($fromDate.' 00:00:00'),
            BioTimeConnection::sqlDateTime(CarbonImmutable::parse($toDate)->addDay()->toDateString().' 00:00:00'),
        ];

        return $this->pull($source, $connection, 0, PHP_INT_MAX, $window, false);
    }

    /**
     * Punches per day on the BioTime side, up to the watermark so rows not yet
     * synced do not read as missing.
     *
     * @return array<string, int>
     */
    public function remoteDailyCounts(BiotimeSource $source, string $fromDate): array
    {
        return $this->bioTime->connection($source)
            ->table(BioTimeConnection::TABLE)
            ->selectRaw('CONVERT(varchar(10), punch_time, 23) as d, COUNT(*) as c')
            ->where('punch_time', '>=', BioTimeConnection::sqlDateTime($fromDate.' 00:00:00'))
            ->where('id', '<=', (int) $source->last_id)
            ->whereNotNull('emp_code')
            ->where('emp_code', '<>', '')
            ->groupByRaw('CONVERT(varchar(10), punch_time, 23)')
            ->pluck('c', 'd')
            ->map(fn ($c) => (int) $c)
            ->all();
    }

    /** @return array<string, int> */
    public function localDailyCounts(BiotimeSource $source, string $fromDate): array
    {
        return AttendancePunch::query()
            ->where('biotime_source_id', $source->id)
            ->where('punch_time', '>=', $fromDate.' 00:00:00')
            ->selectRaw('DATE(punch_time) as d, COUNT(*) as c')
            ->groupByRaw('DATE(punch_time)')
            ->pluck('c', 'd')
            ->map(fn ($c) => (int) $c)
            ->all();
    }

    public function raiseEvent(BiotimeSource $source, string $entityType, string $title, string $message): void
    {
        $open = $this->openEvent($source, $entityType);

        if ($open) {
            $open->update(['last_seen' => now(), 'message' => mb_substr($message, 0, 1000)]);

            return;
        }

        NocEvent::create([
            'module' => 'attendance',
            'branch_id' => $source->default_branch_id,
            'entity_type' => $entityType,
            'entity_id' => (string) $source->id,
            'source_type' => 'biotime_source',
            'source_id' => $source->id,
            'severity' => 'warning',
            'title' => mb_substr($title, 0, 250),
            'message' => mb_substr($message, 0, 1000),
            'first_seen' => now(),
            'last_seen' => now(),
            'status' => 'open',
        ]);
    }

    public function resolveEvent(BiotimeSource $source, string $entityType): void
    {
        NocEvent::where('entity_type', $entityType)
            ->where('entity_id', (string) $source->id)
            ->where('status', '!=', 'resolved')
            ->update(['status' => 'resolved', 'resolved_at' => now()]);
    }

    // ─────────────────────────────────────────────────────────────

    private function startId(BiotimeSource $source, ConnectionInterface $connection, ?string $since): int
    {
        $watermark = (int) $source->last_id;

        // An explicit --since re-reads from that date (safe: upserts). import_from
        // only shapes a source's very first run, so it is not years of history.
        $from = $since ?? ($watermark === 0 ? $source->import_from?->toDateString() : null);
        if ($from === null) {
            return $watermark;
        }

        $firstId = $connection->table(BioTimeConnection::TABLE)
            ->where('punch_time', '>=', BioTimeConnection::sqlDateTime($from.' 00:00:00'))
            ->min('id');

        if ($firstId === null) {
            // Nothing that recent: start at the end so later runs read only new punches.
            return $since === null ? (int) $connection->table(BioTimeConnection::TABLE)->max('id') : $watermark;
        }

        return max(0, (int) $firstId - 1);
    }

    /**
     * @param  array{0: string, 1: string}|null  $window  punch_time >= [0] and < [1]
     */
    private function pull(BiotimeSource $source, ConnectionInterface $connection, int $startId, int $maxRows, ?array $window, bool $advanceWatermark): array
    {
        $cursor = $startId;
        $rows = 0;
        $skipped = 0;
        $newCodes = 0;
        $touched = [];
        $done = false;
        $syncedAt = Carbon::now();

        while ($rows < $maxRows) {
            $query = $connection->table(BioTimeConnection::TABLE)
                ->select(BioTimeConnection::COLUMNS)
                ->where('id', '>', $cursor);

            if ($window) {
                $query->where('punch_time', '>=', $window[0])->where('punch_time', '<', $window[1]);
            }

            $batch = $query->orderBy('id')->limit(self::CHUNK)->get();

            if ($batch->isEmpty()) {
                $done = true;
                break;
            }

            $stored = $this->store($source, $batch, $syncedAt, $touched);
            $skipped += $stored['skipped'];
            $newCodes += $stored['new_codes'];
            $rows += $batch->count();
            $cursor = (int) $batch->last()->id;

            if ($advanceWatermark && $cursor > (int) $source->last_id) {
                $source->forceFill(['last_id' => $cursor])->save();
            }

            if ($batch->count() < self::CHUNK) {
                $done = true;
                break;
            }
        }

        if ($advanceWatermark && $startId > (int) $source->last_id) {
            $source->forceFill(['last_id' => $startId])->save();
        }

        $days = $this->processor->rebuild($touched);

        return [
            'status' => 'ok',
            'rows' => $rows,
            'skipped' => $skipped,
            'new_codes' => $newCodes,
            'days' => $days,
            'last_id' => (int) $source->last_id,
            'done' => $done,
        ];
    }

    /**
     * @param  array<string, array<string, true>>  $touched
     * @return array{skipped: int, new_codes: int}
     */
    private function store(BiotimeSource $source, Collection $batch, Carbon $syncedAt, array &$touched): array
    {
        $rows = [];
        $skipped = 0;

        foreach ($batch as $record) {
            $code = trim((string) ($record->emp_code ?? ''));
            $time = self::wallClock($record->punch_time ?? null);

            if ($code === '' || $time === null) {
                $skipped++;

                continue;
            }

            $rows[] = [
                'biotime_id' => (int) $record->id,
                'emp_code' => mb_substr($code, 0, 50),
                'punch_time' => $time,
                'punch_state' => self::text($record->punch_state ?? null, 10),
                'terminal_sn' => self::text($record->terminal_sn ?? null, 100),
                'terminal_alias' => self::text($record->terminal_alias ?? null, 150),
                'area_alias' => self::text($record->area_alias ?? null, 100),
            ];
        }

        if ($rows === []) {
            return ['skipped' => $skipped, 'new_codes' => 0];
        }

        $this->touchAreas($source, $rows);
        $this->touchTerminals($source, $rows);
        [$employees, $newCodes] = $this->ensureEmployees($source, $rows);

        $punches = [];
        foreach ($rows as $row) {
            $biotimeEmployee = $employees[mb_strtolower($row['emp_code'])];

            $punches[] = $row + [
                'biotime_source_id' => $source->id,
                'biotime_employee_id' => $biotimeEmployee->id,
                'employee_id' => $biotimeEmployee->employee_id,
                'synced_at' => $syncedAt,
            ];

            $subject = $biotimeEmployee->employee_id ? 'emp:'.$biotimeEmployee->employee_id : 'bt:'.$biotimeEmployee->id;
            $touched[$subject][substr($row['punch_time'], 0, 10)] = true;
        }

        foreach (array_chunk($punches, 1000) as $chunk) {
            AttendancePunch::upsert(
                $chunk,
                ['biotime_source_id', 'biotime_id'],
                ['biotime_employee_id', 'employee_id', 'emp_code', 'punch_time', 'punch_state',
                    'terminal_sn', 'terminal_alias', 'area_alias', 'synced_at'],
            );
        }

        return ['skipped' => $skipped, 'new_codes' => $newCodes];
    }

    /**
     * @return array{0: array<string, BiotimeEmployee>, 1: int} keyed by lower-cased emp_code
     */
    private function ensureEmployees(BiotimeSource $source, array $rows): array
    {
        $byCode = [];
        foreach ($rows as $row) {
            $key = mb_strtolower($row['emp_code']);
            $byCode[$key]['code'] ??= $row['emp_code'];
            $byCode[$key]['first'] = min($byCode[$key]['first'] ?? $row['punch_time'], $row['punch_time']);
            $byCode[$key]['last'] = max($byCode[$key]['last'] ?? $row['punch_time'], $row['punch_time']);
            if ($row['area_alias'] !== null) {
                $byCode[$key]['areas'][$row['area_alias']] = true;
            }
        }

        $known = BiotimeEmployee::query()
            ->where('biotime_source_id', $source->id)
            ->whereIn('emp_code', array_column($byCode, 'code'))
            ->get()
            ->keyBy(fn (BiotimeEmployee $e) => mb_strtolower($e->emp_code))
            ->all();

        $new = [];
        foreach ($byCode as $key => $info) {
            $areas = array_keys($info['areas'] ?? []);
            $biotimeEmployee = $known[$key] ?? null;

            if (! $biotimeEmployee) {
                $biotimeEmployee = BiotimeEmployee::createOrFirst(
                    ['biotime_source_id' => $source->id, 'emp_code' => $info['code']],
                    ['areas' => $areas, 'first_punch_at' => $info['first'], 'last_punch_at' => $info['last']],
                );
                $biotimeEmployee->setRelation('source', $source);
                $known[$key] = $biotimeEmployee;
                if ($biotimeEmployee->wasRecentlyCreated) {
                    $new[] = $biotimeEmployee;
                }

                continue;
            }

            $merged = array_values(array_unique(array_merge($biotimeEmployee->areas ?? [], $areas)));
            $biotimeEmployee->areas = array_slice($merged, 0, 20);

            if (! $biotimeEmployee->first_punch_at || $info['first'] < $biotimeEmployee->first_punch_at->format('Y-m-d H:i:s')) {
                $biotimeEmployee->first_punch_at = $info['first'];
            }
            if (! $biotimeEmployee->last_punch_at || $info['last'] > $biotimeEmployee->last_punch_at->format('Y-m-d H:i:s')) {
                $biotimeEmployee->last_punch_at = $info['last'];
            }
            if ($biotimeEmployee->isDirty()) {
                $biotimeEmployee->save();
            }
        }

        // Link before the punches are written, so they carry employee_id from
        // the start. Areas are already stored, so the branch rule can use them.
        foreach ($new as $biotimeEmployee) {
            $this->linker->autoLink($biotimeEmployee);
        }

        return [$known, count($new)];
    }

    private function touchAreas(BiotimeSource $source, array $rows): void
    {
        $latest = [];
        foreach ($rows as $row) {
            if ($row['area_alias'] !== null) {
                $latest[$row['area_alias']] = max($latest[$row['area_alias']] ?? '', $row['punch_time']);
            }
        }

        foreach ($latest as $alias => $lastPunch) {
            $area = BiotimeArea::createOrFirst(
                ['biotime_source_id' => $source->id, 'area_alias' => $alias],
                // First sight only: a keyword hit ("Jeddah") is a guess HR can change.
                ['branch_id' => BranchKeywordMatcher::match([$alias], $this->branchMappings ??= AzureBranchMapping::all())],
            );

            if (! $area->last_punch_at || $lastPunch > $area->last_punch_at->format('Y-m-d H:i:s')) {
                $area->forceFill(['last_punch_at' => $lastPunch])->save();
            }
        }
    }

    private function touchTerminals(BiotimeSource $source, array $rows): void
    {
        $latest = [];
        foreach ($rows as $row) {
            if ($row['terminal_sn'] === null) {
                continue;
            }
            if (! isset($latest[$row['terminal_sn']]) || $row['punch_time'] >= $latest[$row['terminal_sn']]['time']) {
                $latest[$row['terminal_sn']] = ['time' => $row['punch_time'], 'alias' => $row['terminal_alias'], 'area' => $row['area_alias']];
            }
        }

        foreach ($latest as $sn => $info) {
            $terminal = BiotimeTerminal::createOrFirst(['biotime_source_id' => $source->id, 'terminal_sn' => $sn]);

            if (! $terminal->last_punch_at || $info['time'] >= $terminal->last_punch_at->format('Y-m-d H:i:s')) {
                $terminal->forceFill([
                    'terminal_alias' => $info['alias'] ?? $terminal->terminal_alias,
                    'area_alias' => $info['area'] ?? $terminal->area_alias,
                    'last_punch_at' => $info['time'],
                ])->save();
            }
        }
    }

    private function recordFailure(BiotimeSource $source, \Throwable $e): void
    {
        $message = BioTimeConnection::cleanError($e);

        $source->forceFill([
            'last_sync_at' => now(),
            'last_sync_status' => 'error',
            'last_sync_error' => $message,
            'consecutive_failures' => (int) $source->consecutive_failures + 1,
        ])->save();

        Log::error("[biotime:sync] {$source->name}: {$message}");

        // One failed run is a blip; three in a row (15 minutes) means nothing is arriving.
        if ($source->consecutive_failures >= self::FAILURES_BEFORE_ALERT) {
            $this->raiseEvent($source, 'biotime_source',
                "BioTime source \"{$source->name}\" is not syncing",
                "{$source->consecutive_failures} failed syncs in a row. Last error: {$message}");
        }
    }

    private function openEvent(BiotimeSource $source, string $entityType): ?NocEvent
    {
        return NocEvent::where('entity_type', $entityType)
            ->where('entity_id', (string) $source->id)
            ->where('status', '!=', 'resolved')
            ->first();
    }

    /**
     * punch_time exactly as the device wrote it, 'Y-m-d H:i:s'. Not parsed
     * into a zone — it has none, and converting it would shift every punch.
     */
    public static function wallClock(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})/', trim((string) $value), $m)) {
            return (int) substr($m[1], 0, 4) >= 2000 ? $m[1].' '.$m[2] : null;
        }

        return null;
    }

    private static function text(mixed $value, int $max): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
