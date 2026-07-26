<?php

namespace App\Services\Identity;

use App\Models\AzureBranchMapping;
use App\Models\Department;
use App\Models\Employee;
use App\Models\HrImportBatch;
use App\Models\HrImportRow;
use App\Models\IdentityUser;
use App\Support\BranchKeywordMatcher;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Imports the Oracle HRMS employee export (empsg.xlsx) into the NOC.
 *
 * Pipeline: parse() stages every row into hr_import_batches + hr_import_rows,
 * matching each to an existing Employee by email. The admin reviews the batch,
 * applies matched rows (applyMatched / applyBatchMatched) and resolves unmatched
 * rows per-row (resolveUnmatched). Once NOC employee data is populated, the
 * existing Identity ▸ Contact Sync flow PATCHes the new fields to Entra.
 */
class OracleHrImportService
{
    /**
     * Canonical header → internal key. Headers are normalised before lookup
     * (lowercased, trimmed, underscores → spaces, collapsed), so both the old
     * space form ("Emp No") and the new underscore form ("EMP_NO",
     * "EMP_EMAIL_ADDRESS") of the Oracle export are recognised.
     */
    private const HEADER_MAP = [
        'location name' => 'location_name',
        'dept no' => 'dept_no',
        'dept name' => 'dept_name',
        'emp no' => 'emp_no',
        'emp name' => 'emp_name',
        'email address' => 'email',
        'emp email address' => 'email',
        'gender' => 'gender',
        'mobile no' => 'mobile_no',
        'job name' => 'job_name',
    ];

    /** Normalise a raw header cell to the HEADER_MAP key form. */
    private function normalizeHeader(string $value): string
    {
        return preg_replace('/\s+/', ' ', str_replace('_', ' ', mb_strtolower(trim($value))));
    }

    /**
     * Parse an uploaded spreadsheet into a staged batch. Each data row is
     * matched to an Employee and its branch resolved, but nothing is written to
     * the employees table yet.
     */
    public function parse(UploadedFile $file): HrImportBatch
    {
        $spreadsheet = IOFactory::load($file->getRealPath());
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, false, false);

        if (empty($rows)) {
            throw new \RuntimeException('The uploaded file has no rows.');
        }

        // Locate header row + build column index → key map.
        [$headerIndex, $colMap] = $this->resolveColumns($rows);
        if ($colMap === []) {
            throw new \RuntimeException('Could not find the expected columns (Emp No, Email Address, …) in the file.');
        }

        $mappings = AzureBranchMapping::all();

        $batch = HrImportBatch::create([
            'filename' => $file->getClientOriginalName(),
            'uploaded_by' => Auth::id(),
            'status' => 'parsed',
        ]);

