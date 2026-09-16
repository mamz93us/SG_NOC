<?php

namespace App\Services\People;

use App\Models\ActivityLog;
use App\Models\Attendance\AttendanceTask;
use App\Models\Employee;
use App\Models\IdentityUser;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Turns two NOC employee records that are the same person into one.
 *
 * The usual pair is a mailbox record (Microsoft account, licences, assets)
 * next to a service record the Oracle HR import created for the same person
 * (Oracle number, BioTime codes, punches, Oracle leave): two half-profiles,
 * each missing what the other holds. Linking them keeps both; merging moves
 * everything onto one record and deletes the other.
 *
 * The record kept is the one with a live Microsoft account, then a current
 * one, then the one Oracle HR knows, then the older one. Empty fields on it
 * are filled from the duplicate, and nothing is overwritten. Every row that
 * points at the duplicate is moved to it. The duplicate's full row, the moved
 * row ids and what was filled go to the activity log as
 * `employee_records_merged`, so a merge can be traced and undone by hand.
 */
final class EmployeeMerger
{
    /**
     * Every column that holds an employee id, checked against NOC2's schema on
     * 2026-09-16: the foreign keys plus the columns that have none, such as
     * attendance_punches.employee_id. A new table that points at employees
     * belongs here the same day, or a merge leaves its rows pointing at a deleted
     * record; EmployeeMergerTest compares this list with the migrations.
     */
    public const REFERENCES = [
        'accessory_assignments' => ['employee_id'],
        'ai_conversations' => ['employee_id'],
        'attendance_adjustments' => ['employee_id'],
        'attendance_days' => ['employee_id'],
        'attendance_owners' => ['employee_id'],
        'attendance_punches' => ['employee_id'],
        'avepoint_backups' => ['subject_employee_id'],
        'biotime_employees' => ['employee_id'],
        'course_certificates' => ['employee_id'],
        'employee_app_accounts' => ['employee_id'],
        'employee_assets' => ['employee_id'],
        'employee_items' => ['employee_id'],
        'employee_printer' => ['employee_id'],
        'employee_signature_roles' => ['employee_id'],
        'employees' => ['linked_primary_employee_id', 'manager_id', 'supervisor_id'],
        'hr_import_rows' => ['linked_employee_id', 'matched_employee_id'],
        'knowbe4_scores' => ['employee_id'],
        'offboarding_tokens' => ['employee_id'],
        'offboarding_workflows' => ['employee_id', 'asset_target_employee_id'],
        'printer_deploy_tokens' => ['employee_id'],
        'vacation_employees' => ['employee_id'],
    ];

    /** Never carried over: the record's identity and lifecycle, and the unique card token. */
    private const NOT_COPIED = [
        'id', 'name', 'azure_id', 'email', 'status', 'employee_type', 'linked_primary_employee_id',
        'terminated_date', 'azure_disabled_at', 'azure_removed_at', 'card_token', 'created_at', 'updated_at',
    ];

    /**
     * The record to keep first, then the ones to merge into it.
     *
     * @param  iterable<Employee>  $records
     * @return array{0: Employee, 1: list<Employee>}
     */
    public static function order(iterable $records): array
    {
        $records = collect($records)->values();
        $live = IdentityUser::whereIn('azure_id', $records->pluck('azure_id')->filter()->all())->pluck('azure_id')->flip();
        $rank = fn (Employee $e) => [
            $e->azure_id && isset($live[$e->azure_id]) ? 1 : 0,
            $e->status !== 'terminated' ? 1 : 0,
            blank($e->oracle_emp_no) ? 0 : 1,
            -$e->id,
        ];
        $sorted = $records->sort(fn (Employee $a, Employee $b) => $rank($b) <=> $rank($a))->values();

        return [$sorted->first(), $sorted->slice(1)->values()->all()];
    }

