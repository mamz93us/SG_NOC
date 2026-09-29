<?php

namespace App\Services\OraclePortal;

use App\Models\ActivityLog;
use App\Models\OraclePortal\PortalSetting;
use App\Models\Vacation\VacationImport;
use App\Services\Vacation\VacationImporter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Pulls Oracle's leave balances and records and hands them to the importer the
 * spreadsheet upload already uses.
 *
 * There is no second code path: the same VacationImporter, the same row keys,
 * the same linker, the same withdrawal window. The only difference is where
 * the rows came from and, because of that, two decisions this class owns.
 *
 * **`as_of` is today.** The feed has no as_of, year or extract timestamp: it is
 * a live Oracle view, so a balance read today is as of today. That is strictly
 * better than the sheet, where HR types the date. It also means the year comes
 * from today's year, so the first pull in January opens the new year's rows by
 * itself. The importer's "an older sheet never overwrites a newer one" rule
 * compares with `gt`, not `gte`, so a same-day re-pull is idempotent rather
 * than blocked.
 *
 * **`withdrawMissing` is true, but only ever for a whole-book pull.** Oracle's
 * record feed is a rolling window — since 2026-09-27 from the first of the
 * previous month, before that 119 days back, plus everything booked ahead
 * either way — and the importer derives its withdrawal span from the rows it
 * is given, so leave cancelled in Oracle is withdrawn here and records older
 * than the window are left alone. That is exactly what the span logic is for.
 * It is also why a narrower pull must never use it: a per-person call would
 * hand the importer one person's dates as the span and withdraw every other
 * person's records inside it.
 *
 * **Two things stop a pull from withdrawing what it should not.** Oracle's
 * API writes leave dates a day early ({@see DateOffset}), and a record a day
 * out is a new record beside the real one with the real one withdrawn — 3,285
 * of them in the first dry run of this release. So the offset is judged and
 * applied first, or the run stops. And a pull that would still withdraw more
 * than {@see SyncGuards::MAX_WITHDRAWALS} records and a tenth of those it
 * covers is rolled back whole, because a feed that changed shape looks
 * exactly like a mass cancellation.
 */
class VacationSync
{
    /** A first run has no baseline; below these, the response is not believed. */
    private const FLOOR_BALANCES = 300;

    /**
     * ~730 records measured on 2026-09-29 — a month and a half of the book's
     * leave, down from 7,668 when the window reached four months back. The
     * floor only guards a first run; after that, half the last run's count
     * does, and the migration that reset that baseline for the narrower
     * window is why this is the number that matters.
     */
    private const FLOOR_RECORDS = 300;

    public function __construct(
        private PortalApiClient $api,
        private VacationImporter $importer,
        private PortalBook $book,
    ) {}

    /**
     * @return array{balances: VacationImport, absences: VacationImport, date_offset: int, dry_run: bool}
     *
     * @throws RuntimeException when the response is too small to act on
     */
    public function sync(bool $dryRun = false, ?int $userId = null, ?PortalSetting $settings = null): array
    {
        $settings ??= PortalSetting::get();

        if ($issue = $settings->configurationIssue()) {
            throw new RuntimeException($issue);
        }

        $book = $this->book->key();
        $asOf = CarbonImmutable::today();

        $balanceRows = $this->api->vacationBalances(null, $settings);
        $recordRows = $this->api->vacationDetails(null, $settings);

        $this->guard($balanceRows, $settings->last_vacation_balances_count, self::FLOOR_BALANCES, 'leave balances');
        $this->guard($recordRows, $settings->last_vacation_records_count, self::FLOOR_RECORDS, 'leave records');

        $offset = $this->book->dateOffset($recordRows);
        $recordRows = DateOffset::shift($recordRows, ['startDate', 'endDate'], $offset);

        // Neither leave endpoint carries personId; /employees does. Without it
        // the balances still import — they just carry no Oracle person id.
        $personIds = VacationRows::personIds($this->api->employees(null, $settings));

        $label = 'Oracle Employee Portal API';

        $run = function () use ($book, $asOf, $balanceRows, $recordRows, $personIds, $label, $userId) {
            // Balances first: they are what carry Oracle's person ids onto
            // vacation_employees, the same order the upload uses.
            $balances = $this->importer->importBalances(
                $book,
                $asOf,
                VacationRows::balances($balanceRows, $personIds),
                VacationImport::SOURCE_API,
                $label,
                $userId,
            );

            $absences = $this->importer->importAbsences(
                $book,
                VacationRows::absences($recordRows),
                VacationImport::SOURCE_API,
                $label,
                $userId,
                withdrawMissing: true,
                typesNotCarried: (array) config('oracle_portal.leave_types_not_sent', []),
            );

            // Thrown inside the transaction, so the whole pull rolls back.
            if (SyncGuards::refusesWithdrawals((int) $absences->removed, $absences->heldInWindow())) {
                throw new RuntimeException(SyncGuards::withdrawalReason((int) $absences->removed, $absences->heldInWindow()));
            }

            return ['balances' => $balances, 'absences' => $absences];
        };

        if ($dryRun) {
            // Same shape as OracleAssetImporter's dry run: do the real work so
            // the counts are the real counts, then throw it away.
            DB::beginTransaction();

            try {
                $imports = $run();
            } finally {
                DB::rollBack();
            }

            return $imports + ['date_offset' => $offset, 'dry_run' => true];
        }

        $imports = DB::transaction($run);

        foreach ($imports as $import) {
            $this->log($import, $userId, $offset);
        }

        $settings->forceFill([
            'last_vacations_sync_at' => now(),
            'last_vacation_balances_count' => count($balanceRows),
            'last_vacation_records_count' => count($recordRows),
        ])->save();

        return $imports + ['date_offset' => $offset, 'dry_run' => false];
    }

    /**
     * @throws RuntimeException
     */
    private function guard(array $rows, ?int $baseline, int $floor, string $noun): void
    {
        if (SyncGuards::refusesPayload(count($rows), $baseline, $floor)) {
            throw new RuntimeException(SyncGuards::payloadReason($noun, count($rows), $baseline, $floor));
        }
    }

    /**
     * One row per import, by hand: the four vacation models are outside the
     * automatic auditor, and the importer wraps its writes in
     * Auditor::withoutAuditing() so a re-import is one honest record rather
     * than thousands of per-row ones.
     */
    private function log(VacationImport $import, ?int $userId, int $dateOffset): void
    {
        ActivityLog::create([
            'model_type' => VacationImport::class,
            'model_id' => $import->id,
            'model_label' => $import->filename,
            'action' => 'vacation_import',
            'changes' => [
                'book' => $import->book,
                'kind' => $import->kind,
                'source' => $import->source,
                'as_of' => $import->as_of?->toDateString(),
                'summary' => $import->summary(),
                'date_offset_days' => $dateOffset,
            ],
            'user_id' => $userId,
        ]);
    }
}
