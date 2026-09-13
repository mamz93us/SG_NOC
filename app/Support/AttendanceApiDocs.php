<?php

namespace App\Support;

use App\Http\Controllers\Api\AttendanceApiController as Api;
use App\Models\Attendance\AttendanceAdjustment;
use App\Models\Attendance\AttendancePunch;
use App\Services\Attendance\AttendanceDayBuilder;

/**
 * The attendance API reference, as data — the same shape as HrApiDocs, so the
 * docs page renders both with one partial. Limits, flags, excuses and punch
 * states are read off the classes that enforce them, so the page cannot
 * describe an API that no longer exists.
 */
class AttendanceApiDocs
{
    /** @return list<array<string, mixed>> */
    public static function endpoints(): array
    {
        $paging = static fn (array $subject) => ['ok' => true, 'from' => '2026-09-01', 'to' => '2026-09-30']
            + $subject
            + [
                'page' => 1, 'per_page' => Api::PER_PAGE, 'last_page' => 1, 'total' => 1, 'count' => 1,
                'generated_at' => '2026-10-01T06:00:00+03:00',
                'records' => [self::exampleRecord()],
            ];

        $range = [
            ['from', 'date (Y-m-d)', 'required', 'First work date, included.'],
            ['to', 'date (Y-m-d)', 'required', 'Last work date, included.'],
        ];
        $pages = [
            ['page', 'integer', 'optional', 'Default 1.'],
            ['per_page', 'integer', 'optional', '1–'.Api::MAX_PER_PAGE.', default '.Api::PER_PAGE.'.'],
        ];

        return [
            [
                'id' => 'attendance-all',
                'method' => 'GET',
                'path' => '/api/attendance',
                'summary' => 'Every employee’s days in a date range',
                'mirrors' => null,
                'description' => 'One record per employee per work day — status, shift, check-in, check-out, worked / late / '
                    .'early-leave / overtime minutes — with every raw punch of that day. Only employees who have an '
                    .'Oracle number in NOC are returned.',
                'notes' => [
                    'The range covers at most '.Api::MAX_DAYS.' days, both ends included: ask for a month at a time.',
                    'Paginated. Repeat with page=2, 3 … until page equals last_page. Records are ordered by date, then employee.',
                    'excluded counts the days in the range that are not returned — people with no Oracle number in NOC, and fingerprint codes linked to no employee. Fix those in NOC and the days appear.',
                ],
                'fields' => array_merge($range, $pages),
                'request' => null,
                'response' => $paging(['excluded' => ['days_without_oracle_number' => 0, 'days_not_linked_to_an_employee' => 12]]),
                'curl_query' => 'from=2026-09-01&to=2026-09-30',
                'testable' => false,
            ],
            [
                'id' => 'attendance-employee',
                'method' => 'GET',
                'path' => '/api/attendance/employees/{oracle_emp_no}',
                'summary' => 'One employee’s days in a date range',
                'mirrors' => null,
                'description' => 'The same records for one person, found by Oracle employee number. employee in the response '
                    .'confirms who was matched, even when the range holds no days.',
                'notes' => [
                    'The range covers at most '.Api::MAX_DAYS_EMPLOYEE.' days, both ends included.',
                    'EMP_NO repeats between the SSS-Egypt and SamirGroup series. When a number belongs to more than one employee the call returns 409 with the candidates and never picks one — repeat it with branch_id or employee_id from that list.',
                    'Returns 404 when no employee holds the number.',
                ],
                'fields' => array_merge(
                    [['oracle_emp_no', 'string, in the path', 'required', 'Oracle employee number.']],
                    $range,
                    [
                        ['branch_id', 'integer', 'optional', 'Only for a shared number: the NOC branch of the person you mean.'],
                        ['employee_id', 'integer', 'optional', 'Only for a shared number: the NOC id from the 409 candidates.'],
                    ],
                    $pages,
                ),
                'request' => null,
                'response' => $paging(['employee' => [
                    'employee_id' => 421, 'oracle_emp_no' => '10432', 'name' => 'Sara Al-Rashid',
                    'branch_id' => 3, 'branch' => 'Jeddah', 'status' => 'active',
                ]]),
                'curl_path' => '/api/attendance/employees/10432',
                'curl_query' => 'from=2026-09-01&to=2026-09-30',
                'testable' => false,
            ],
        ];
    }