        DB::transaction(function () use ($rows, $headerIndex, $colMap, $mappings, $batch) {
            foreach ($rows as $i => $raw) {
                if ($i <= $headerIndex) {
                    continue;
                }

                $get = fn (string $key) => isset($colMap[$key]) ? trim((string) ($raw[$colMap[$key]] ?? '')) : '';

                $empNo = $get('emp_no');
                $email = strtolower($get('email'));

                // Skip fully blank rows (no emp no AND no email).
                if ($empNo === '' && $email === '') {
                    continue;
                }

                $mobileRaw = $get('mobile_no');
                $mobile = $this->normalizeMobile($mobileRaw);
                $location = $get('location_name');
                $name = $get('emp_name');
                $g = strtoupper($get('gender'));
                $gender = $g === 'F' ? 'female' : ($g === 'M' ? 'male' : null);

                // Match on emp_no + email + name (scored). May report ambiguous/name-only.
                [$employee, $method] = $this->matchEmployee($empNo, $name, $email);
                $branchId = BranchKeywordMatcher::match([$location], $mappings);

                $errorNote = null;
                if ($mobileRaw !== '' && $mobile === null) {
                    $errorNote = 'Unrecognized mobile format: '.$mobileRaw;
                }
                if ($location !== '' && $branchId === null) {
                    $errorNote = trim(($errorNote ? $errorNote.'; ' : '').'No branch keyword matched location: '.$location);
                }

                // No confident match -> unmatched for manual resolution, with a reason
                // for the two "close but unsafe" cases so the reviewer knows what to do.
                $status = $employee ? 'matched' : 'unmatched';
                if (! $employee) {
                    $reason = match ($method) {
                        'ambiguous' => 'Ambiguous: emp_no/email matched more than one employee — link the correct one.',
                        'name-only' => 'Name matched but no emp_no/email confirmation — verify before linking.',
                        default => null,
                    };
                    if ($reason) {
                        $errorNote = trim(($errorNote ? $errorNote.'; ' : '').$reason);
                    }
                }

                HrImportRow::create([
                    'hr_import_batch_id' => $batch->id,
                    'row_number' => $i + 1,
                    'emp_no' => $empNo ?: null,
                    'emp_name' => $name ?: null,
                    'email' => $email ?: null,
                    'mobile_raw' => $mobileRaw ?: null,
                    'mobile_normalized' => $mobile,
                    'location_name' => $location ?: null,
                    'dept_no' => $get('dept_no') ?: null,
                    'dept_name' => $get('dept_name') ?: null,
                    'job_name' => $get('job_name') ?: null,
                    'gender' => $gender,
                    'matched_employee_id' => $employee?->id,
                    'match_method' => $method,
                    'resolved_branch_id' => $branchId,
                    'status' => $status,
                    'error_note' => $errorNote,
                ]);
            }
        });

        $batch->refreshCounts();

