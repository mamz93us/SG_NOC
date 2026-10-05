<?php

namespace App\Services\OraclePortal;

use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\HrImportBatch;
use App\Models\OraclePortal\PortalSetting;
use App\Services\Identity\OracleHrImportService;
use App\Support\Audit\Auditor;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Pulls Oracle's employee view and stages it for review.
 *
 * ONE RULE GOVERNS THIS CLASS: nothing here ever sets `status = 'terminated'`.
 *
 * `EmployeeObserver` fires on that transition and calls
 * `GraphService::disableUser()`, so a termination written by a scheduled job
 * takes away somebody's Microsoft account. Two separate reasons it must never
 * be automatic here:
 *
 *  - **Absence from the feed means nothing.** The Employee Portal serves the
 *    SamirGroup Saudi book: 617 people in Riyadh, Jeddah, Al-Khobar and Abha.
 *    Every SSS Egypt employee is permanently absent from it, as is anyone
 *    Oracle has not onboarded yet. A missing-means-gone rule would, on its
 *    first run, try to disable every Egyptian employee's account. That is
 *    wrong semantics, not a threshold that needs tuning — no count guard makes
 *    it right.
 *  - **Inactive is Oracle's word, not a decision.** Oracle marks somebody
 *    Inactive once their termination date arrives and keeps them in the list
 *    for a month. A termination date keyed in by mistake, or one that is
 *    reversed a week later, reads exactly the same. Six rows say Inactive
 *    today, which is a minute of somebody's attention against the cost of
 *    locking a working colleague out of their mailbox.
 *
 * So what Oracle says is recorded — `employees.oracle_assignment_status` — and
 * what the NOC does about it stays a person's decision. Those two columns being
 * separate is the whole safety design.
 *
 * The same goes for the manager and supervisor. Oracle's are recorded as a name
 * and an address (`oracle_manager_*`, `oracle_supervisor_*`) and shown on the
 * profile; `manager_id` and `supervisor_id` are never written here, because
 * they decide whose attendance and leave somebody may read and where an
 * approval goes.
 *
 * Hire dates arrive a day early from Oracle's API; {@see DateOffset} judges
 * that from the leave feed on every run and corrects it, or stops the run.
 *
 * Everything consequential (job title, department, branch, category, Arabic
 * name, hire date) still goes through the existing review page, because the
 * matcher needs two signals to agree and what it refuses has to land in front
 * of somebody.
 */
class EmployeeSync
{
    /** A first run with fewer than this is not believed. 617 measured. */
    private const FLOOR = 400;

    public function __construct(
        private PortalApiClient $api,
        private OracleHrImportService $importer,
        private PortalBook $book,
    ) {}

    /**
     * @return array{batch: ?HrImportBatch, rows: int, unchanged: bool, inactive: int,
     *               recorded: int, leavers_refused: bool, date_offset: int, dry_run: bool}
     *
     * @throws RuntimeException when the response is too small to act on
     */
    public function sync(bool $dryRun = false, ?int $userId = null, ?PortalSetting $settings = null): array
    {
        $settings ??= PortalSetting::get();

        if ($issue = $settings->configurationIssue()) {
            throw new RuntimeException($issue);
        }

        $rows = $this->api->employees(null, $settings);

        if (SyncGuards::refusesPayload(count($rows), $settings->last_employees_count, self::FLOOR)) {
            throw new RuntimeException(SyncGuards::payloadReason(
                'employees', count($rows), $settings->last_employees_count, self::FLOOR
            ));
        }

        // Fail early and loudly if the book is misconfigured, before anything
        // is staged: without it there is no way to tell a SamirGroup employee
        // from an SSS Egypt one holding the same Oracle number.
        $this->book->branchIds();

        // Before anything is mapped, so the digest, the staged rows and the
        // review page all see the corrected date.
        $offset = $this->book->dateOffset($this->api->vacationDetails(null, $settings));
        $rows = DateOffset::shift($rows, ['startDate'], $offset);

        $facts = EmployeeFacts::rows($rows);
        $digest = OracleHrImportService::digestOf($facts);
        $previous = HrImportBatch::query()->where('source', 'api')->latest('id')->first();

        $result = [
            'batch' => null,
            'rows' => count($facts),
            'unchanged' => false,
            'inactive' => 0,
            'recorded' => 0,
            'leavers_refused' => false,
            'date_offset' => $offset,
            'dry_run' => $dryRun,
        ];

        if ($previous && $previous->source_digest === $digest) {
            // Oracle has not changed since the last pull. Creating another
            // identical batch would fill the review page with nothing to review.
            $result['unchanged'] = true;

            if (! $dryRun) {
                $settings->forceFill([
                    'last_employees_sync_at' => now(),
                    'last_employees_count' => count($rows),
                ])->save();
            }

            return $result;
        }

        $label = 'Oracle Employee Portal API — '.now()->format('d M Y H:i');

        // The importer is handed Oracle's own payload and maps it with
        // EmployeeFacts itself, so the mapping happens in exactly one place.
        // Mapping here and un-mapping for the importer would give the two a
        // way to disagree about the same row.
        $run = function () use ($rows, $facts, $label, $userId, &$result) {
            $result['batch'] = $this->importer->fromApi($rows, $label, $userId);

            $recorded = Auditor::withoutAuditing(fn () => $this->recordOracleFacts($facts));

            $result['inactive'] = $recorded['inactive'];
            $result['recorded'] = $recorded['recorded'];
            $result['leavers_refused'] = $recorded['refused'];

            return $result;
        };

        if ($dryRun) {
            DB::beginTransaction();

            try {
                $run();
            } finally {
                DB::rollBack();
            }

            return $result;
        }

        $run();

        $this->log($result, $userId);

        $settings->forceFill([
            'last_employees_sync_at' => now(),
            'last_employees_count' => count($rows),
        ])->save();

        return $result;
    }

