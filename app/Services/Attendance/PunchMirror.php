<?php

namespace App\Services\Attendance;

use App\Models\ActivityLog;
use App\Models\Attendance\AttendanceDay;
use App\Models\Attendance\AttendancePunch;
use App\Models\Attendance\BiotimeSource;
use App\Services\Attendance\Mirror\PunchDiff;
use App\Services\Attendance\Mirror\RemovalGuard;
use App\Services\Attendance\Readers\PunchReaders;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * Makes the NOC's copy of a window of punches match the source database again.
 *
 * biotime:sync only ever moves forwards: it reads past its watermark, upserts
 * what it finds and never looks at a row again. So a punch EDITED in ZKTeco
 * after it was copied — a time corrected, a scan moved to another badge — and
 * a punch DELETED there both stayed in the NOC for good, still driving that
 * day's check-in and check-out. Counting punches per day, which is all
 * biotime:reconcile used to do, cannot see an edit at all: the count is the
 * same on both sides.
 *
 * So the comparison is by row. The NOC's punches for the window are loaded
 * once, the source is paged over the same window, and each row is matched on
 * its external id and compared field by field (PunchDiff):
 *
 *   in the source, not here          → written, exactly as a sync would
 *   in both, a source field differs  → rewritten to the source's version
 *   here, not in the source          → stamped removed_at, never deleted
 *
 * Every day either side of a change is rebuilt, including the day a punch
 * LEFT when an edit moved it — otherwise the old day keeps showing a punch
 * that is now somewhere else.
 *
 * Two things are never touched:
 *
 *   An approved period. What HR signed off is what Oracle pulls, and Oracle
 *   reads the day's punches, not only its totals — so changing or removing
 *   one underneath an approved day would rewrite a closed month without the
 *   day itself ever changing. Those rows are counted and reported; reopening
 *   the period is a person's decision.
 *
 *   A window the source did not fully answer. Removals are applied only after
 *   a complete pass, and only if RemovalGuard allows their number: a restored,
 *   repointed or retention-pruned source looks exactly like a mass deletion.
 *
 * Adding a punch to an approved day is NOT blocked here — biotime:sync has
 * always done that, and blocking it in one path only would be a difference
 * nobody could explain.
 */
class PunchMirror
{
    /** Rows per read from the source. Tests lower it to exercise paging. */
    public int $chunk = BioTimeSyncService::CHUNK;

    /** Punches per upsert, and per day rebuild. */
    private const WRITE_CHUNK = 1000;

    private const REPORT_SAMPLE = 20;

    private PunchReaders $readers;

    public function __construct(
        private BioTimeConnection $bioTime,
        private BioTimeSyncService $sync,
        private AttendanceDayProcessor $processor,
        ?PunchReaders $readers = null,
    ) {
        $this->readers = $readers ?? new PunchReaders;
    }

    /**
     * @param  string  $from  Y-m-d, inclusive
     * @param  string  $to  Y-m-d, inclusive
     * @param  bool  $apply  false only reports what differs
     * @param  bool  $allowBulk  a person has looked and wants the removals anyway
     * @return array{status: string, from: string, to: string, source_rows: int, noc_rows: int, skipped: int,
     *               added: int, changed: int, removed: int, locked: int, days: int, done: bool, applied: bool,
     *               refused: ?string, by_date: array<string, array{source: int, noc: int}>, removed_sample: list<string>}
     */
    public function run(BiotimeSource $source, string $from, string $to, bool $apply, bool $allowBulk = false, ?int $maxSeconds = null): array
    {
        // The same lock biotime:sync takes. The two write the same rows, and a
        // sync inserting punches while the mirror is working out what the
        // source no longer holds would have it stamp them as they arrive.
        $lock = Cache::lock('biotime-sync:'.$source->id, 3600);

        if (! $lock->get()) {
            return ['status' => 'busy'] + $this->blank($from, $to);
        }

        try {
            return $this->compare($source, $from, $to, $apply, $allowBulk, $maxSeconds);
        } finally {
            $lock->release();
        }
    }

