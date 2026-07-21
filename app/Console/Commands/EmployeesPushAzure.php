<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Services\Identity\AzureContactSyncService;
use Illuminate\Console\Command;

/**
 * Pushes NOC employee contact fields to Azure AD for specific employees.
 *
 * Needed because bulk/import paths (e.g. employees:sync-hr-list) write straight to
 * the employees table with $emp->save(), bypassing the auto-push in
 * EmployeeController::update(). Azure then holds stale values — which matters for
 * the server-side signature transport rule, since it fills %%Title%% etc. from Azure.
 *
 * Reuses AzureContactSyncService (computeFromEmployee + applyToEmployee), which
 * PATCHes Graph, mirrors to IdentityUser, and writes an activity log.
 */
class EmployeesPushAzure extends Command
{
    protected $signature = 'employees:push-azure
                            {--emp-no=* : Match by oracle_emp_no (repeatable)}
                            {--id=* : Match by employee id (repeatable)}
                            {--all : All active employees with an azure_id}
                            {--domain= : Limit --all to employees whose email is on this domain}
                            {--dry : Print what would be pushed; write nothing}';

    protected $description = 'Push NOC employee contact fields (name, title, dept, phones, office) to Azure AD';

    public function __construct(private readonly AzureContactSyncService $sync)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $empNos = (array) $this->option('emp-no');
        $ids    = (array) $this->option('id');
        $all    = (bool) $this->option('all');
        $domain = strtolower((string) $this->option('domain'));

        if (! $empNos && ! $ids && ! $all) {
            $this->error('Pass --emp-no=, --id=, or --all');

            return self::FAILURE;
        }

        $employees = Employee::with(['branch', 'department', 'identityUser'])
            ->whereNotNull('azure_id')
            ->when($all, fn ($q) => $q->where('status', 'active'))
            ->when($all && $domain, fn ($q) => $q->where('email', 'like', '%@'.$domain))
            ->when(! $all, fn ($q) => $q->where(function ($w) use ($empNos, $ids) {
                if ($empNos) {
                    $w->orWhereIn('oracle_emp_no', $empNos);
                }
                if ($ids) {
                    $w->orWhereIn('id', $ids);
                }
            }))
            ->orderBy('oracle_emp_no')
            ->get();

        if ($employees->isEmpty()) {
            $this->error('No employees matched.');

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry');
        $this->info('=== Push NOC -> Azure'.($dry ? ' (DRY RUN — nothing written)' : '').' ===');
        $this->line('Matched employees: '.$employees->count());
        $this->newLine();

        $ok = $skipped = $failed = 0;

        foreach ($employees as $emp) {
            $label = sprintf('#%-6s %-24s', $emp->oracle_emp_no ?: '-', mb_substr($emp->name, 0, 24));

            if (empty($emp->azure_id)) {
                $this->warn("SKIP {$label} no azure_id");
                $skipped++;

                continue;
            }

            $proposed = $this->sync->computeFromEmployee($emp);
            if ($proposed === []) {
                $this->warn("SKIP {$label} nothing to push");
                $skipped++;

                continue;
            }

            if ($dry) {
                $this->line("WOULD PUSH {$label}");
                foreach ($proposed as $k => $v) {
                    $this->line('        '.str_pad($k, 16).(is_array($v) ? implode(', ', $v) : $v));
                }
                $ok++;

                continue;
            }

            try {
                $this->sync->applyToEmployee($emp, $proposed);
                $this->info("OK   {$label} ".($proposed['jobTitle'] ?? ''));
                $ok++;
            } catch (\Throwable $e) {
                if (AzureContactSyncService::isProtectedAdminError($e)) {
                    $this->warn("SKIP {$label} protected admin account (Entra blocks app-only writes)");
                    $skipped++;
                } elseif (AzureContactSyncService::isMissingUserError($e)) {
                    $this->warn("SKIP {$label} no such user in Entra (stale azure_id)");
                    $skipped++;
                } else {
                    $this->error("FAIL {$label} ".$e->getMessage());
                    $failed++;
                }
            }
        }

        $this->newLine();
        $this->info(($dry ? 'Would push: ' : 'Pushed: ')."{$ok}   skipped: {$skipped}   failed: {$failed}");

        if ($dry) {
            $this->line('Re-run without --dry to apply.');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
