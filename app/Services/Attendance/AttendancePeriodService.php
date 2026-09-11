<?php

namespace App\Services\Attendance;

use App\Models\Attendance\AttendanceDay;
use App\Models\Attendance\AttendanceExport;
use App\Models\Attendance\AttendancePeriod;
use App\Models\Attendance\AttendancePunch;
use App\Models\Attendance\AttendanceTask;
use App\Models\Employee;
use App\Services\Attendance\Oracle\OracleAttendanceSender;
use App\Services\Attendance\Oracle\SendResult;
use App\Services\Attendance\Oracle\StubOracleAttendanceSender;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The life of a pay period: is it ready, approve and lock it, reopen it,
 * build its Oracle payload and hand it to the sender.
 *
 * A period is approved only when nothing in it is still wrong — no day with a
 * data error, nobody without an Oracle number, its last day over, no
 * background work still changing it. Approving locks its days: from then on
 * the processor leaves them exactly as approved, so the payload sent is the
 * payload HR signed off. Reopening is explicit, logged, and recalculates.
 */
class AttendancePeriodService
{
    /** Columns of a payload record, in CSV order. */
    public const RECORD_FIELDS = [
        'oracle_emp_no', 'employee_name', 'branch', 'date', 'status', 'shift', 'scheduled_in', 'scheduled_out',
        'check_in', 'check_out', 'worked_minutes', 'late_minutes', 'early_leave_minutes', 'overtime_minutes',
        'excuse', 'corrected',
    ];

    public function __construct(private ?OracleAttendanceSender $sender = null) {}

    public function sender(): OracleAttendanceSender
    {
        return $this->sender ??= app(config('attendance.oracle_sender', StubOracleAttendanceSender::class));
    }

    /**
     * @return array{days: int, people: int, errors: int, by_flag: array<string, int>,
     *               missing_oracle: \Illuminate\Support\Collection, open_tasks: int, blockers: list<string>}
     */
    public function readiness(AttendancePeriod $period): array
    {
        $days = $period->daysQuery();

        // Counted in PHP from the error days alone: portable, and there are few.
        $byFlag = [];
        foreach ((clone $days)->where('has_error', true)->pluck('flags') as $flags) {
            foreach ((array) $flags as $flag) {
                if (in_array($flag, AttendanceDayBuilder::ERRORS, true)) {
                    $byFlag[$flag] = ($byFlag[$flag] ?? 0) + 1;
                }
            }
        }
        $errors = (clone $days)->where('has_error', true)->count();

        $missingOracle = Employee::query()
            ->whereIn('id', (clone $days)->whereNotNull('employee_id')->select('employee_id'))
            ->where(fn ($q) => $q->whereNull('oracle_emp_no')->orWhere('oracle_emp_no', ''))
            ->orderBy('name')
            ->get(['id', 'name']);

        $openTasks = AttendanceTask::query()
            ->whereIn('status', [AttendanceTask::PENDING, AttendanceTask::RUNNING])
            ->whereIn('type', ['sync', 'rebuild', 'relink'])
            ->count();

        $blockers = [];
        if ($period->status !== AttendancePeriod::OPEN) {
            $blockers[] = 'The period is already approved.';
        }
        if ($period->date_to->toDateString() >= CarbonImmutable::today()->toDateString()) {
            $blockers[] = "The period runs until {$period->date_to->format('d M Y')} — approve it once that day is over, when absences are final.";
        }
        if ($errors > 0) {
            $blockers[] = "{$errors} day(s) still have a data error — fix or excuse them first.";
        }
        if ($missingOracle->isNotEmpty()) {
            $blockers[] = $missingOracle->count().' employee(s) have no Oracle number, so Oracle could not take their days.';
        }
        if ($openTasks > 0) {
            $blockers[] = 'Background work is still running (sync, recalculation or re-matching) — wait until it finishes.';
        }

        return [
            'days' => (clone $days)->count(),
            'people' => (clone $days)->distinct()->count('subject_key'),
            'errors' => $errors,
            'by_flag' => $byFlag,
            'missing_oracle' => $missingOracle,
            'open_tasks' => $openTasks,
            'blockers' => $blockers,
        ];
    }

    /** Approves and locks the period, then queues its Oracle export. */
    public function approve(AttendancePeriod $period, ?int $userId): void
    {
        $blockers = $this->readiness($period)['blockers'];
        if ($blockers !== []) {
            throw new RuntimeException(implode(' ', $blockers));
        }

        DB::transaction(function () use ($period, $userId) {
            $period->daysQuery()
                ->whereNotNull('employee_id')
                ->update(['locked' => true, 'attendance_period_id' => $period->id]);

            $period->forceFill([
                'status' => AttendancePeriod::APPROVED,
                'approved_by' => $userId,
                'approved_at' => now(),
            ])->save();
        });

        AttendanceTask::queue('export', ['period_id' => $period->id], "Prepare the Oracle export: {$period->name}", $userId);
    }

