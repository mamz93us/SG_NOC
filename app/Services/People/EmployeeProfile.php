<?php

namespace App\Services\People;

use App\Models\Employee;
use App\Models\Vacation\VacationAbsence;
use App\Models\Vacation\VacationBalance;
use App\Models\Vacation\VacationEmployee;
use App\Services\Attendance\MonthlySheet;
use Illuminate\Support\Collection;

/**
 * Reads what one person's profile shows: their Oracle vacation data and their
 * attendance year. Nothing is worked out here that the Monthly sheet or the
 * Vacations pages do not already show — the profile puts the two side by side.
 */
class EmployeeProfile
{
    public function __construct(private MonthlySheet $sheet) {}

    /**
     * The Oracle people linked to this employee (normally one), with their
     * balances. A linked secondary mailbox reads its primary's.
     *
     * @return Collection<int, VacationEmployee>
     */
    public function oraclePeople(Employee $employee): Collection
    {
        return VacationEmployee::forEmployee($employee);
    }

    /** @param  Collection<int, VacationEmployee>  $people */
    public function balance(Collection $people, int $year): ?VacationBalance
    {
        return $people->map(fn (VacationEmployee $person) => $person->balanceFor($year))->filter()->first();
    }

    /**
     * Leave records with at least one day from $from to $to — withdrawn ones
     * included, newest first.
     *
     * @param  Collection<int, VacationEmployee>  $people
     * @return Collection<int, VacationAbsence>
     */
    public function leave(Collection $people, string $from, string $to): Collection
    {
        if ($people->isEmpty()) {
            return collect();
        }

        return VacationAbsence::query()
            ->whereIn('vacation_employee_id', $people->pluck('id')->all())
            ->overlapping($from, $to)
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * January to December, the attendance calendar with the leave laid over it.
     *
     * @param  Collection<int, VacationAbsence>  $leave
     * @param  list<int>  $weekend  used on dates the person has no shift
     */
    public function year(Employee $employee, int $year, Collection $leave, array $weekend): ProfileYear
    {
        $days = $this->sheet->daysWithoutPunches(collect([$employee]), "{$year}-01-01", "{$year}-12-31");

        return ProfileYear::build($days[(int) $employee->id] ?? [], $leave, $weekend);
    }

    /**
     * The weekend of the book the person's Oracle data comes from.
     *
     * @param  Collection<int, VacationEmployee>  $people
     * @return list<int>
     */
    public static function weekend(Collection $people): array
    {
        $book = $people->first()?->book ?? VacationEmployee::defaultBook();

        return array_values(VacationEmployee::books()[$book]['weekend'] ?? []);
    }
}
