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
     * Canonical header → internal key. Matched case-insensitively, trimmed.
     */
    private const HEADER_MAP = [
        'location name' => 'location_name',
        'dept no' => 'dept_no',
        'dept name' => 'dept_name',
        'emp no' => 'emp_no',
        'emp name' => 'emp_name',
        'email address' => 'email',
        'mobile no' => 'mobile_no',
        'job name' => 'job_name',
    ];

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

                [$employee, $method] = $this->matchEmployee($email);
                $branchId = BranchKeywordMatcher::match([$location], $mappings);

                $errorNote = null;
                if ($mobileRaw !== '' && $mobile === null) {
                    $errorNote = 'Unrecognized mobile format: '.$mobileRaw;
                }
                if ($location !== '' && $branchId === null) {
                    $errorNote = trim(($errorNote ? $errorNote.'; ' : '').'No branch keyword matched location: '.$location);
                }

                $status = $employee ? 'matched' : 'unmatched';
                if (! $employee && $email === '') {
                    $status = 'error';
                    $errorNote = trim(($errorNote ? $errorNote.'; ' : '').'Row has no email to match on.');
                }

                HrImportRow::create([
                    'hr_import_batch_id' => $batch->id,
                    'row_number' => $i + 1,
                    'emp_no' => $empNo ?: null,
                    'emp_name' => $get('emp_name') ?: null,
                    'email' => $email ?: null,
                    'mobile_raw' => $mobileRaw ?: null,
                    'mobile_normalized' => $mobile,
                    'location_name' => $location ?: null,
                    'dept_no' => $get('dept_no') ?: null,
                    'dept_name' => $get('dept_name') ?: null,
                    'job_name' => $get('job_name') ?: null,
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
     * Match an Oracle email to an existing Employee. Mirrors the lookup chain in
     * AzureSyncController::findEmployeeByUpn but keyed on the Oracle email.
     *
     * @return array{0: ?Employee, 1: string} [employee, match_method]
     */
    public function matchEmployee(string $email): array
    {
        $email = trim(strtolower($email));
        if ($email === '') {
            return [null, 'none'];
        }

        $employee = Employee::whereRaw('LOWER(email) = ?', [$email])->first();
        if ($employee) {
            return [$employee, 'email'];
        }

        $identity = IdentityUser::whereRaw('LOWER(user_principal_name) = ?', [$email])
            ->orWhereRaw('LOWER(mail) = ?', [$email])
            ->first();

        if ($identity) {
            if ($identity->azure_id) {
                $employee = Employee::where('azure_id', $identity->azure_id)->first();
                if ($employee) {
                    return [$employee, 'upn'];
                }
            }
            if ($identity->mail) {
                $employee = Employee::whereRaw('LOWER(email) = ?', [strtolower($identity->mail)])->first();
                if ($employee) {
                    return [$employee, 'mail'];
                }
            }
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
                $key = strtolower(trim((string) $value));
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