    /**
     * What each record carries: [field, type, meaning].
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    public static function recordFields(): array
    {
        // Days of unlinked codes are never returned, so neither are their flags.
        $flags = collect(AttendanceDayBuilder::LABELS)
            ->except([AttendanceDayBuilder::FLAG_UNMAPPED, AttendanceDayBuilder::FLAG_NOT_EMPLOYEE])
            ->map(fn (string $label, string $flag) => "{$flag} ({$label})")
            ->implode(', ');
        $errors = implode(', ', array_diff(AttendanceDayBuilder::ERRORS, [AttendanceDayBuilder::FLAG_UNMAPPED]));
        $states = collect(AttendancePunch::STATES)->map(fn (string $label, $code) => "{$code} {$label}")->implode(', ');

        return [
            ['employee_id', 'integer', 'NOC employee id. Unique to one person, unlike oracle_emp_no.'],
            ['oracle_emp_no', 'string', 'Oracle employee number.'],
            ['employee_name', 'string', 'Name in NOC.'],
            ['branch', 'string | null', 'The branch the day was judged against.'],
            ['date', 'date (Y-m-d)', 'The work day.'],
            ['status', 'present | absent | excused', 'absent is recorded only once the shift is over; excused is a whole-day excuse entered by HR.'],
            ['shift', 'string | null', 'Name of the shift that applied.'],
            ['scheduled_in, scheduled_out', 'datetime | null', 'Start and end of that shift on this day.'],
            ['check_in', 'datetime | null', 'The earliest punch of the day, or the time HR corrected it to.'],
            ['check_out', 'datetime | null', 'The latest punch of the day, or the time HR corrected it to. null when there was a single punch.'],
            ['worked_minutes', 'integer | null', 'From check_in to check_out. null without a check-out — never estimated.'],
            ['late_minutes, early_leave_minutes, overtime_minutes', 'integer', 'Against the shift, after its grace periods. Every minute worked on a day off or a holiday counts as overtime.'],
            ['excuse', 'string | null', implode(', ', array_keys(AttendanceAdjustment::EXCUSES))],
            ['corrected', 'boolean', 'HR corrected check_in or check_out. punches still show what the device recorded.'],
            ['approved', 'boolean', 'The day belongs to a period HR has approved and locked. Until then it can still change.'],
            ['has_error', 'boolean', "The day has a data error: {$errors}."],
            ['flags', 'string[]', $flags],
            ['punches', 'object[]', "Every raw punch of the day, by time: time, state, state_label, terminal, area. state is the key pressed on the device ({$states}); it does not decide check-in or check-out."],
        ];
    }

    /** @return array<string, mixed> */
    private static function exampleRecord(): array
    {
        $punch = fn (string $time, string $state) => [
            'time' => "2026-09-01 {$time}",
            'state' => $state,
            'state_label' => AttendancePunch::STATES[$state],
            'terminal' => 'JED Main Entrance',
            'area' => 'Jeddah HQ',
        ];

        return [
            'employee_id' => 421,
            'oracle_emp_no' => '10432',
            'employee_name' => 'Sara Al-Rashid',
            'branch' => 'Jeddah',
            'date' => '2026-09-01',
            'status' => 'present',
            'shift' => 'Office',
            'scheduled_in' => '2026-09-01 09:00:00',
            'scheduled_out' => '2026-09-01 17:00:00',
            'check_in' => '2026-09-01 08:52:00',
            'check_out' => '2026-09-01 17:41:00',
            'worked_minutes' => 529,
            'late_minutes' => 0,
            'early_leave_minutes' => 0,
            'overtime_minutes' => 41,
            'excuse' => null,
            'corrected' => false,
            'approved' => true,
            'has_error' => false,
            'flags' => ['overtime'],
            'punches' => [
                $punch('08:52:00', '0'),
                $punch('13:05:00', '2'),
                $punch('17:41:00', '1'),
            ],
        ];
    }
}
