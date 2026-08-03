<?php

namespace App\Console\Commands;

use App\Models\Mib;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Copies the vendor MIBs committed under database/mibs/ onto the `local` disk
 * (storage/app/private/mibs) and registers them in the `mibs` table, so they
 * show up in Network → Monitoring → MIB Library exactly like an uploaded file.
 *
 * Idempotent — safe to re-run after every deploy.
 */
class ImportBundledMibs extends Command
{
    protected $signature = 'mibs:import {--force : Overwrite files already present on the storage disk}';

    protected $description = 'Import the MIB files bundled in database/mibs into the MIB library';

    /**
     * Descriptions for the bundled files. Anything in database/mibs without an
     * entry here still gets imported, just with a generic description.
     */
    private const DESCRIPTIONS = [
        'FORTINET-CORE-MIB' => 'Fortinet enterprise root (.1.3.6.1.4.1.12356) — serial number, admin table and the shared fnTrap* notifications used by every Fortinet appliance.',
        'FORTINET-FORTIGATE-MIB' => 'FortiGate / FortiWiFi firewalls (.1.3.6.1.4.1.12356.101) — CPU, memory, sessions, hardware sensors, IPsec tunnels, SD-WAN link monitors, HA cluster stats.',
    ];

    public function handle(): int
    {
        $source = database_path('mibs');

        if (! is_dir($source)) {
            $this->error("Bundled MIB directory not found: {$source}");

            return self::FAILURE;
        }

        $files = glob($source.'/*.mib') ?: [];

        if ($files === []) {
            $this->warn("No .mib files found in {$source}");

            return self::SUCCESS;
        }

        $disk = Storage::disk('local');
        $imported = 0;
        $updated = 0;

        foreach ($files as $file) {
            $basename = basename($file);
            $name = pathinfo($basename, PATHINFO_FILENAME);
            $target = 'mibs/'.$basename;

            if (! $disk->exists($target) || $this->option('force')) {
                $disk->put($target, file_get_contents($file));
                $this->line("  copied  {$target}");
            } else {
                $this->line("  exists  {$target} (use --force to overwrite)");
            }

            $mib = Mib::where('name', $name)->first();

            if ($mib) {
                $mib->update([
                    'description' => self::DESCRIPTIONS[$name] ?? $mib->description,
                    'file_path' => $target,
                ]);
                $updated++;
            } else {
                Mib::create([
                    'name' => $name,
                    'description' => self::DESCRIPTIONS[$name] ?? 'Bundled vendor MIB.',
                    'file_path' => $target,
                ]);
                $imported++;
            }
        }

        $this->info("MIB library synced — {$imported} added, {$updated} updated.");

        return self::SUCCESS;
    }
}
