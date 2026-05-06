<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Models\DeviceMac;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * radius:sync-macs
 *
 * Pulls MAC addresses from the `devices` table (phones, switches, APs,
 * printers, etc.) into `device_macs` so they become RADIUS-eligible.
 *
 * Intune-managed Windows PCs are already populated by `intune:sync-net-data`,
 * which sets `azure_device_id`. This command is the equivalent for the
 * `device_id` side: every device with `mac_address` and/or `wifi_mac` gets
 * a normalised entry.
 *
 * After running, VLAN assignment is automatic via radius_branch_vlan_policy
 * — match by branch + adapter_type (ethernet/wifi) + device_type (phone,
 * printer, etc.) and FreeRADIUS returns the right Tunnel-Private-Group-Id.
 *
 * Idempotent: uses DeviceMac::upsertMac, so re-running just refreshes
 * last_seen_at.
 */
class SyncRadiusMacs extends Command
{
    protected $signature = 'radius:sync-macs
        {--dry-run : Print what would change but don\'t write to device_macs}';

    protected $description = 'Sync MAC addresses from devices (phones, APs, printers) into the RADIUS registry.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $invalid = 0;

        $devices = Device::query()
            ->where(function ($q) {
                $q->whereNotNull('mac_address')->where('mac_address', '!=', '')
                  ->orWhere(function ($q2) {
                      $q2->whereNotNull('wifi_mac')->where('wifi_mac', '!=', '');
                  });
            })
            ->get();

        $this->info("[radius:sync-macs] Scanning {$devices->count()} devices with MAC data...");

        foreach ($devices as $device) {
            // ── Wired (LAN) MAC ────────────────────────────────────
            if (!empty($device->mac_address)) {
                $result = $this->syncOne(
                    rawMac:      $device->mac_address,
                    device:      $device,
                    adapterType: 'ethernet',
                    dryRun:      $dryRun,
                );
                $this->tally($result, $created, $updated, $skipped, $invalid);
            }

            // ── Wireless MAC (IP phones, mostly) ────────────────────
            if (!empty($device->wifi_mac)) {
                $result = $this->syncOne(
                    rawMac:      $device->wifi_mac,
                    device:      $device,
                    adapterType: 'wifi',
                    dryRun:      $dryRun,
                );
                $this->tally($result, $created, $updated, $skipped, $invalid);
            }
        }

        $prefix = $dryRun ? '[DRY-RUN] ' : '';
        $this->info(sprintf(
            '%sDone. created=%d  updated=%d  skipped=%d  invalid=%d',
            $prefix, $created, $updated, $skipped, $invalid
        ));

        if ($invalid > 0) {
            $this->warn("  → {$invalid} MAC(s) skipped due to invalid format. Check device records.");
        }

        return self::SUCCESS;
    }

    /**
     * @return string  one of: 'created', 'updated', 'skipped', 'invalid'
     */
    private function syncOne(string $rawMac, Device $device, string $adapterType, bool $dryRun): string
    {
        $normalized = DeviceMac::normalizeMac($rawMac);
        if ($normalized === null) {
            Log::warning("[radius:sync-macs] Invalid MAC '{$rawMac}' on device #{$device->id} ({$device->name})");
            return 'invalid';
        }

        $existing = DeviceMac::where('mac_address', $normalized)->first();

        // Skip if this MAC is already linked to a different owner — don't
        // clobber an Intune-synced row, for example.
        if ($existing && $existing->device_id !== $device->id && $existing->azure_device_id !== null) {
            return 'skipped';
        }

        if ($dryRun) {
            return $existing ? 'updated' : 'created';
        }

        $isCreate = $existing === null;

        DeviceMac::upsertMac($rawMac, [
            'device_id'           => $device->id,
            'adapter_type'        => $adapterType,
            'adapter_name'        => $adapterType === 'wifi' ? 'Wi-Fi' : 'LAN',
            'adapter_description' => $device->manufacturer
                ? trim("{$device->manufacturer} {$device->model}")
                : null,
            'is_active'           => $device->status !== 'retired',
            'source'              => 'import',
        ]);

        return $isCreate ? 'created' : 'updated';
    }

    private function tally(string $result, int &$created, int &$updated, int &$skipped, int &$invalid): void
    {
        match ($result) {
            'created' => $created++,
            'updated' => $updated++,
            'skipped' => $skipped++,
            'invalid' => $invalid++,
        };
    }
}
