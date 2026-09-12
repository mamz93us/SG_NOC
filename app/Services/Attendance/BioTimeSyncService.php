<?php

namespace App\Services\Attendance;

use App\Models\Attendance\AttendancePunch;
use App\Models\Attendance\BiotimeArea;
use App\Models\Attendance\BiotimeEmployee;
use App\Models\Attendance\BiotimeSource;
use App\Models\Attendance\BiotimeTerminal;
use App\Models\AzureBranchMapping;
use App\Models\NocEvent;
use App\Services\Attendance\Readers\PunchReader;
use App\Services\Attendance\Readers\PunchReaders;
use App\Support\BranchKeywordMatcher;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Copies punches from one ZKTeco database into attendance_punches.
 *
 * What is read, and how it is paged, belongs to the source's reader:
 *  - BioTime attendance (iclock_transaction) — by the numeric id, never by
 *    punch time: an offline terminal uploads old punches with new ids;
 *  - ZKBio access control (acc_transaction) — its ids are unordered hex, so
 *    by (time, id);
 *  - ZKTeco legacy (CHECKINOUT + USERINFO) — no id at all, so by
 *    (CHECKTIME, USERID), with the badge number joined in from USERINFO.
 *
 * Idempotent — upserted on (source, external_id) — and resumable: the
 * watermark is saved after every chunk, so a big first backfill that is cut
 * short by max-rows or a crash simply carries on next run.
 */
class BioTimeSyncService
{
    public const CHUNK = 5000;

    public const DEFAULT_MAX_ROWS = 200000;

    public const FAILURES_BEFORE_ALERT = 3;

    /** Rows per query. Tests lower it to exercise paging. */
    public int $chunk = self::CHUNK;

    private ?Collection $branchMappings = null;

    private PunchReaders $readers;

    public function __construct(
        private BioTimeConnection $bioTime,
        private EmployeeLinker $linker,
        private AttendanceDayProcessor $processor,
        ?PunchReaders $readers = null,
    ) {
        $this->readers = $readers ?? new PunchReaders;
    }

    public function reader(BiotimeSource $source): PunchReader
    {
        return $this->readers->for($source);
    }

    /** The Sources page's "Test connection". */
    public function test(BiotimeSource $source): array
    {
        return $this->reader($source)->test($source, $this->bioTime->connection($source));
    }