    /**
     * The fields the source owns, as one short hash. Any of them different
     * here means the source changed the punch. employee_id and
     * biotime_employee_id are left out on purpose: those are the NOC's own
     * matching, re-decided by EmployeeLinker, not anything the source said.
     *
     * @param  array<string, mixed>|object  $punch  a normalised punch, or an attendance_punches row
     */
    public static function fingerprint(array|object $punch): string
    {
        $punch = (array) $punch;

        $parts = [
            ($punch['biotime_id'] ?? null) !== null ? (string) (int) $punch['biotime_id'] : '',
            (string) ($punch['emp_code'] ?? ''),
            substr((string) ($punch['punch_time'] ?? ''), 0, 19),
            (string) ($punch['punch_state'] ?? ''),
            (string) ($punch['terminal_sn'] ?? ''),
            (string) ($punch['terminal_alias'] ?? ''),
            (string) ($punch['area_alias'] ?? ''),
        ];

        return hash('xxh3', implode("\x1f", $parts));
    }

    // ─────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function compare(BiotimeSource $source, string $from, string $to, bool $apply, bool $allowBulk, ?int $maxSeconds): array
    {
        $db = $this->bioTime->connection($source);
        $reader = $this->readers->for($source);
        $deadline = $maxSeconds ? microtime(true) + $maxSeconds : null;

        $window = [$from.' 00:00:00', CarbonImmutable::parse($to)->addDay()->toDateString().' 00:00:00'];

        $byDate = [];
        $local = $this->localRows($source, $window, $byDate);
        $diff = new PunchDiff($local);
        $locked = $this->lockedWindows($from, $to);

        $result = ['status' => 'ok'] + $this->blank($from, $to);
        $result['noc_rows'] = count($local);
        unset($local);

        $syncedAt = Carbon::now();
        $buffer = [];
        $added = [];
        $touched = [];
        $cursor = $reader->floorCursor();

        while ($deadline === null || microtime(true) < $deadline) {
            $batch = $reader->fetch($source, $db, $cursor, $this->chunk, $window);

            if ($batch->isEmpty()) {
                $result['done'] = true;
                break;
            }

            foreach ($batch as $row) {
                $punch = $reader->normalise($row, $source);

                if ($punch === null) {
                    $result['skipped']++;

                    continue;
                }

                $result['source_rows']++;
                $date = substr($punch['punch_time'], 0, 10);
                $byDate[$date]['source'] = ($byDate[$date]['source'] ?? 0) + 1;

                [$verdict, $known] = $diff->see($punch['external_id'], self::fingerprint($punch));

                if ($verdict === PunchDiff::SAME) {
                    continue;
                }

                // A rewrite under an approved day would change what Oracle
                // reads back for a month that is already signed off. Both the
                // day it sits in and the one the edit moves it to count.
                if ($verdict === PunchDiff::CHANGED
                    && $this->isLocked($locked, $known['subject'], [$known['punch_time'], $punch['punch_time']])) {
                    $result['locked']++;

                    continue;
                }

                $result[$verdict === PunchDiff::ADDED ? 'added' : 'changed']++;

                if (! $apply) {
                    continue;
                }

                $buffer[] = $punch;

                if ($known !== null) {
                    // The day it is leaving, when an edit moved it.
                    $touched[$known['subject']][$known['date']] = true;
                } else {
                    $added[] = $punch['external_id'];
                }

                if (count($buffer) >= self::WRITE_CHUNK) {
                    $result['days'] += $this->write($source, $buffer, $added, $syncedAt, $touched);
                }
            }

            $cursor = $reader->cursorAfter($batch->last());

            if ($batch->count() < $this->chunk) {
                $result['done'] = true;
                break;
            }
        }

        $result['days'] += $this->write($source, $buffer, $added, $syncedAt, $touched);
        $result['applied'] = $apply;

        $result = $this->applyRemovals($result, $diff->removed(), $locked, $apply, $allowBulk);

        ksort($byDate);
        $result['by_date'] = array_map(fn (array $counts) => $counts + ['source' => 0, 'noc' => 0], $byDate);

        if ($apply && ($result['added'] || $result['changed'] || $result['removed'] || $result['refused'])) {
            $this->log($source, $result);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  list<array<string, mixed>>  $removed
     * @param  array<string, list<array{from: string, to: string}>>  $locked
     * @return array<string, mixed>
     */
    private function applyRemovals(array $result, array $removed, array $locked, bool $apply, bool $allowBulk): array
    {
        $removable = [];

        foreach ($removed as $row) {
            if ($this->isLocked($locked, $row['subject'], [$row['punch_time']])) {
                $result['locked']++;

                continue;
            }
            $removable[] = $row;
        }

        $result['removed'] = count($removable);
        $result['removed_sample'] = array_map(
            fn (array $row) => $row['emp_code'].' at '.$row['punch_time'],
            array_slice($removable, 0, self::REPORT_SAMPLE),
        );

        // A pass that ran out of time never saw the rest of the source, so
        // everything it did not reach looks deleted. Nothing is stamped.
        $result['refused'] = $removable !== [] && ! $result['done']
            ? 'the source was not read to the end within the time budget, so what is missing is not yet known'
            : RemovalGuard::refuse($result['noc_rows'], $result['source_rows'], count($removable), $allowBulk);

        if ($result['refused'] !== null) {
            $result['removed'] = 0;
        }

        if (! $apply || $result['refused'] !== null || $removable === []) {
            return $result;
        }

        $touched = [];

        foreach (array_chunk($removable, self::WRITE_CHUNK) as $chunk) {
            AttendancePunch::whereIn('id', array_column($chunk, 'id'))->delete();

            foreach ($chunk as $row) {
                $touched[$row['subject']][$row['date']] = true;
            }
        }

        $result['days'] += $this->processor->rebuild($touched);

        return $result;
    }

    /**
     * Writes a page of punches and rebuilds the days they touch.
     *
     * @param  list<array<string, mixed>>  $buffer
     * @param  list<string>  $added  external ids the window did not hold
     * @param  array<string, array<string, true>>  $touched
     */
    private function write(BiotimeSource $source, array &$buffer, array &$added, Carbon $syncedAt, array &$touched): int
    {
        if ($buffer === []) {
            return 0;
        }

        $this->oldDaysOf($source, $added, $touched);
        $this->sync->store($source, $buffer, $syncedAt, $touched);

        $days = $this->processor->rebuild($touched);

        $buffer = [];
        $added = [];
        $touched = [];

        return $days;
    }

    /**
     * A punch the window did not hold may still exist here, outside it: an
     * edit that moved it to another date, or one the NOC had stamped removed
     * and the source has again. Either way the day it is leaving is rebuilt —
     * the upsert is about to move the row out of it.
     *
     * @param  list<string>  $externalIds
     * @param  array<string, array<string, true>>  $touched
     */
    private function oldDaysOf(BiotimeSource $source, array $externalIds, array &$touched): void
    {
        if ($externalIds === []) {
            return;
        }

        foreach (array_chunk($externalIds, self::WRITE_CHUNK) as $chunk) {
            AttendancePunch::withTrashed()
                ->where('biotime_source_id', $source->id)
                ->whereIn('external_id', $chunk)
                ->get(['employee_id', 'biotime_employee_id', 'punch_time'])
                ->each(function (AttendancePunch $punch) use (&$touched) {
                    $touched[$punch->subjectKey()][$punch->punch_time->format('Y-m-d')] = true;
                });
        }
    }

    /**
     * The NOC's punches for the window, keyed by external id, with the day
     * each belongs to so it can be rebuilt when the punch changes.
     *
     * Already-removed punches are left out — Eloquent's soft-delete scope does
     * that — so one the source has again reads as new and the upsert revives it.
     *
     * @param  array{0: string, 1: string}  $window
     * @param  array<string, array{source?: int, noc?: int}>  $byDate
     * @return array<string, array<string, mixed>>
     */
    private function localRows(BiotimeSource $source, array $window, array &$byDate): array
    {
        $rows = [];

        AttendancePunch::query()
            ->where('biotime_source_id', $source->id)
            ->where('punch_time', '>=', $window[0])
            ->where('punch_time', '<', $window[1])
            ->toBase()
            ->select(['id', 'external_id', 'employee_id', 'biotime_employee_id', 'biotime_id', 'emp_code',
                'punch_time', 'punch_state', 'terminal_sn', 'terminal_alias', 'area_alias'])
            ->orderBy('id')
            ->chunkById(5000, function ($chunk) use (&$rows, &$byDate) {
                foreach ($chunk as $row) {
                    $time = substr((string) $row->punch_time, 0, 19);
                    $date = substr($time, 0, 10);
                    $byDate[$date]['noc'] = ($byDate[$date]['noc'] ?? 0) + 1;

                    $rows[(string) $row->external_id] = [
                        'id' => (int) $row->id,
                        'fingerprint' => self::fingerprint($row),
                        'subject' => $row->employee_id ? 'emp:'.(int) $row->employee_id : 'bt:'.(int) $row->biotime_employee_id,
                        'date' => $date,
                        'punch_time' => $time,
                        'emp_code' => (string) $row->emp_code,
                    ];
                }
            });

        return $rows;
    }

    /**
     * The approved days covering any part of the window, by subject, as the
     * wall-clock stretch each one owns — a day is a window, not a date, once
     * an overnight shift is in use. A day one date before the range can still
     * reach into it.
     *
     * @return array<string, list<array{from: string, to: string}>>
     */
    private function lockedWindows(string $from, string $to): array
    {
        $locked = [];

        AttendanceDay::query()
            ->where('locked', true)
            ->whereBetween('work_date', [CarbonImmutable::parse($from)->subDay()->toDateString(), $to])
            ->get(['subject_key', 'work_date', 'window_start', 'window_end'])
            ->each(function (AttendanceDay $day) use (&$locked) {
                [$start, $end] = $day->window();
                $locked[(string) $day->subject_key][] = ['from' => $start, 'to' => $end];
            });

        return $locked;
    }

    /**
     * @param  array<string, list<array{from: string, to: string}>>  $locked
     * @param  list<string>  $times  any of these inside an approved day protects the punch
     */
    private function isLocked(array $locked, string $subject, array $times): bool
    {
        foreach ($locked[$subject] ?? [] as $day) {
            foreach ($times as $time) {
                if ($time >= $day['from'] && $time < $day['to']) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param  array<string, mixed>  $result */
    private function log(BiotimeSource $source, array $result): void
    {
        ActivityLog::create([
            'model_type' => 'BiotimeSource',
            'model_id' => $source->id,
            'action' => 'attendance_punches_mirrored',
            'changes' => [
                'source' => $source->name,
                'from' => $result['from'],
                'to' => $result['to'],
                'added' => $result['added'],
                'changed' => $result['changed'],
                'removed' => $result['removed'],
                'left_alone_approved' => $result['locked'],
                'refused' => $result['refused'],
                'removed_punches' => $result['removed_sample'],
            ],
            'user_id' => Auth::id(),
        ]);
    }

    /** @return array<string, mixed> */
    private function blank(string $from, string $to): array
    {
        return [
            'from' => $from,
            'to' => $to,
            'source_rows' => 0,
            'noc_rows' => 0,
            'skipped' => 0,
            'added' => 0,
            'changed' => 0,
            'removed' => 0,
            'locked' => 0,
            'days' => 0,
            'done' => false,
            'applied' => false,
            'refused' => null,
            'by_date' => [],
            'removed_sample' => [],
        ];
    }
}
