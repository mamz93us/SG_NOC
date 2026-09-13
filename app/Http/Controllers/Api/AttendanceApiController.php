<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\Attendance\Oracle\AttendanceFeed;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Oracle's read of attendance: check-in, check-out and every punch, per
 * employee per day. Authenticated by `hr.api_key:attendance` — a key from the
 * HR API Keys page with the attendance scope, which cannot reach /api/hr.
 *
 * Times are the branch device's wall clock with no zone, as
 * attendance_punches stores them, so they go out as "Y-m-d H:i:s" and never
 * as ISO 8601: an offset would claim a zone nobody recorded.
 */
class AttendanceApiController extends Controller
{
    /** Everyone, a month at a time: a pay period is never longer. */
    public const MAX_DAYS = 31;

    /** One person, up to a year. */
    public const MAX_DAYS_EMPLOYEE = 366;

    public const PER_PAGE = 500;

    public const MAX_PER_PAGE = 1000;

    public function __construct(private AttendanceFeed $feed) {}

    /** GET /api/attendance?from=&to= — every employee. */
    public function index(Request $request): JsonResponse
    {
        $params = $this->validated($request, self::MAX_DAYS);

        return $this->respond($this->feed->days($params['from'], $params['to']), $params, [
            'excluded' => $this->feed->excluded($params['from'], $params['to']),
        ]);
    }

    /** GET /api/attendance/employees/{oracle_emp_no}?from=&to= — one employee. */
    public function employee(Request $request, string $oracleEmpNo): JsonResponse
    {
        $params = $this->validated($request, self::MAX_DAYS_EMPLOYEE, [
            'branch_id' => 'nullable|integer',
            'employee_id' => 'nullable|integer',
        ]);

        // A linked secondary mailbox mirrors its primary record and never holds punches.
        $candidates = Employee::query()
            ->where('oracle_emp_no', $oracleEmpNo)
            ->whereNull('linked_primary_employee_id')
            ->when($params['branch_id'] ?? null, fn ($q, $id) => $q->where('branch_id', $id))
            ->when($params['employee_id'] ?? null, fn ($q, $id) => $q->whereKey($id))
            ->with('branch:id,name')
            ->orderBy('id')
            ->get(['id', 'name', 'oracle_emp_no', 'branch_id', 'status']);

        if ($candidates->isEmpty()) {
            $narrowed = ! empty($params['branch_id']) || ! empty($params['employee_id']);

            return response()->json([
                'ok' => false,
                'message' => "No employee holds Oracle number {$oracleEmpNo}".($narrowed ? ' with that branch_id / employee_id.' : '.'),
            ], 404);
        }

        // EMP_NO collides between the SSS-Egypt and SamirGroup series. Never
        // pick one: either person's days under a shared number would book one
        // person's punches to the other in Oracle.
        if ($candidates->count() > 1) {
            return response()->json([
                'ok' => false,
                'message' => "Oracle number {$oracleEmpNo} is held by {$candidates->count()} employees. "
                    .'Repeat the request with branch_id or employee_id to choose one.',
                'candidates' => $candidates->map(fn (Employee $e) => $this->identity($e))->all(),
            ], 409);
        }

        $employee = $candidates->first();

        return $this->respond($this->feed->days($params['from'], $params['to'])->where('employee_id', $employee->id), $params, [
            'employee' => $this->identity($employee),
        ]);
    }

    /**
     * @param  array<string, string>  $rules  endpoint-specific rules
     * @return array<string, mixed>
     */
    private function validated(Request $request, int $maxDays, array $rules = []): array
    {
        $params = $request->validate([
            'from' => 'required|date_format:Y-m-d',
            'to' => 'required|date_format:Y-m-d|after_or_equal:from',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:'.self::MAX_PER_PAGE,
        ] + $rules);

        $days = CarbonImmutable::parse($params['from'])->diff(CarbonImmutable::parse($params['to']))->days + 1;

        if ($days > $maxDays) {
            throw ValidationException::withMessages([
                'to' => "The range can cover at most {$maxDays} days; this one covers {$days}. Request it in parts.",
            ]);
        }

        return $params;
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $subject  who the page is about
     */
    private function respond(Builder $days, array $params, array $subject): JsonResponse
    {
        $page = $days->paginate((int) ($params['per_page'] ?? self::PER_PAGE));

        return response()->json([
            'ok' => true,
            'from' => $params['from'],
            'to' => $params['to'],
            ...$subject,
            'page' => $page->currentPage(),
            'per_page' => $page->perPage(),
            'last_page' => $page->lastPage(),
            'total' => $page->total(),
            'count' => $page->count(),
            'generated_at' => now()->toIso8601String(),
            'records' => $this->feed->records($page->items()),
        ])->header('Cache-Control', 'no-store, private');
    }

    /** @return array<string, mixed> */
    private function identity(Employee $employee): array
    {
        return [
            'employee_id' => $employee->id,
            'oracle_emp_no' => $employee->oracle_emp_no,
            'name' => $employee->name,
            'branch_id' => $employee->branch_id,
            'branch' => $employee->branch?->name,
            'status' => $employee->status,
        ];
    }
}
