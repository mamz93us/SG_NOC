<?php

namespace App\Console\Commands;

use App\Models\Employee;
use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Reconciles an HR employee export (xlsx) against the NOC employee records.
 *
 * Matches on EMP_NO -> employees.oracle_emp_no (emails in the export are not
 * reliable: some are personal, some blank). Sets gender, and reports any other
 * field differences so IT/HR can decide whether to apply them.
 *
 * Dry-run by default. --apply writes gender only; --apply-all also writes
 * job title / department / location.
 */
class EmployeesSyncHrList extends Command
{
    protected $signature = 'employees:sync-hr-list
                            {path : Path to the HR xlsx export}
                            {--apply : Write gender changes}
                            {--apply-all : Also write job title / department no+name / location}';

    protected $description = 'Reconcile an HR employee list (xlsx): set gender and report field differences';

    public function handle(): int
    {
        $path = $this->argument('path');
        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $rows = IOFactory::load($path)->getActiveSheet()->toArray(null, true, true, false);
        $head = array_map(fn ($h) => strtoupper(trim((string) $h)), array_shift($rows));
        $idx  = array_flip($head);

        foreach (['EMP_NO', 'EMP_NAME', 'GENDER'] as $required) {
            if (! isset($idx[$required])) {
                $this->error("Missing required column: {$required}");

                return self::FAILURE;
            }
        }

        $get = fn (array $r, string $c) => isset($idx[$c]) ? trim((string) ($r[$idx[$c]] ?? '')) : '';

        $apply    = (bool) $this->option('apply') || (bool) $this->option('apply-all');
        $applyAll = (bool) $this->option('apply-all');

        $stats = [
            'rows' => 0, 'matched' => 0, 'unmatched' => 0,
            'gender_new' => 0, 'gender_changed' => 0, 'gender_same' => 0,
        ];
        $unmatched  = [];
        $diffs      = [];
        $seenEmpNos = [];

        foreach ($rows as $r) {
            if (! $r || ! array_filter($r, fn ($v) => $v !== null && $v !== '')) {
                continue;
            }
            $empNo = $get($r, 'EMP_NO');
            if ($empNo === '') {
                continue;
            }
            $stats['rows']++;
            $seenEmpNos[] = $empNo;

            $emp = Employee::where('oracle_emp_no', $empNo)->first();
            if (! $emp) {
                $stats['unmatched']++;
                $unmatched[] = sprintf('%-8s %-28s %s', $empNo, $get($r, 'EMP_NAME'), $get($r, 'EMP_EMAIL_ADDRESS') ?: '(no email)');

                continue;
            }
            $stats['matched']++;

            // ── Gender ──────────────────────────────────────────────
            $g      = strtoupper($get($r, 'GENDER'));
            $gender = $g === 'F' ? 'female' : ($g === 'M' ? 'male' : null);

            if ($gender) {
                if ($emp->gender === $gender) {
                    $stats['gender_same']++;
                } else {
                    $emp->gender ? $stats['gender_changed']++ : $stats['gender_new']++;
                    if ($apply) {
                        $emp->gender = $gender;
                    }
                }
            }

            // ── Other fields: report always, write only with --apply-all ──
            $checks = [
                'job_title'         => $get($r, 'JOB_NAME'),
                'oracle_department' => $get($r, 'DEPT_NAME'),
                'oracle_dept_no'    => $get($r, 'DEPT_NO'),
                'oracle_location'   => $get($r, 'LOCATION_NAME'),
            ];
            foreach ($checks as $field => $new) {
                if ($new === '') {
                    continue;
                }
                $cur = trim((string) ($emp->{$field} ?? ''));
                if ($cur !== $new) {
                    $diffs[$field][] = sprintf('#%-6s %-26s %-32s -> %s', $empNo, mb_substr($emp->name, 0, 26), ($cur !== '' ? mb_substr($cur, 0, 32) : '(blank)'), $new);
                    if ($applyAll) {
                        $emp->{$field} = $new;
                    }
                }
            }

            if ($emp->isDirty()) {
                $emp->save();
            }
        }

        // ── Report ──────────────────────────────────────────────────
        $this->newLine();
        $this->info('=== HR list reconcile'.($apply ? '' : ' (DRY RUN — nothing written)').' ===');
        $this->line("Rows in file      : {$stats['rows']}");
        $this->line("Matched employees : {$stats['matched']}");
        $this->line("Unmatched (in file, not in NOC) : {$stats['unmatched']}");
        $this->newLine();
        $this->line('Gender  new: '.$stats['gender_new'].'  changed: '.$stats['gender_changed'].'  already correct: '.$stats['gender_same']);

        foreach ($diffs as $field => $list) {
            $this->newLine();
            $this->warn(strtoupper($field).' differences: '.count($list));
            foreach (array_slice($list, 0, 15) as $line) {
                $this->line('  '.$line);
            }
            if (count($list) > 15) {
                $this->line('  ... +'.(count($list) - 15).' more');
            }
        }

        if ($unmatched) {
            $this->newLine();
            $this->warn('In file but NOT in NOC: '.count($unmatched));
            foreach (array_slice($unmatched, 0, 15) as $line) {
                $this->line('  '.$line);
            }
            if (count($unmatched) > 15) {
                $this->line('  ... +'.(count($unmatched) - 15).' more');
            }
        }

        // Active NOC employees absent from the HR list (possible leavers)
        $missing = Employee::where('status', 'active')
            ->whereNotNull('oracle_emp_no')
            ->whereNotIn('oracle_emp_no', $seenEmpNos)
            ->get(['oracle_emp_no', 'name', 'email']);

        if ($missing->isNotEmpty()) {
            $this->newLine();
            $this->warn('Active in NOC but NOT in the HR list (possible leavers): '.$missing->count());
            foreach ($missing->take(15) as $m) {
                $this->line(sprintf('  #%-6s %-28s %s', $m->oracle_emp_no, mb_substr($m->name, 0, 28), $m->email));
            }
            if ($missing->count() > 15) {
                $this->line('  ... +'.($missing->count() - 15).' more');
            }
        }

        $this->newLine();
        if (! $apply) {
            $this->info('Dry run. Re-run with --apply to write gender, or --apply-all to also write job/dept/location.');
        } else {
            $this->info($applyAll ? 'Applied: gender + job/dept/location.' : 'Applied: gender only.');
        }

        return self::SUCCESS;
    }
}
