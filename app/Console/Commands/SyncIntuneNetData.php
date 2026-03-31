<?php

namespace App\Console\Commands;

use App\Models\AzureDevice;
use App\Models\DeviceMac;
use App\Models\ServiceSyncLog;
use App\Models\Setting;
use App\Services\Identity\GraphService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Pulls the latest NOC-DeviceInfo.ps1 run results from Intune (Graph beta API)
 * and stores them on the matching azure_devices record.
 *
 * Also upserts every MAC address into the central device_macs table so the
 * RADIUS server can resolve any MAC back to a managed device.
 *
 * Requirements
 * ────────────
 * 1. Deploy NOC-DeviceInfo.ps1 (resources/intune-scripts/NOC-DeviceInfo.ps1)
 *    to Intune → Devices → Scripts → Windows → Add → run as SYSTEM 64-bit.
 * 2. Note the script GUID from the Intune portal URL.
 * 3. Save that GUID in Settings → intune_net_data_script_id.
 *
 * Graph API permissions required (Application type, admin-consented):
 *   DeviceManagementConfiguration.Read.All
 *   DeviceManagementManagedDevices.Read.All
 */
class SyncIntuneNetData extends Command
{
    protected $signature = 'intune:sync-net-data
                            {--script-id= : Intune script GUID (overrides Settings value)}
                            {--force      : Re-sync devices even if net_data_synced_at is recent}';

    protected $description = 'Sync TeamViewer ID, CPU, Wi-Fi/Ethernet/USB MAC addresses from Intune script results.';

    // ─────────────────────────────────────────────────────────────────
    // Entry point
    // ─────────────────────────────────────────────────────────────────