    /**
     * Why these two cannot be merged, if anything stops it.
     *
     * @return list<string>
     */
    public function problems(Employee $keep, Employee $duplicate): array
    {
        $problems = [];
        $label = fn (Employee $e) => "{$e->name} (#{$e->id})";

        if ($keep->id === $duplicate->id) {
            return ['A record cannot be merged with itself.'];
        }

        $live = IdentityUser::whereIn('azure_id', array_filter([$keep->azure_id, $duplicate->azure_id]))->pluck('azure_id')->all();
        if ($keep->azure_id && $duplicate->azure_id && in_array($keep->azure_id, $live, true) && in_array($duplicate->azure_id, $live, true)) {
            $problems[] = "{$label($keep)} and {$label($duplicate)} each have a Microsoft account: link them instead.";
        }
        if (! blank($keep->oracle_emp_no) && ! blank($duplicate->oracle_emp_no) && trim($keep->oracle_emp_no) !== trim($duplicate->oracle_emp_no)) {
            $problems[] = "Oracle HR knows them as two people ({$keep->oracle_emp_no} and {$duplicate->oracle_emp_no}).";
        }
        if ($keep->status !== 'terminated' && $duplicate->status !== 'terminated'
            && ! blank($keep->email) && ! blank($duplicate->email) && strcasecmp(trim($keep->email), trim($duplicate->email)) !== 0) {
            $problems[] = "They have different email addresses ({$keep->email}, {$duplicate->email}): link them instead.";
        }
        if ($keep->status === 'terminated' && $duplicate->status !== 'terminated') {
            $problems[] = "{$label($keep)} has left, but {$label($duplicate)} is current.";
        }
        if (Schema::hasTable('attendance_days')) {
            $clash = DB::table('attendance_days')->where('subject_key', 'emp:'.$duplicate->id)
                ->whereIn('work_date', DB::table('attendance_days')->where('subject_key', 'emp:'.$keep->id)->pluck('work_date')->all())
                ->count();
            if ($clash) {
                $problems[] = "Both records have attendance on the same {$clash} day(s).";
            }
        }
        if (Schema::hasTable('attendance_owners')
            && DB::table('attendance_owners')->whereIn('employee_id', [$keep->id, $duplicate->id])->count() > 1) {
            $problems[] = 'Both records are attendance owners.';
        }

        return $problems;
    }

