<?php

namespace App\Console\Commands;

use App\Models\IdentityLicense;
use App\Models\License;
use App\Support\MicrosoftSkuNames;
use Illuminate\Console\Command;

/**
 * Renames already-synced Microsoft licences from the raw SKU part number to the
 * product name shown in the Microsoft 365 admin centre.
 *
 *   php artisan licenses:rename-from-sku --dry-run
 *   php artisan licenses:rename-from-sku
 *
 * The sync now stores the friendly name for new SKUs; this fixes rows created
 * before that. Only renames an ITAM licence whose name still equals its SKU
 * part number — anything an admin has renamed by hand is left alone.
 */
class LicensesRenameFromSku extends Command
{
    protected $signature = 'licenses:rename-from-sku
        {--dry-run : Show what would change and change nothing}';

    protected $description = 'Give synced Microsoft licences their real product names';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $licenses = IdentityLicense::with('itamLicense')->get();

        if ($licenses->isEmpty()) {
            $this->info('No Azure licences synced yet — run the identity sync first.');

            return self::SUCCESS;
        }

        $rows = [];
        $unmapped = [];

        foreach ($licenses as $il) {
            $friendly = MicrosoftSkuNames::forPartNumber($il->sku_part_number);

            if (! MicrosoftSkuNames::isKnown($il->sku_part_number)) {
                $unmapped[] = $il->sku_part_number;
            }

            $identityNeeds = $il->display_name !== $friendly;

            // Only touch the ITAM row if it still carries the raw part number.
            // A name someone has edited is theirs, not ours to overwrite.
            $itam = $il->itamLicense;
            $itamNeeds = $itam && $itam->license_name === $il->sku_part_number && $friendly !== $il->sku_part_number;

            if (! $identityNeeds && ! $itamNeeds) {
                continue;
            }

            $rows[] = [$il, $friendly, $identityNeeds, $itamNeeds];
        }

        if (empty($rows)) {
            $this->info('Nothing to rename — every licence already has its product name.');
        } else {
            $this->info(count($rows).' licence(s) to rename'.($dry ? '   [DRY RUN]' : '').':');
            $this->table(
                ['SKU part number', 'Current', 'New name', 'Also renames ITAM'],
                collect($rows)->map(fn ($r) => [
                    $r[0]->sku_part_number,
                    $r[0]->display_name,
                    $r[1],
                    $r[3] ? 'yes' : '—',
                ])->all()
            );
        }

        if ($unmapped) {
            $this->newLine();
            $this->warn('No product-name mapping for these SKUs — they get a tidied part number instead.');
            $this->comment('Add them to App\Support\MicrosoftSkuNames::NAMES if you want exact names:');
            foreach (array_unique($unmapped) as $u) {
                $this->line("    {$u}");
            }
        }

        if ($dry || empty($rows)) {
            if ($dry && $rows) {
                $this->newLine();
                $this->info('Dry run — nothing changed.');
            }

            return self::SUCCESS;
        }

        if (! $this->confirm('Apply '.count($rows).' rename(s)?', true)) {
            return self::SUCCESS;
        }

        foreach ($rows as [$il, $friendly, $identityNeeds, $itamNeeds]) {
            if ($identityNeeds) {
                $il->update(['display_name' => $friendly]);
            }
            if ($itamNeeds) {
                License::whereKey($il->license_id)->update(['license_name' => $friendly]);
            }
        }

        $this->info('Renamed '.count($rows).' licence(s).');

        return self::SUCCESS;
    }
}
