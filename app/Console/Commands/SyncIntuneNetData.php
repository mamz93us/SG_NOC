<?php

namespace App\Console\Commands;

use App\Models\AzureDevice;
use App\Models\Setting;
use App\Services\Identity\GraphService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * intune:sync-net-data
 *
 * Fetches Intune PowerShell script run results and updates the
 * azure_devices table with network/hardware data collected on each
 * endpoint by NOC-DeviceInfo.ps1 (or any script that writes JSON
 * to stdout).
 *
 * Expected JSON output from the PowerShell script:
 * {
 *   "teamviewer_id": "123456789",
 *   "tv_version":    "15.x.x",
 *   "cpu_name":      "Intel Core i7-1165G7",
 *   "wifi_mac":      "AA-BB-CC-DD-EE-FF",
 *   "ethernet_mac":  "11-22-33-44-55-66",
 *   "usb_eth_adapters": [
 *       { "name": "USB-C LAN", "mac": "xx-xx-xx-xx-xx-xx", "desc": "Realtek USB" }
 *   ]
 * }
 *
 * Usage:
 *   php artisan intune:sync-net-data
 *   php artisan intune:sync-net-data --script-id=<GUID>
 */
class SyncIntuneNetData extends Command
{
    protected $signature = 'intune:sync-net-data
        {--script-id= : Override the Intune deviceManagementScript GUID stored in settings}';

    protected $description = 'Sync TeamViewer ID, CPU, Wi-Fi MAC, Ethernet MAC from Intune script run results.';

    public function handle(): int
    {
        $settings = Setting::get();

        $scriptId = $this->option('script-id')
            ?? $settings->intune_net_data_script_id
            ?? null;

        if (! $scriptId) {
            $this->error(
                'No script ID available. Pass --script-id=<GUID> or set '
                . 'intune_net_data_script_id in Settings.'
            );
            return self::FAILURE;
        }

        $graph   = new GraphService();
        $updated = 0;
        $skipped = 0;
        $failed  = 0;

        $this->info("[intune:sync-net-data] Fetching run states for script: {$scriptId}");

        $graph->listScriptRunStates(
            $scriptId,
            function (array $states) use (&$updated, &$skipped, &$failed) {
                foreach ($states as $state) {

                    // ── 1. Skip non-successful runs ────────────────────
                    if (($state['runState'] ?? '') !== 'success') {
                        $failed++;
                        continue;
                    }

                    // ── 2. Extract Intune managedDeviceId from composite key ──
                    // The 'id' field is "{scriptId}:{managedDeviceId}".
                    // 'managedDeviceId' is NOT returned as a separate selectable field.
                    $compositeId     = $state['id'] ?? '';
                    $parts           = explode(':', $compositeId);
                    $managedDeviceId = $parts[1] ?? null;

                    if (! $managedDeviceId) {
                        $this->warn("  ⚠ Could not extract managedDeviceId from composite id: {$compositeId}");
                        $skipped++;
                        continue;
                    }

                    // ── 3. Parse the script stdout (JSON) ─────────────
                    $raw  = trim($state['resultMessage'] ?? '');
                    $data = json_decode($raw, true);

                    if (! is_array($data)) {
                        $this->warn("  ⚠ Non-JSON resultMessage for device {$managedDeviceId}: {$raw}");
                        $skipped++;
                        continue;
                    }

                    // ── 4. Match to local AzureDevice record ───────────
                    // azure_device_id stores the Intune managedDeviceId
                    $device = AzureDevice::where('azure_device_id', $managedDeviceId)->first();

                    if (! $device) {
                        // Device not yet synced via identity:sync — skip silently
                        $skipped++;
                        continue;
                    }

                    // ── 5. Map usb_eth_adapters → JSON string ──────────
                    $usbEthJson = null;
                    if (! empty($data['usb_eth_adapters']) && is_array($data['usb_eth_adapters'])) {
                        $usbEthJson = json_encode($data['usb_eth_adapters']);
                    }

                    // ── 6. Update the device ───────────────────────────
                    $device->update([
                        'teamviewer_id'      => $data['teamviewer_id']  ?? $device->teamviewer_id,
                        'tv_version'         => $data['tv_version']      ?? $device->tv_version,
                        'cpu_name'           => $data['cpu_name']        ?? $device->cpu_name,
                        'wifi_mac'           => $data['wifi_mac']        ?? $device->wifi_mac,
                        'ethernet_mac'       => $data['ethernet_mac']    ?? $device->ethernet_mac,
                        'usb_eth_data'       => $usbEthJson              ?? $device->usb_eth_data,
                        'net_data_synced_at' => now(),
                    ]);

                    $updated++;
                }
            }
        );

        $summary = "✅ Done — Updated: {$updated} | Skipped: {$skipped} | Failed/Pending: {$failed}";
        $this->info($summary);
        Log::info("[intune:sync-net-data] script={$scriptId} {$summary}");

        return self::SUCCESS;
    }
}
