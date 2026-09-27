<?php

namespace App\Services\Itam\Oracle;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Itam\OracleAsset;
use Illuminate\Support\Collection;

/**
 * Decides which NOC employee an Oracle employee number in the asset register is.
 *
 *   1. candidates = employees whose oracle_emp_no is the number (linked
 *      secondary mailboxes excluded — they mirror a primary record);
 *   2. keep those in the register's branches (config/oracle_assets.php) or with
 *      no branch: the SSS Egypt and SamirGroup series collide, and a number
 *      held only by someone in Cairo is somebody else;
 *   3. exactly one left → that employee; several → ambiguous;
 *   4. none → by name, only among active employees the NOC holds NO Oracle
 *      number for, and only when exactly one of them matches and that one
 *      matches no other number in the file. Oracle writes the full name
 *      ("Abdullah Mahmoud Mari Haidar") where Entra has two parts ("Abdullah
 *      Haidar"): the first names must be equal, the NOC's parts must all
 *      appear in Oracle's in order, and the last names must be equal unless
 *      the NOC name has three parts or more.
 *
 * Never HrLookup::employee(): it quietly prefers the active record, which
 * across the colliding series would hand one person's laptop to another. A
 * manual link, and any unit that already has a NOC asset, is left alone.
 */
class OracleAssetEmployeeLinker
{
    /**
     * @param  iterable<OracleAsset>  $units  units still without a NOC asset
     * @return array<string, int> units per decision
     */
    public function linkAll(iterable $units): array
    {
        $units = collect($units)
            ->reject(fn (OracleAsset $unit) => $unit->employee_match === OracleAsset::EMPLOYEE_MANUAL || $unit->isResolved())
            ->values();

        $counts = [];

        if ($units->isEmpty()) {
            return $counts;
        }

        $numbers = $units->pluck('emp_no')->map(fn ($number) => trim((string) $number))->unique()->values();
        $decisions = $this->decide($numbers, $units->groupBy(fn (OracleAsset $unit) => trim((string) $unit->emp_no))
            ->map(fn (Collection $group) => (string) $group->first()->emp_name));

        foreach ($units as $unit) {
            [$employeeId, $method, $candidateIds] = $decisions[trim((string) $unit->emp_no)];

            $unit->forceFill([
                'employee_id' => $employeeId,
                'employee_match' => $method,
                'employee_candidates' => $candidateIds ?: null,
            ]);

            $counts[$method] = ($counts[$method] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @param  Collection<int, string>  $numbers
     * @param  Collection<string, string>  $namesByNumber
     * @return array<string, array{0: ?int, 1: string, 2: list<int>}>
     */
    private function decide(Collection $numbers, Collection $namesByNumber): array
    {
        $bookBranches = $this->bookBranchIds();
        $inBook = fn ($employee) => $bookBranches === null || $employee->branch_id === null || in_array((int) $employee->branch_id, $bookBranches, true);

        $holders = $numbers
            ->flatMap(fn (string $number) => $this->lookupNumbers($number))
            ->unique()
            ->chunk(500)
            ->flatMap(fn (Collection $chunk) => Employee::query()
                ->whereIn('oracle_emp_no', $chunk->values()->all())
                ->whereNull('linked_primary_employee_id')
                ->get(['id', 'oracle_emp_no', 'branch_id']))
            ->unique('id')
            ->groupBy(fn (Employee $employee) => trim((string) $employee->oracle_emp_no));

        $decisions = [];
        $needName = [];

        foreach ($numbers as $number) {
            $candidates = collect($this->lookupNumbers($number))
                ->flatMap(fn (string $form) => $holders->get($form, collect()))
                ->unique('id')
                ->values();
            $candidateIds = $candidates->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
            $pool = $candidates->filter($inBook)->values();

            if ($pool->count() === 1) {
                $decisions[$number] = [(int) $pool->first()->id, $candidates->count() === 1 ? OracleAsset::EMPLOYEE_NUMBER : OracleAsset::EMPLOYEE_NUMBER_BRANCH, $candidates->count() === 1 ? [] : $candidateIds];
            } elseif ($pool->count() > 1) {
                $decisions[$number] = [null, OracleAsset::EMPLOYEE_AMBIGUOUS, $candidateIds];
            } else {
                $decisions[$number] = [null, OracleAsset::EMPLOYEE_NONE, $candidateIds];
                $needName[] = $number;
            }
        }

        if ($needName === []) {
            return $decisions;
        }

        $unnumbered = Employee::query()
            ->whereNull('linked_primary_employee_id')
            ->where(fn ($q) => $q->whereNull('oracle_emp_no')->orWhere('oracle_emp_no', ''))
            ->where('status', 'active')
            ->get(['id', 'name', 'branch_id'])
            ->filter($inBook)
            ->values();

        $byName = [];

        foreach ($needName as $number) {
            $oracleName = (string) ($namesByNumber[$number] ?? '');
            $matches = $unnumbered->filter(fn (Employee $employee) => self::namesMatch($oracleName, (string) $employee->name))->pluck('id')->all();

            if (count($matches) === 1) {
                $byName[$number] = (int) $matches[0];
            }
        }

        // One NOC employee named for two different Oracle numbers is a coincidence of names, not a match.
        $claims = array_count_values($byName);

        foreach ($byName as $number => $employeeId) {
            if ($claims[$employeeId] === 1) {
                $decisions[$number] = [$employeeId, OracleAsset::EMPLOYEE_NAME, []];
            }
        }

        return $decisions;
    }

    /** @return list<string> the forms a number is looked up as */
    public function lookupNumbers(string $number): array
    {
        $number = trim($number);

        return array_values(array_unique(array_filter([$number, ltrim($number, '0')], fn ($form) => $form !== '')));
    }

    /** Oracle's full name and a NOC display name for the same person. See the class comment. */
    public static function namesMatch(string $oracleName, string $nocName): bool
    {
        $oracle = self::nameParts($oracleName);
        $noc = self::nameParts($nocName);

        if (count($noc) < 2 || count($oracle) < 2) {
            return false;
        }

        if (implode('', $oracle) === implode('', $noc)) {
            return true;
        }

        if ($oracle[0] !== $noc[0]) {
            return false;
        }

        if (count($noc) < 3 && end($oracle) !== end($noc)) {
            return false;
        }

        // Every NOC part, in order, among Oracle's.
        $at = 0;

        foreach ($noc as $part) {
            while ($at < count($oracle) && $oracle[$at] !== $part) {
                $at++;
            }

            if ($at === count($oracle)) {
                return false;
            }

            $at++;
        }

        return true;
    }

    /** @return list<string> */
    private static function nameParts(string $name): array
    {
        $name = strtolower(trim((string) preg_replace('/[^\pL]+/u', ' ', $name)));

        return $name === '' ? [] : preg_split('/\s+/', $name);
    }

    /**
     * The register's branch ids; null when it names none that exist, so a stale
     * config degrades to "any holder" instead of linking nobody.
     *
     * @return list<int>|null
     */
    private function bookBranchIds(): ?array
    {
        $names = (array) config('oracle_assets.branches', []);

        if ($names === []) {
            return null;
        }

        $ids = Branch::query()->whereIn('name', $names)->pluck('id')->map(fn ($id) => (int) $id)->all();

        return $ids === [] ? null : $ids;
    }
}