        return $batch->fresh();
    }

    /**
     * Match an Oracle row to an existing Employee using THREE signals and a score:
     * oracle_emp_no (5), email (4), name (3). Email also matches when the address
     * lives on the employee's linked Azure identity (UPN/mail), not just employees.email.
     *
     * Two+ signals (>=7) is a confident match. A lone strong signal (id or email) is
     * accepted only when it points to exactly one person; a tie returns 'ambiguous'
     * (handles the SSS/Saudi emp_no collision and shared driver/guard emails safely).
     * Name-only returns 'name-only'. Both are surfaced for manual resolution.
     *
     * @return array{0: ?Employee, 1: string} [employee|null, match_method]
     */
    public function matchEmployee(string $empNo, string $name, string $email): array
    {
        $empNo = trim($empNo);
        $email = trim(mb_strtolower($email));
        $norm = fn (string $s): string => preg_replace('/\s+/', ' ', mb_strtolower(trim($s)));
        $sortName = function (string $s) use ($norm): string {
            $t = explode(' ', $norm($s));
            sort($t);

            return implode(' ', $t);
        };

        // Employees whose linked Azure identity carries this email (UPN or mail).
        $identityEmpIds = [];
        if ($email !== '') {
            $azureIds = IdentityUser::where(fn ($q) => $q
                ->whereRaw('LOWER(user_principal_name) = ?', [$email])
                ->orWhereRaw('LOWER(mail) = ?', [$email]))
                ->pluck('azure_id')->filter()->all();
            if ($azureIds !== []) {
                $identityEmpIds = Employee::whereIn('azure_id', $azureIds)->pluck('id')->all();
            }
        }

        // Build the candidate set from any matching key.
        $cands = collect();
        if ($empNo !== '') {
            $cands = $cands->merge(Employee::where('oracle_emp_no', $empNo)->get());
        }
        if ($email !== '') {
            $cands = $cands->merge(Employee::whereRaw('LOWER(email) = ?', [$email])->get());
        }
        if ($identityEmpIds !== []) {
            $cands = $cands->merge(Employee::whereIn('id', $identityEmpIds)->get());
        }
        if ($name !== '') {
            $cands = $cands->merge(Employee::whereRaw("REPLACE(LOWER(name), ' ', '') = ?", [str_replace(' ', '', $norm($name))])->get());
        }
        $cands = $cands->unique('id');
        if ($cands->isEmpty()) {
            return [null, 'none'];
        }

        $best = null;
        $bestScore = 0;
        $bestSig = [];
        $topCount = 0;
        foreach ($cands as $c) {
            $s = 0;
            $sig = [];
            if ($empNo !== '' && (string) $c->oracle_emp_no === $empNo) {
                $s += 5;
                $sig[] = 'id';
            }
            if (($email !== '' && mb_strtolower((string) $c->email) === $email) || in_array($c->id, $identityEmpIds, true)) {
                $s += 4;
                $sig[] = 'email';
            }
            if ($name !== '' && ($norm((string) $c->name) === $norm($name) || $sortName((string) $c->name) === $sortName($name))) {
                $s += 3;
                $sig[] = 'name';
            }
            if ($s > $bestScore) {
                $bestScore = $s;
                $best = $c;
                $bestSig = $sig;
                $topCount = 1;
            } elseif ($s === $bestScore && $s > 0) {
                $topCount++;
            }
        }

        if ($best && $bestScore >= 7) {
            return [$best, 'multi:'.implode('+', $bestSig)];
        }
        if ($best && $bestScore >= 4 && $topCount === 1) {
            return [$best, implode('+', $bestSig)];
        }
        if ($best && $bestScore >= 4 && $topCount > 1) {
            return [null, 'ambiguous'];
        }
        if ($best && $bestScore === 3) {
            return [null, 'name-only'];
        }

        return [null, 'none'];
    }

    /**
     * Normalize a raw phone value to KSA E.164 (+9665XXXXXXXX). Returns null for
     * empty / placeholder / unrecognized inputs so the caller can flag them.
     */
    public function normalizeMobile(?string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $raw);
        if ($digits === '' || $digits === null) {
            return null;
        }

        // Strip an international prefix if present.
        if (str_starts_with($digits, '00966')) {
            $digits = substr($digits, 5);
        } elseif (str_starts_with($digits, '966')) {
            $digits = substr($digits, 3);
        }

        // Drop any remaining leading zeros (trunk prefix / '00' placeholder).
        $digits = ltrim($digits, '0');

        // Valid Saudi mobile: 9 digits, leading 5.
        if (strlen($digits) === 9 && $digits[0] === '5') {
            return '+966'.$digits;
        }

        return null;
    }

    /**
     * Apply a matched row's Oracle data onto its linked Employee and mark the
     * row applied. No-op (status 'error') if there is no matched employee.
     */
    public function applyMatched(HrImportRow $row): void
    {
        $employee = $row->matchedEmployee;
        if (! $employee) {
            $row->update(['status' => 'error', 'error_note' => 'No matched employee to apply to.']);

            return;
        }

        DB::transaction(function () use ($row, $employee) {
            $this->writeToEmployee($employee, $row);
            $row->update(['status' => 'applied']);
        });
    }

    /**
     * Apply every still-matched row in a batch. Returns the number applied.
     */
    public function applyBatchMatched(HrImportBatch $batch): int
    {
        $applied = 0;

        $batch->rows()->where('status', 'matched')->whereNotNull('matched_employee_id')
            ->with('matchedEmployee')
            ->chunkById(200, function ($rows) use (&$applied) {
                foreach ($rows as $row) {
                    $this->applyMatched($row);
                    if ($row->fresh()->status === 'applied') {
                        $applied++;
                    }
                }
            });

        $batch->refreshCounts();
        $batch->update([
            'status' => $batch->rows()->where('status', 'unmatched')->exists()
                ? 'partially_applied'
                : 'applied',
        ]);

        return $applied;
    }

    /**
     * Resolve a single unmatched row according to the admin's decision.
     *
     * @param  string  $decision  create|skip|link
     */
    public function resolveUnmatched(HrImportRow $row, string $decision, ?int $linkEmployeeId = null): void
    {
        DB::transaction(function () use ($row, $decision, $linkEmployeeId) {
            switch ($decision) {
                case 'skip':
                    $row->update(['decision' => 'skip', 'status' => 'skipped']);
                    break;

                case 'create':
                    $employee = new Employee([
                        'name' => $row->emp_name ?: ($row->email ?: 'Unknown'),
                        'email' => $row->email,
                        'status' => 'active',
                    ]);
                    $employee->save();
                    $this->writeToEmployee($employee, $row);
                    $row->update([
                        'decision' => 'create',
                        'linked_employee_id' => $employee->id,
                        'status' => 'created',
                    ]);
                    break;

                case 'link':
                    $employee = Employee::findOrFail($linkEmployeeId);
                    $this->writeToEmployee($employee, $row);
                    $row->update([
                        'decision' => 'link',
                        'linked_employee_id' => $employee->id,
                        'status' => 'linked',
                    ]);
                    break;

                default:
                    throw new \InvalidArgumentException("Unknown decision: {$decision}");
            }
        });
    }

    /**
     * Reconciliation lists for the review page — employees the Oracle export
     * does NOT account for, and inactive/disabled accounts. Report only.
     *
     * @return array{not_in_hr: EloquentCollection, inactive: EloquentCollection}
     */
    public function flaggedEmployees(): array
    {
        $notInHr = Employee::with('branch')
            ->whereNull('oracle_synced_at')
            ->orderBy('name')
            ->get();

        $inactive = Employee::with(['branch', 'identityUser'])
            ->where(function ($q) {
                $q->where('status', '!=', 'active')
                    ->orWhereNotNull('azure_disabled_at')
                    ->orWhereNotNull('azure_removed_at');
            })
            ->orderBy('name')
            ->get();

        // Also surface employees whose linked Azure account is disabled even if
        // their NOC status still reads active.
        $disabledAzureIds = IdentityUser::where('account_enabled', false)
            ->pluck('azure_id')
            ->filter()
            ->all();

        if ($disabledAzureIds !== []) {
            $extra = Employee::with(['branch', 'identityUser'])
                ->whereIn('azure_id', $disabledAzureIds)
                ->whereNotIn('id', $inactive->pluck('id'))
                ->orderBy('name')
                ->get();
            $inactive = $inactive->merge($extra)->sortBy('name')->values();
        }

        return ['not_in_hr' => $notInHr, 'inactive' => $inactive];
    }

    /**
     * Write all Oracle-sourced fields onto an employee. Branch and department
     * are only changed when we have a resolved value (never blanked).
     */
    private function writeToEmployee(Employee $employee, HrImportRow $row): void
    {
        $attrs = [
            'oracle_emp_no' => $row->emp_no,
            'oracle_dept_no' => $row->dept_no,
            'oracle_department' => $row->dept_name,
            'oracle_location' => $row->location_name,
            'oracle_synced_at' => now(),
        ];

        if ($row->mobile_normalized) {
            $attrs['mobile_phone'] = $row->mobile_normalized;
        }
        if ($row->job_name) {
            $attrs['job_title'] = $row->job_name;
        }
        if ($row->gender) {
            $attrs['gender'] = $row->gender;
        }
        if ($row->resolved_branch_id) {
            $attrs['branch_id'] = $row->resolved_branch_id;
        }
        if ($row->dept_name) {
            $department = Department::firstOrCreate(['name' => $row->dept_name]);
            $attrs['department_id'] = $department->id;
        }

        $employee->update($attrs);
    }

    /**
     * Build a column-index map from the first row that contains recognizable
     * headers.
     *
     * @param  array<int, array<int, mixed>>  $rows
     * @return array{0: int, 1: array<string, int>} [headerRowIndex, key→colIndex]
     */
    private function resolveColumns(array $rows): array
    {
        foreach ($rows as $index => $row) {
            $map = [];
            foreach ($row as $col => $value) {
                $key = $this->normalizeHeader((string) $value);
                if (isset(self::HEADER_MAP[$key])) {
                    $map[self::HEADER_MAP[$key]] = $col;
                }
            }
            // Require at least the two columns we key on.
            if (isset($map['emp_no']) || isset($map['email'])) {
                return [$index, $map];
            }
            if ($index > 5) {
                break; // headers should be near the top
            }
        }

        return [0, []];
    }
}
