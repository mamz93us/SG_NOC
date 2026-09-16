<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\IdentityLicense;
use App\Models\LicenseAssignment;
use App\Services\Identity\GraphService;
use App\Services\Identity\LicenseAssignmentSync;
use Illuminate\Console\Command;

/**
 * Copies Microsoft 365 licences into the NOC: who holds which licence, and how
 * many seats each has. Microsoft → NOC only.
 *
 *   php artisan identity:sync-license-assignments --check
 *   php artisan identity:sync-license-assignments
 *
 * Scheduled every five minutes, so a licence assigned or removed in Microsoft
 * (admin centre, licensing group, or the NOC's own buttons) shows on the
 * employee in the NOC within minutes. It reads Graph directly each run.
 *
 * --check fetches the same data and prints what would change, writing nothing.
 */
class SyncLicenseAssignments extends Command
{
    protected $signature = 'identity:sync-license-assignments
        {--check : Show what would change, change nothing}';

    protected $description = 'Copy Microsoft 365 licence assignments and seat counts into the NOC';

    public function handle(): int
    {
        if (IdentityLicense::whereNotNull('license_id')->doesntExist()) {
            $this->error('No Microsoft SKU is linked to an NOC licence yet: run identity:sync first.');

            return self::FAILURE;
        }

        $check = (bool) $this->option('check');

        try {
            $result = (new LicenseAssignmentSync(new GraphService))->run(apply: ! $check);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $plan = $result['plan'];
        $this->line('  Microsoft users read               '.$result['users']);
        $this->line('  Assignments Microsoft holds        '.$plan->desired);
        $this->line('  Assignments the NOC held           '.$plan->current);
        $this->line('  Accounts matching no employee      '.count($plan->unmatched));
        $this->line('  Accounts matching several          '.count($plan->ambiguous));

        foreach (array_slice($plan->unmatched, 0, 10) as $upn) {
            $this->line("    no employee: {$upn}");
        }
        foreach (array_slice($plan->ambiguous, 0, 10, true) as $upn => $ids) {
            $this->line('    several employees (#'.implode(', #', $ids)."): {$upn}");
        }
        foreach ($result['seats'] as $seat) {
            $this->line("  Seats {$seat['name']}: {$seat['from']} → {$seat['to']}");
        }

        foreach ($result['errors'] as $error) {
            $this->error($error);
        }

        if ($check) {
            $this->info('Check only: would add '.count($plan->add).' and remove '.($result['removals_refused'] ? 0 : count($plan->remove)).' assignment(s), change '.count($result['seats']).' seat count(s).');
            foreach (array_slice($plan->add, 0, 15) as $row) {
                $this->line("    + licence #{$row['license_id']} → employee #{$row['employee_id']} ({$row['upn']})");
            }
            $removals = LicenseAssignment::whereKey(array_slice($plan->remove, 0, 15))->get(['id', 'license_id', 'assignable_id', 'notes']);
            foreach ($removals as $row) {
                $this->line("    − licence #{$row->license_id} from employee #{$row->assignable_id} (row #{$row->id}, \"{$row->notes}\")");
            }

            return self::SUCCESS;
        }

        $total = LicenseAssignment::where('assignable_type', Employee::class)
            ->whereIn('license_id', IdentityLicense::whereNotNull('license_id')->pluck('license_id'))
            ->count();
        $this->info("Added {$result['added']}, removed {$result['removed']}, seat counts changed ".count($result['seats']).". The NOC now records {$total} Microsoft licence assignment(s).");

        return $result['errors'] ? self::FAILURE : self::SUCCESS;
    }
}