    /** Unlocks the period's days and queues a recalculation, so everything since approval applies. */
    public function reopen(AttendancePeriod $period, ?int $userId, string $reason): void
    {
        if (! $period->isLocked()) {
            throw new RuntimeException('The period is not approved.');
        }

        DB::transaction(function () use ($period, $userId, $reason) {
            AttendanceDay::where('attendance_period_id', $period->id)->update(['locked' => false, 'attendance_period_id' => null]);

            $period->forceFill([
                'status' => AttendancePeriod::OPEN,
                'approved_by' => null,
                'approved_at' => null,
                'reopened_by' => $userId,
                'reopened_at' => now(),
                'reopen_reason' => mb_substr($reason, 0, 1000),
            ])->save();
        });

        AttendanceTask::queue('rebuild', [
            'from' => $period->date_from->toDateString(),
            'to' => $period->date_to->toDateString(),
            'employee_ids' => $period->branch_id ? Employee::where('branch_id', $period->branch_id)->pluck('id')->all() : null,
        ], "Recalculate {$period->name} (reopened)", $userId);
    }

    /**
     * Punches synced after approval for people in this period — they are
     * stored but, the days being locked, not in the approved figures.
     */
    public function punchesAfterApproval(AttendancePeriod $period): int
    {
        if (! $period->approved_at) {
            return 0;
        }

        return AttendancePunch::query()
            ->where('punch_time', '>=', $period->date_from->toDateString().' 00:00:00')
            ->where('punch_time', '<', $period->date_to->copy()->addDay()->toDateString().' 00:00:00')
            ->where('synced_at', '>', $period->approved_at)
            ->whereIn('employee_id', AttendanceDay::where('attendance_period_id', $period->id)->select('employee_id'))
            ->count();
    }

    /** One record per employee per day, as approved. */
    public function buildPayload(AttendancePeriod $period): array
    {
        $records = [];

        $period->daysQuery()
            ->whereNotNull('employee_id')
            ->with(['employee:id,name,oracle_emp_no', 'shift:id,name', 'branch:id,name'])
            ->orderBy('work_date')
            ->orderBy('employee_id')
            ->lazy(1000)
            ->each(function (AttendanceDay $d) use (&$records) {
                $records[] = [
                    'oracle_emp_no' => (string) $d->employee?->oracle_emp_no,
                    'employee_name' => $d->employee?->name,
                    'branch' => $d->branch?->name,
                    'date' => $d->work_date->toDateString(),
                    'status' => $d->status,
                    'shift' => $d->shift?->name,
                    'scheduled_in' => $d->scheduled_start?->format('Y-m-d H:i:s'),
                    'scheduled_out' => $d->scheduled_end?->format('Y-m-d H:i:s'),
                    'check_in' => $d->first_in?->format('Y-m-d H:i:s'),
                    'check_out' => $d->last_out?->format('Y-m-d H:i:s'),
                    'worked_minutes' => $d->worked_minutes,
                    'late_minutes' => $d->late_minutes,
                    'early_leave_minutes' => $d->early_leave_minutes,
                    'overtime_minutes' => $d->overtime_minutes,
                    'excuse' => $d->excuse,
                    'corrected' => in_array(AttendanceDayBuilder::FLAG_ADJUSTED, $d->flags ?? [], true),
                ];
            });

        return [
            'period' => [
                'id' => $period->id,
                'name' => $period->name,
                'branch' => $period->branch?->name,
                'from' => $period->date_from->toDateString(),
                'to' => $period->date_to->toDateString(),
                'approved_at' => $period->approved_at?->toIso8601String(),
            ],
            'generated_at' => now()->toIso8601String(),
            'record_count' => count($records),
            'records' => $records,
        ];
    }

    /** Builds the payload of an approved period, hands it to the sender and records the attempt. */
    public function export(AttendancePeriod $period, ?int $userId): AttendanceExport
    {
        if (! $period->isLocked()) {
            throw new RuntimeException('Approve the period before exporting it.');
        }

        $payload = $this->buildPayload($period);
        $sender = $this->sender();

        try {
            $result = $sender->send($payload);
        } catch (\Throwable $e) {
            $result = SendResult::failed(BioTimeConnection::cleanError($e));
        }

        $export = AttendanceExport::create([
            'attendance_period_id' => $period->id,
            'sender' => class_basename($sender),
            'status' => $result->status,
            'record_count' => $payload['record_count'],
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'response' => $result->response,
            'reference' => $result->reference,
            'message' => $result->message,
            'created_by' => $userId,
        ]);

        if ($result->status === AttendanceExport::SENT) {
            $period->forceFill(['status' => AttendancePeriod::SENT, 'sent_at' => now()])->save();
        }

        return $export;
    }
}