    public function handle(): int
    {
        set_time_limit(0);
        ini_set('memory_limit', '512M');

        // ── Resolve script ID ─────────────────────────────────────────
        $settings = Setting::get();
        $scriptId = $this->option('script-id') ?: $settings->intune_net_data_script_id;

        if (empty($scriptId)) {
            $this->error(
                'No Intune script ID provided. ' .
                'Pass --script-id=<GUID> or set intune_net_data_script_id in Settings.'
            );
            return self::FAILURE;
        }

        // ── Graph credentials ─────────────────────────────────────────
        if (empty($settings->graph_tenant_id) || empty($settings->graph_client_id) || empty($settings->graph_client_secret)) {
            $this->error('Microsoft Graph credentials are not configured in Settings.');
            return self::FAILURE;
        }

        // ── Cache lock — prevent overlapping runs ─────────────────────
        $lock = Cache::lock('intune_net_data_running', 3600);

        if (! $lock->get()) {
            $this->warn('intune:sync-net-data is already running. Use --force to override stale lock.');
            return self::FAILURE;
        }

        $this->info("Starting Intune net-data sync  [script: {$scriptId}]…");
        $log = ServiceSyncLog::start('intune_net_data');

        $counters = ['updated' => 0, 'skipped' => 0, 'failed' => 0];

        try {
            $graph = new GraphService();
            $force = (bool) $this->option('force');

            $graph->listScriptRunStates($scriptId, function (array $page) use (&$counters, $force) {
                foreach ($page as $state) {
                    $this->processState($state, $counters, $force);
                }
            });

            $log->update([
                'status'         => 'completed',
                'records_synced' => $counters['updated'],
                'completed_at'   => now(),
            ]);

            $this->newLine();
            $this->info(
                "✅ Intune net-data sync completed. " .
                "Updated: {$counters['updated']} | " .
                "Skipped: {$counters['skipped']} | " .
                "Failed: {$counters['failed']}"
            );
            return self::SUCCESS;

        } catch (\Throwable $e) {
            $log->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at'  => now(),
            ]);
            Log::error('intune:sync-net-data failed: ' . $e->getMessage());
            $this->error('Sync failed: ' . $e->getMessage());
            return self::FAILURE;

        } finally {
            $lock->release();
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // Per-state processing
    // ─────────────────────────────────────────────────────────────────

    private function processState(array $state, array &$counters, bool $force): void
    {
        // ── Only process successful runs ──────────────────────────────
        if (($state['runState'] ?? '') !== 'success') {
            $counters['skipped']++;
            return;
        }

        // ── Resolve managedDeviceId ───────────────────────────────────
        // 'managedDeviceId' is NOT a selectable field on deviceManagementScriptDeviceState.
        // The device GUID is always the second segment of the composite id:
        //   format:  "{scriptId}:{managedDeviceId}"
        $parts           = explode(':', $state['id'] ?? '');
        $managedDeviceId = $parts[1] ?? '';

        if (empty($managedDeviceId)) {
            $counters['skipped']++;
            return;
        }

        // ── Find Azure device ─────────────────────────────────────────
        $azureDevice = AzureDevice::where('azure_device_id', $managedDeviceId)->first();
        if (! $azureDevice) {
            $counters['skipped']++;
            return;
        }

        // ── Skip recently synced devices (unless --force) ─────────────
        if (! $force && $azureDevice->net_data_synced_at && $azureDevice->net_data_synced_at->gt(now()->subHours(12))) {
            $counters['skipped']++;
            return;
        }

        // ── Strip UTF-8 BOM and parse JSON resultMessage ──────────────
        $raw  = $state['resultMessage'] ?? '';
        $json = trim(preg_replace('/^\xEF\xBB\xBF/', '', $raw));
        $data = json_decode($json, true);

        if (! is_array($data)) {
            $this->warn("  ⚠ Could not parse resultMessage for device {$managedDeviceId} — skipping.");
            $counters['failed']++;
            return;
        }

        // ── Update azure_devices row ──────────────────────────────────
        try {
            $azureDevice->update([
                'teamviewer_id'      => $data['teamviewer_id']   ?? null,
                'tv_version'         => $data['tv_version']      ?? null,
                'cpu_name'           => $data['cpu']             ?? null,
                'wifi_mac'           => $this->normalizeMac($data['wifi_mac']      ?? null),
                'ethernet_mac'       => $this->normalizeMac($data['ethernet_mac']  ?? null),
                'usb_eth_data'       => ! empty($data['usb_eth'])
                                          ? json_encode($data['usb_eth'])
                                          : null,
                'net_data_synced_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $this->warn("  ⚠ DB update failed for {$managedDeviceId}: " . $e->getMessage());
            $counters['failed']++;
            return;
        }

        // ── Sync MAC addresses into device_macs ───────────────────────
        $this->syncMacs($azureDevice, $data);

        $counters['updated']++;
        $this->line("  ✓ {$azureDevice->display_name} ({$managedDeviceId})");
    }

    // ─────────────────────────────────────────────────────────────────
    // MAC address registry sync
    // ─────────────────────────────────────────────────────────────────

    /**
     * Upsert all MACs from the script result into device_macs.
     * Existing rows are identified by mac_address (unique key).
     */
    private function syncMacs(AzureDevice $azureDevice, array $data): void
    {
        $baseAttrs = [
            'azure_device_id' => $azureDevice->id,
            'device_id'       => null,
            'source'          => 'intune',
            'is_active'       => true,
        ];

        // Primary wired Ethernet
        if ($mac = $this->normalizeMac($data['ethernet_mac'] ?? null)) {
            DeviceMac::upsertMac($mac, array_merge($baseAttrs, [
                'adapter_type' => 'ethernet',
                'adapter_name' => 'Ethernet',
                'is_primary'   => true,
            ]));
        }

        // Built-in Wi-Fi
        if ($mac = $this->normalizeMac($data['wifi_mac'] ?? null)) {
            DeviceMac::upsertMac($mac, array_merge($baseAttrs, [
                'adapter_type' => 'wifi',
                'adapter_name' => 'Wi-Fi',
                'is_primary'   => false,
            ]));
        }

        // USB / dock Ethernet adapters
        foreach ($data['usb_eth'] ?? [] as $usbAdapter) {
            if ($mac = $this->normalizeMac($usbAdapter['mac'] ?? null)) {
                DeviceMac::upsertMac($mac, array_merge($baseAttrs, [
                    'adapter_type'        => 'usb_ethernet',
                    'adapter_name'        => $usbAdapter['name']        ?? 'USB Ethernet',
                    'adapter_description' => $usbAdapter['desc']        ?? null,
                    'is_primary'          => false,
                ]));
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────

    /**
     * Normalise a MAC address to uppercase colon-separated format (AA:BB:CC:DD:EE:FF).
     * Accepts hyphens, colons, or raw hex.  Returns null for invalid input.
     */
    private function normalizeMac(?string $mac): ?string
    {
        return DeviceMac::normalizeMac($mac);
    }
}
