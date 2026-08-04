<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\IdentityLicense;
use App\Models\IdentityUser;
use App\Models\LicenseAssignment;
use App\Services\Identity\IdentitySyncService;
use Illuminate\Console\Command;

/**
 * Rebuilds the per-employee licence assignments from what Azure says each user
 * holds, without running the full identity sync.
 *
 *   php artisan identity:sync-license-assignments --check
 *   php artisan identity:sync-license-assignments
 *
 * The same step runs at the end of `identity:sync`, but that is the heaviest
 * job in the app; having it standalone means you can fix or verify licence
 * assignments in seconds instead of waiting for a full sync.
 *
 * --check reports the inputs it depends on and changes nothing, which is how
 * you tell "nothing to do" apart from "a prerequisite is missing".
 */
class SyncLicenseAssignments extends Command
{
    protected $signature = 'identity:sync-license-assignments
        {--check : Report the inputs and coverage, change nothing}';

    protected $description = 'Sync each employee\'s Azure licences into ITAM licence assignments';

    public function handle(IdentitySyncService $sync): int
    {
        $usersWithSkus = IdentityUser::whereNotNull('azure_id')
            ->get(['id', 'azure_id', 'user_principal_name', 'mail', 'assigned_licenses'])
            ->filter(fn ($u) => is_array($u->assigned_licenses) && count($u->assigned_licenses) > 0);

        $linkedSkus = IdentityLicense::whereNotNull('license_id')->count();
        $totalSkus = IdentityLicense::count();

        $this->line('  Azure users holding licences   '.$usersWithSkus->count());
        $this->line('  SKUs linked to an ITAM licence '.$linkedSkus.' of '.$totalSkus);

        // The three prerequisites, called out separately because each fails
        // silently inside the sync and looks identical from the outside.
        if ($totalSkus === 0) {
            $this->error('No Azure SKUs synced yet — run identity:sync first.');

            return self::FAILURE;
        }

        if ($linkedSkus === 0) {
            $this->error('No SKU is linked to an ITAM licence, so there is nothing to assign.');

            return self::FAILURE;
        }

        if ($usersWithSkus->isEmpty()) {
            $this->error('No Azure user has any licence recorded — identity:sync is not populating assigned_licenses.');

            return self::FAILURE;
        }

        // How many of those users actually resolve to an employee? This is the
        // usual culprit: licences exist, but the identity user never matches a
        // local employee record, so nothing can be attached to anyone.
        $matched = 0;
        foreach ($usersWithSkus as $iu) {
            $exists = Employee::where('azure_id', $iu->azure_id)
                ->orWhere('email', $iu->user_principal_name)
                ->orWhere('email', $iu->mail)
                ->exists();
            if ($exists) {
                $matched++;
            }
        }

        $this->line('  …of which match an employee    '.$matched);
        $this->newLine();

        if ($matched === 0) {
            $this->error('None of those Azure users match an employee record (by azure_id or email), so no assignment can be made.');

            return self::FAILURE;
        }

        if ($this->option('check')) {
            $current = LicenseAssignment::where('assignable_type', Employee::class)->count();
            $this->info("Check only — {$current} employee licence assignment(s) currently recorded.");

            return self::SUCCESS;
        }

        $errors = [];
        $count = $sync->syncEmployeeLicenseAssignments($errors);

        foreach ($errors as $e) {
            $this->error($e);
        }

        $employees = LicenseAssignment::where('assignable_type', Employee::class)
            ->distinct('assignable_id')
            ->count('assignable_id');
        $total = LicenseAssignment::where('assignable_type', Employee::class)->count();

        $this->info("Processed {$count} assignment(s).");
        $this->info("Now recorded: {$total} assignment(s) across {$employees} employee(s).");

        return empty($errors) ? self::SUCCESS : self::FAILURE;
    }
}