    /**
     * @param  string|null  $since  re-read from this date (Y-m-d) instead of the watermark
     * @param  int|null  $maxSeconds  stop after about this long; the next run carries on from the watermark
     * @return array{status: string, rows: int, skipped: int, new_codes: int, days: int, last_id: int, watermark: string, done: bool}
     */
    public function sync(BiotimeSource $source, ?string $since = null, int $maxRows = self::DEFAULT_MAX_ROWS, ?int $maxSeconds = null): array
    {
        // A queued "Sync now" and the scheduler must not run one source twice at
        // once. The lock outlives any budgeted run and is released in finally.
        $lock = Cache::lock('biotime-sync:'.$source->id, 3600);
        if (! $lock->get()) {
            return ['status' => 'busy', 'rows' => 0, 'skipped' => 0, 'new_codes' => 0, 'days' => 0,
                'last_id' => (int) $source->last_id, 'watermark' => $source->watermarkLabel(), 'done' => false];
        }

        try {
            $db = $this->bioTime->connection($source);
            $reader = $this->reader($source);
            $deadline = $maxSeconds ? microtime(true) + $maxSeconds : null;

            $result = $this->pull($source, $reader, $db, $reader->startCursor($source, $db, $since), $maxRows, null, true, $maxSeconds);
            $result = $this->catchUpLate($source, $reader, $db, $result, $since, $maxRows, $deadline);
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
     * by biotime:reconcile when the source has punches the NOC does not.
     */
    public function pullRange(BiotimeSource $source, string $fromDate, string $toDate): array
    {
        $db = $this->bioTime->connection($source);
        $reader = $this->reader($source);
        $window = [$fromDate.' 00:00:00', CarbonImmutable::parse($toDate)->addDay()->toDateString().' 00:00:00'];

        return $this->pull($source, $reader, $db, $reader->floorCursor(), PHP_INT_MAX, $window, false);
    }

    /**
     * Punches per local day on the source side, up to the watermark so rows
     * not yet synced do not read as missing. Counted from normalised rows, so
     * a UTC source is grouped by the same local day as the NOC.
     *
     * @return array<string, int>
     */
    public function remoteDailyCounts(BiotimeSource $source, string $fromDate): array
    {
        $db = $this->bioTime->connection($source);
        $reader = $this->reader($source);

        // A day's margin either side of the range, then counted by local date:
        // a UTC source's day boundaries do not fall on the NOC's midnight.
        $window = [
            CarbonImmutable::parse($fromDate)->subDay()->toDateString().' 00:00:00',
            CarbonImmutable::today()->addDays(2)->toDateString().' 00:00:00',
        ];

        $cursor = $reader->floorCursor();
        $counts = [];

        while (true) {
            $batch = $reader->fetch($source, $db, $cursor, $this->chunk, $window);
            if ($batch->isEmpty()) {
                break;
            }

            foreach ($batch as $row) {
                if (! $reader->withinWatermark($row, $source)) {
                    continue;
                }
                $punch = $reader->normalise($row, $source);
                $date = $punch ? substr($punch['punch_time'], 0, 10) : null;
                if ($date !== null && $date >= $fromDate) {
                    $counts[$date] = ($counts[$date] ?? 0) + 1;
                }
            }

            $cursor = $reader->cursorAfter($batch->last());
            if ($batch->count() < $this->chunk) {
                break;
            }
        }

        ksort($counts);

        return $counts;
    }

    /** @return array<string, int> */
    public function localDailyCounts(BiotimeSource $source, string $fromDate): array
    {
        $counts = AttendancePunch::query()
            ->where('biotime_source_id', $source->id)
            ->where('punch_time', '>=', $fromDate.' 00:00:00')
            ->selectRaw('DATE(punch_time) as d, COUNT(*) as c')
            ->groupByRaw('DATE(punch_time)')
            ->pluck('c', 'd')
            ->map(fn ($c) => (int) $c)
            ->all();

        ksort($counts);

        return $counts;
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

    /**
     * @param  array{0: string, 1: string}|null  $window  local wall-clock [from, to)
     */
    private function pull(BiotimeSource $source, PunchReader $reader, ConnectionInterface $db, array $cursor, int $maxRows, ?array $window, bool $advanceWatermark, ?int $maxSeconds = null): array
    {
        $start = $cursor;
        $rows = 0;
        $skipped = 0;
        $newCodes = 0;
        $days = 0;
        $done = false;
        $syncedAt = Carbon::now();
        $deadline = $maxSeconds ? microtime(true) + $maxSeconds : null;

        while ($rows < $maxRows && ($deadline === null || microtime(true) < $deadline)) {
            $batch = $reader->fetch($source, $db, $cursor, $this->chunk, $window);

            if ($batch->isEmpty()) {
                $done = true;
                break;
            }

            $punches = [];
            foreach ($batch as $row) {
                $punch = $reader->normalise($row, $source);
                $punch === null ? $skipped++ : $punches[] = $punch;
            }

            $touched = [];
            $newCodes += $this->store($source, $punches, $syncedAt, $touched);
            // Days are rebuilt per page, so a run its time budget cuts short
            // never leaves stored punches with stale days.
            $days += $this->processor->rebuild($touched);
            $rows += $batch->count();
            $cursor = $reader->cursorAfter($batch->last());

            if ($advanceWatermark) {
                $reader->advance($source, $cursor);
            }

            if ($batch->count() < $this->chunk) {
                $done = true;
                break;
            }
        }

        // A start past the watermark counts even when nothing was read — an
        // import_from date with nothing newer yet.
        if ($advanceWatermark) {
            $reader->advance($source, $start);
        }

        return [
            'status' => 'ok',
            'rows' => $rows,
            'skipped' => $skipped,
            'new_codes' => $newCodes,
            'days' => $days,
            'last_id' => (int) $source->last_id,
            'watermark' => $source->watermarkLabel(),
            'done' => $done,
        ];
    }

    /**
     * Re-reads the last `lookback_days` days, ignoring the watermark.
     *
     * A table read by a (time, id) keyset cannot see a punch that arrived late:
     * a terminal uploading yesterday's scans writes them BEHIND the watermark,
     * and the forward pass never looks back. The legacy CHECKINOUT table has no
     * write-time column at all, so there is nothing else to page by. Re-reading
     * is free of consequences — punches upsert on (source, external_id) — and
     * the nightly biotime:reconcile remains the backstop for anything older.
     *
     * Skipped while a backfill is still catching up, and when the run is out of
     * time: the next one does it.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function catchUpLate(BiotimeSource $source, PunchReader $reader, ConnectionInterface $db, array $result, ?string $since, int $maxRows, ?float $deadline): array
    {
        $days = (int) $source->lookback_days;

        if ($days < 1 || $since !== null || ! $result['done'] || ($deadline !== null && microtime(true) >= $deadline)) {
            return $result;
        }

        $window = [
            CarbonImmutable::today()->subDays($days - 1)->toDateString().' 00:00:00',
            CarbonImmutable::today()->addDay()->toDateString().' 00:00:00',
        ];

        $again = $this->pull($source, $reader, $db, $reader->floorCursor(), $maxRows, $window, false,
            $deadline === null ? null : max(1, (int) ceil($deadline - microtime(true))));

        foreach (['rows', 'skipped', 'new_codes', 'days'] as $key) {
            $result[$key] += $again[$key];
        }

        return $result;
    }

    /**
     * @param  list<array<string, mixed>>  $rows  normalised punches
     * @param  array<string, array<string, true>>  $touched
     * @return int codes seen for the first time
     */
    private function store(BiotimeSource $source, array $rows, Carbon $syncedAt, array &$touched): int
    {
        if ($rows === []) {
            return 0;
        }

        $this->touchAreas($source, $rows);
        $this->touchTerminals($source, $rows);
        [$employees, $newCodes] = $this->ensureEmployees($source, $rows);

        $punches = [];
        foreach ($rows as $row) {
            $biotimeEmployee = $employees[mb_strtolower($row['emp_code'])];

            // Whatever the device knows about the person lives on the code,
            // not on every one of their punches.
            unset($row['device_name'], $row['device_user_id']);

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
                ['biotime_source_id', 'external_id'],
                ['biotime_id', 'biotime_employee_id', 'employee_id', 'emp_code', 'punch_time', 'punch_state',
                    'terminal_sn', 'terminal_alias', 'area_alias', 'synced_at'],
            );
        }

        return $newCodes;
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
            if ($row['terminal_sn'] !== null) {
                $byCode[$key]['terminals'][$row['terminal_sn']] = true;
            }
            // Display only — never matched on. Only a source with its own user
            // table (the legacy ZKTeco one) supplies these.
            foreach (['device_name', 'device_user_id'] as $field) {
                if (($row[$field] ?? null) !== null) {
                    $byCode[$key][$field] = $row[$field];
                }
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
            $terminals = array_keys($info['terminals'] ?? []);
            $biotimeEmployee = $known[$key] ?? null;

            if (! $biotimeEmployee) {
                $biotimeEmployee = BiotimeEmployee::createOrFirst(
                    ['biotime_source_id' => $source->id, 'emp_code' => $info['code']],
                    ['areas' => $areas, 'terminals' => $terminals, 'first_punch_at' => $info['first'], 'last_punch_at' => $info['last'],
                        'device_name' => $info['device_name'] ?? null, 'device_user_id' => $info['device_user_id'] ?? null],
                );
                $biotimeEmployee->setRelation('source', $source);
                $known[$key] = $biotimeEmployee;
                if ($biotimeEmployee->wasRecentlyCreated) {
                    $new[] = $biotimeEmployee;
                }

                continue;
            }

            $biotimeEmployee->areas = array_slice(array_values(array_unique(array_merge($biotimeEmployee->areas ?? [], $areas))), 0, 20);
            $biotimeEmployee->terminals = array_slice(array_values(array_unique(array_merge($biotimeEmployee->terminals ?? [], $terminals))), 0, 20);

            foreach (['device_name', 'device_user_id'] as $field) {
                if (($info[$field] ?? null) !== null) {
                    $biotimeEmployee->{$field} = $info[$field];
                }
            }

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
        // the start. Areas and terminals are already stored, so the branch
        // rule can use them.
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
            $terminal = BiotimeTerminal::createOrFirst(
                ['biotime_source_id' => $source->id, 'terminal_sn' => $sn],
                // Access-control terminals carry no area — a keyword in the name is the only first guess.
                ['branch_id' => $info['area'] === null && $info['alias'] !== null
                    ? BranchKeywordMatcher::match([$info['alias']], $this->branchMappings ??= AzureBranchMapping::all())
                    : null],
            );

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
}