    /**
     * @return array{merged: bool, problems: list<string>, filled: array<string, mixed>, differs: array<string, array{kept: mixed, dropped: mixed}>, moved: array<string, int>}
     */
    public function merge(Employee $keep, Employee $duplicate): array
    {
        $result = ['merged' => false, 'problems' => $this->problems($keep, $duplicate), 'filled' => [], 'differs' => [], 'moved' => []];
        if ($result['problems']) {
            return $result;
        }

        DB::transaction(function () use ($keep, $duplicate, &$result) {
            $movedIds = [];

            foreach (Schema::getColumnListing('employees') as $column) {
                if (in_array($column, self::NOT_COPIED, true)) {
                    continue;
                }
                $mine = $keep->getRawOriginal($column);
                $theirs = $duplicate->getRawOriginal($column);
                if (blank($theirs) || (in_array($column, ['manager_id', 'supervisor_id'], true) && (int) $theirs === $keep->id)) {
                    continue;
                }
                if (blank($mine)) {
                    $keep->setAttribute($column, $theirs);
                    $result['filled'][$column] = $theirs;
                } elseif ((string) $mine !== (string) $theirs) {
                    $result['differs'][$column] = ['kept' => $mine, 'dropped' => $theirs];
                }
            }
            // An address with no account behind it is still the person's address.
            if (blank($keep->email) && ! blank($duplicate->email) && $duplicate->status !== 'terminated' && blank($duplicate->azure_id)) {
                $keep->email = $duplicate->email;
                $result['filled']['email'] = $duplicate->email;
            }
            foreach (['linked_primary_employee_id', 'manager_id', 'supervisor_id'] as $column) {
                if ((int) $keep->{$column} === $duplicate->id) {
                    $keep->{$column} = null;
                }
            }
            $keep->save();

            // Rows the kept record already has would break a unique key: the duplicate's copy goes.
            foreach (['employee_app_accounts' => 'business_app_id', 'employee_printer' => 'printer_id'] as $table => $other) {
                if (Schema::hasTable($table)) {
                    $already = DB::table($table)->where('employee_id', $keep->id)->pluck($other)->all();
                    DB::table($table)->where('employee_id', $duplicate->id)->whereIn($other, $already ?: [0])->delete();
                }
            }
            $licences = DB::table('license_assignments')->where('assignable_type', Employee::class);
            $already = (clone $licences)->where('assignable_id', $keep->id)->pluck('license_id')->all();
            (clone $licences)->where('assignable_id', $duplicate->id)->whereIn('license_id', $already ?: [0])->delete();
            $movedIds['license_assignments'] = (clone $licences)->where('assignable_id', $duplicate->id)->pluck('id')->all();
            (clone $licences)->where('assignable_id', $duplicate->id)->update(['assignable_id' => $keep->id]);

            if (Schema::hasTable('attendance_days')) {
                DB::table('attendance_days')->where('subject_key', 'emp:'.$duplicate->id)->update(['subject_key' => 'emp:'.$keep->id]);
            }
            if (Schema::hasTable('attendance_shift_assignments')) {
                $movedIds['attendance_shift_assignments'] = DB::table('attendance_shift_assignments')
                    ->where('scope_type', 'employee')->where('scope_id', $duplicate->id)->pluck('id')->all();
                DB::table('attendance_shift_assignments')->where('scope_type', 'employee')->where('scope_id', $duplicate->id)->update(['scope_id' => $keep->id]);
            }

            foreach (self::REFERENCES as $table => $columns) {
                if (! Schema::hasTable($table)) {
                    continue;
                }
                $hasId = Schema::hasColumn($table, 'id');
                foreach ($columns as $column) {
                    $rows = DB::table($table)->where($column, $duplicate->id)
                        ->when($table === 'employees', fn ($q) => $q->where('id', '!=', $keep->id));
                    $movedIds["{$table}.{$column}"] = $hasId ? (clone $rows)->pluck('id')->all() : array_fill(0, (clone $rows)->count(), null);
                    (clone $rows)->update([$column => $keep->id]);
                }
            }

            $snapshot = $duplicate->getRawOriginal();
            $duplicate->delete();

            $movedIds = array_filter($movedIds);
            $result['moved'] = array_map('count', $movedIds);
            $result['merged'] = true;

            ActivityLog::create([
                'model_type' => 'Employee',
                'model_id' => $keep->id,
                'action' => 'employee_records_merged',
                'changes' => [
                    'kept' => ['id' => $keep->id, 'name' => $keep->name, 'email' => $keep->email],
                    'merged' => $snapshot,
                    'filled' => $result['filled'],
                    'differs' => $result['differs'],
                    'moved_ids' => $movedIds,
                ],
                'user_id' => Auth::id(),
            ]);

            // Days were worked out with the duplicate's branch and shift; work them out again for this record.
            if (! empty($movedIds['attendance_days.employee_id']) && Schema::hasTable('attendance_tasks')) {
                $range = DB::table('attendance_days')->where('subject_key', 'emp:'.$keep->id)
                    ->selectRaw('min(work_date) as first_day, max(work_date) as last_day')->first();
                AttendanceTask::queue('rebuild', [
                    'from' => substr((string) $range->first_day, 0, 10),
                    'to' => substr((string) $range->last_day, 0, 10),
                    'employee_ids' => [$keep->id],
                ], "Recalculate {$keep->name} after merging a duplicate record", Auth::id());
            }
        });

        return $result;
    }
}