    /**
     * Record what Oracle says about each matched person — their assignment
     * status, person id, job category, profession, contract end date, and the
     * manager and supervisor Oracle names — and nothing more.
     *
     * These columns are inert — no observer watches them and no workflow
     * reads them — which is exactly why they can be written without review. It
     * is what makes "Oracle says INACTIVE" visible on the employee page and on
     * the review list without it being an action.
     *
     * The profile fields mirror Oracle, blanks included: a contract end date
     * Oracle has withdrawn, or a manager it no longer names, must not stay on
     * the profile as if Oracle still said it. The one exception is a field
     * nobody in the response carries at all — that is Oracle having dropped
     * the column, as it did three others on 2026-09-27, and what was last
     * recorded is worth more than 617 blanks.
     *
     * A person Oracle calls ACTIVE again has any earlier "ignore this leaver"
     * decision cleared, so a real departure later is offered afresh.
     *
     * @param  list<array<string,mixed>>  $facts
     * @return array{inactive:int, recorded:int, refused:bool}
     */
    private function recordOracleFacts(array $facts): array
    {
        $byNumber = [];

        foreach ($facts as $row) {
            $number = ltrim(trim((string) ($row['emp_no'] ?? '')), '0');

            if ($number !== '') {
                $byNumber[$number] = $row;
            }
        }

        $branchIds = $this->book->branchIds();

        $employees = Employee::query()
            ->whereNotNull('oracle_emp_no')
            ->where('oracle_emp_no', '<>', '')
            ->whereNull('linked_primary_employee_id')
            ->where(fn ($q) => $q->whereIn('branch_id', $branchIds)->orWhereNull('branch_id'))
            ->get(['id', 'oracle_emp_no', 'branch_id', 'status',
                'oracle_assignment_status', 'oracle_person_id', 'oracle_leaver_ignored_at',
                ...array_values(EmployeeFacts::PROFILE_FIELDS)]);

        $carried = array_filter(
            EmployeeFacts::PROFILE_FIELDS,
            fn (string $fact) => array_filter(array_column($facts, $fact)) !== [],
            ARRAY_FILTER_USE_KEY,
        );

        $inactive = 0;
        $matched = 0;
        $pending = [];

        foreach ($employees as $employee) {
            $number = ltrim(trim((string) $employee->oracle_emp_no), '0');
            $row = $byNumber[$number] ?? null;

            if ($row === null) {
                continue;
            }

            $matched++;
            $status = $row['assignment_status'] ?? null;

            if (EmployeeFacts::isInactive($status) && $employee->status !== 'terminated') {
                $inactive++;
            }

            $pending[] = [$employee, $status, $row];
        }

        // Too many at once is far likelier to be a broken response than a
        // genuine clear-out. Recording it would flood the review list, so the
        // statuses are left as they were and the run says so.
        if (SyncGuards::refusesLeavers($inactive, $matched)) {
            return ['inactive' => $inactive, 'recorded' => 0, 'refused' => true];
        }

        $recorded = 0;

        foreach ($pending as [$employee, $status, $row]) {
            $personId = $row['person_id'] ?? null;
            $attrs = [];

            if ($employee->oracle_assignment_status !== $status) {
                $attrs['oracle_assignment_status'] = $status;
            }

            if ($personId && $employee->oracle_person_id !== $personId) {
                $attrs['oracle_person_id'] = $personId;
            }

            // Back in Oracle's good books: a stale "ignore" must not hide a
            // genuine departure later on.
            if (! EmployeeFacts::isInactive($status) && $employee->oracle_leaver_ignored_at !== null) {
                $attrs['oracle_leaver_ignored_at'] = null;
            }

            foreach ($carried as $fact => $column) {
                $said = $row[$fact] ?? null;
                $held = $employee->getAttribute($column);

                if ($held instanceof \DateTimeInterface) {
                    $held = $held->format('Y-m-d');
                }

                if ($held !== $said) {
                    $attrs[$column] = $said;
                }
            }

            if ($attrs !== []) {
                $employee->forceFill($attrs)->save();
                $recorded++;
            }
        }

        return ['inactive' => $inactive, 'recorded' => $recorded, 'refused' => false];
    }

    private function log(array $result, ?int $userId): void
    {
        ActivityLog::create([
            'model_type' => HrImportBatch::class,
            'model_id' => $result['batch']?->id ?? 0,
            'model_label' => 'Oracle Employee Portal',
            'action' => 'portal_employees_staged',
            'changes' => [
                'rows' => $result['rows'],
                'matched' => $result['batch']?->matched_count,
                'unmatched' => $result['batch']?->unmatched_count,
                'inactive_in_oracle' => $result['inactive'],
                'statuses_recorded' => $result['recorded'],
                'leavers_refused' => $result['leavers_refused'],
                'date_offset_days' => $result['date_offset'],
            ],
            'user_id' => $userId,
        ]);
    }
}
