<?php

namespace App\Console\Commands;

use App\Models\Employee;
use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Reconciles an HR employee export (xlsx) against the NOC employee records.
 *
 * Matches each HR row on THREE signals — EMP_NO -> oracle_emp_no, email, and name —
 * scored (id=5, email=4, name=3). Requires TWO signals to agree (id+email, email+name
 * or id+name); NO single signal is trusted, because:
 *   - EMP_NO collides between the SSS-Egypt and SamirGroup/Saudi series, and
 *   - mail-less staff (drivers/guards) are listed under their MANAGER's email.
 * A top-score tie is AMBIGUOUS and a lone signal is SINGLE-SIGNAL — both skipped.
 *
 * Sets gender and reports other field differences. Dry-run by default; --apply writes
 * gender only; --apply-all also writes job title / department / location.
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
        $idx = array_flip($head);

        foreach (['EMP_NO', 'EMP_NAME', 'GENDER'] as $required) {
            if (! isset($idx[$required])) {
                $this->error("Missing required column: {$required}");

                return self::FAILURE;
            }
        }

        $get = fn (array $r, string $c) => isset($idx[$c]) ? trim((string) ($r[$idx[$c]] ?? '')) : '';

        $apply = (bool) $this->option('apply') || (bool) $this->option('apply-all');
        $applyAll = (bool) $this->option('apply-all');

        // Name normalisers: lower + single-spaced (exact), and a word-sorted variant
        // so "Abbas Alramini" and "Alramini Abbas" still count as the same name.
        $norm = fn (string $s): string => preg_replace('/\s+/', ' ', mb_strtolower(trim($s)));
        $sortName = function (string $s) use ($norm): string {
            $t = explode(' ', $norm($s));
            sort($t);

            return implode(' ', $t);
        };

        $stats = [
            'rows' => 0, 'matched' => 0, 'unmatched' => 0, 'ambiguous' => 0, 'lowconf' => 0,
            'gender_new' => 0, 'gender_changed' => 0, 'gender_same' => 0,
            'method' => [],
        ];
        $unmatched = [];
        $ambiguous = [];
        $lowconf = [];
        $diffs = [];
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

            $name = $get($r, 'EMP_NAME');
            $email = mb_strtolower($get($r, 'EMP_EMAIL_ADDRESS'));

            // Gather candidates by ANY of the three keys, then score each on how many
            // agree (id=5, email=4, name=3). Combining signals resolves the SSS/Saudi
            // emp_no collision and shared emails; a single signal that ties across people
            // is treated as ambiguous rather than guessed.
            $cands = collect();
            if ($empNo !== '') {
                $cands = $cands->merge(Employee::where('oracle_emp_no', $empNo)->get());
            }
            if ($email !== '') {
                $cands = $cands->merge(Employee::whereRaw('LOWER(email) = ?', [$email])->get());
            }
            if ($name !== '') {
                $cands = $cands->merge(Employee::whereRaw("REPLACE(LOWER(name), ' ', '') = ?", [str_replace(' ', '', $norm($name))])->get());
            }
            $cands = $cands->unique('id');

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
                if ($email !== '' && mb_strtolower((string) $c->email) === $email) {
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

            // Require TWO signals to agree (>=7), unambiguously. No single signal is
            // trusted: email alone can be the manager's (mail-less staff) and emp_no
            // alone collides across the SSS/Saudi series. Single signal / tie = skipped.
            $emp = null;
            $method = 'unmatched';
            if ($best && $bestScore >= 7 && $topCount === 1) {
                $emp = $best;
                $method = 'multi ('.implode('+', $bestSig).')';
            } elseif ($best && $bestScore >= 7 && $topCount > 1) {
                $method = 'ambiguous';
            } elseif ($best && $bestScore > 0) {
                $method = 'single ('.implode('+', $bestSig).')';
            }

            if (! $emp) {
                $line = sprintf('%-8s %-28s %s', $empNo, mb_substr($name, 0, 28), $email ?: '(no email)');
                if ($method === 'ambiguous') {
                    $stats['ambiguous']++;
                    $ambiguous[] = $line;
                } elseif (str_starts_with($method, 'single')) {
                    $stats['lowconf']++;
                    $lowconf[] = $line.'  ['.$method.']';
                } else {
                    $stats['unmatched']++;
                    $unmatched[] = $line;
                }

                continue;
            }
            $stats['matched']++;
            $stats['method'][$method] = ($stats['method'][$method] ?? 0) + 1;

            // ── Gender ──────────────────────────────────────────────
            $g = strtoupper($get($r, 'GENDER'));
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
                'job_title' => $get($r, 'JOB_NAME'),
                'oracle_department' => $get($r, 'DEPT_NAME'),
                'oracle_dept_no' => $get($r, 'DEPT_NO'),
                'oracle_location' => $get($r, 'LOCATION_NAME'),
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
        $this->line("Ambiguous (id/email tie — skipped) : {$stats['ambiguous']}");
        $this->line("Low-confidence (name-only — skipped) : {$stats['lowconf']}");
        $this->line("Unmatched (in file, not in NOC) : {$stats['unmatched']}");
        if ($stats['method']) {
            ksort($stats['method']);
            $this->line('Match method     : '.implode('  ', array_map(fn ($k, $v) => "{$k}={$v}", array_keys($stats['method']), array_values($stats['method']))));
        }
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

        $report = function (string $title, array $list): void {
            if (! $list) {
                return;
            }
            $this->newLine();
            $this->warn($title.': '.count($list));
            foreach (array_slice($list, 0, 15) as $line) {
                $this->line('  '.$line);
            }
            if (count($list) > 15) {
                $this->line('  ... +'.(count($list) - 15).' more');
            }
        };

        $report('AMBIGUOUS — two signals tied across more than one person (skipped, resolve by hand)', $ambiguous);
        $report('SINGLE-SIGNAL — only id OR email OR name matched, not enough to trust (skipped)', $lowconf);
        $report('In file but NOT in NOC', $unmatched);

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
