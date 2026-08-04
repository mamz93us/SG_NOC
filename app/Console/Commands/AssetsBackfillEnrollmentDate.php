<?php

namespace App\Console\Commands;

use App\Models\AzureDevice;
use App\Models\EmployeeAsset;
use Illuminate\Console\Command;

/**
 * Repoints existing laptop assignment dates at the Intune enrolment date.
 *
 * The Azure/Intune sync used to stamp assigned_date = now(), so a machine
 * enrolled in 2022 shows as assigned on whatever day the sync happened to run.
 * AzureSyncController now uses the enrolment date for new links; this fixes the
 * rows created before that.
 *
 *   php artisan assets:backfill-enrollment-date --dry-run
 *   php artisan assets:backfill-enrollment-date
 *
 * Only touches assignments whose device is linked to an Azure device that
 * reports an enrolment date, and only where the two differ. Returned
 * assignments are left alone — their dates are history.
 */
class AssetsBackfillEnrollmentDate extends Command
{
    protected $signature = 'assets:backfill-enrollment-date
        {--dry-run : Show what would change and change nothing}
        {--include-returned : Also fix assignments that have been returned}';

    protected $description = 'Set laptop assignment dates from the Intune enrolment date';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        // Azure devices that know when they were enrolled and map to an asset.
        $enrolment = AzureDevice::whereNotNull('device_id')
            ->whereNotNull('enrolled_date')
            ->pluck('enrolled_date', 'device_id');

        if ($enrolment->isEmpty()) {
            $this->info('No Azure devices with both an enrolment date and a linked asset.');

            return self::SUCCESS;
        }

        $query = EmployeeAsset::whereIn('asset_id', $enrolment->keys());
        if (! $this->option('include-returned')) {
            $query->whereNull('returned_date');
        }

        $assets = $query->with('employee:id,name')->get();

        $changes = [];
        foreach ($assets as $asset) {
            $enrolled = $enrolment[$asset->asset_id] ?? null;
            if (! $enrolled) {
                continue;
            }

            $target = $enrolled->copy()->startOfDay();
            $current = $asset->assigned_date?->copy()->startOfDay();

            if ($current && $current->equalTo($target)) {
                continue;
            }

            $changes[] = [$asset, $current, $target];
        }

        if (empty($changes)) {
            $this->info('Nothing to change — every assignment already matches its enrolment date.');

            return self::SUCCESS;
        }

        $this->info(count($changes).' assignment(s) to correct'.($dry ? '   [DRY RUN]' : '').':');
        $this->newLine();

        $this->table(
            ['Employee', 'Asset', 'Assigned (now)', 'Enrolled (target)'],
            collect($changes)->take(30)->map(fn ($c) => [
                $c[0]->employee?->name ?? '—',
                $c[0]->asset_id,
                $c[1]?->format('d M Y') ?? '(none)',
                $c[2]->format('d M Y'),
            ])->all()
        );

        if (count($changes) > 30) {
            $this->comment('… and '.(count($changes) - 30).' more.');
        }

        if ($dry) {
            $this->newLine();
            $this->info('Dry run — nothing changed.');

            return self::SUCCESS;
        }

        if (! $this->confirm('Apply these '.count($changes).' change(s)?', true)) {
            return self::SUCCESS;
        }

        foreach ($changes as [$asset, , $target]) {
            $asset->update(['assigned_date' => $target]);
        }

        $this->info('Updated '.count($changes).' assignment date(s).');

        return self::SUCCESS;
    }
}
