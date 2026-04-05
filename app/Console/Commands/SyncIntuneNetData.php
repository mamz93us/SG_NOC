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
 *   php artisan intune:sync-net-data --script-id=<GUID> --verbose
 *   php artisan intune:sync-net-data --script-id=<GUID> --diagnose
 */
class SyncIntuneNetData extends Command
{
    protected $signature = 'intune:sync-net-data
        {--script-id= : Override the Intune deviceManagementScript GUID stored in settings}
        {--force      : Re-sync all devices, even those already synced recently}
        {--diagnose   : Print first successful resultMessage and first 5 composite IDs, then exit}';

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

        $verbose  = $this->option('verbose');
        $diagnose = $this->option('diagnose');
        $graph    = new GraphService();

        // ── DIAGNOSE MODE ─────────────────────────────────────────────
        // Dumps raw Graph data so you can verify ID format & resultMessage
        if ($diagnose) {
            return $this->runDiagnose($graph, $scriptId);
        }

        // ── NORMAL SYNC ───────────────────────────────────────────────
        // The Graph deviceRunStates list endpoint has a broken pagination
        // token that always circles back to the same 100 devices.
        // Workaround: iterate over every AzureDevice that has an Intune
        // managed-device ID and fetch each run state individually.
        // This bypasses the list endpoint entirely and is 100% reliable.

        $updated     = 0;
        $skipped     = 0;
        $failed      = 0;
        $stateCounts = [];

        $devices = AzureDevice::whereNotNull('intune_managed_device_id')
            ->select('id', 'display_name', 'intune_managed_device_id', 'device_id',
                     'teamviewer_id', 'tv_version', 'cpu_name',
                     'wifi_mac', 'ethernet_mac', 'usb_eth_data')
            ->get();

        $total = $devices->count();
        $this->info("[intune:sync-net-data] Querying {$total} devices for script: {$scriptId}");

        $normMac = fn(?string $m) => $m
            ? strtoupper(implode(':', str_split(preg_replace('/[^a-fA-F0-9]/', '', $m), 2)))
            : null;

        foreach ($devices as $i => $device) {
            // Courtesy delay after every 10 requests to stay under Intune rate limits
            if ($i > 0 && $i % 10 === 0) {
                usleep(1000 * 1000); // 1 s every 10 devices
            }

            $managedDeviceId = $device->intune_managed_device_id;

            // ── 1. Fetch this device's run state ──────────────────
            $state = $graph->getScriptRunState($scriptId, $managedDeviceId);

            if ($state === null) {
                // 404 — script has never run on this device
                $skipped++;
                if ($verbose) {
                    $this->line("  <fg=yellow>~</> {$device->display_name} — no run state");
                }
                continue;
            }

            $runState  = $state['runState']  ?? 'unknown';
            $errorCode = $state['errorCode'] ?? null;
            $stateCounts[$runState] = ($stateCounts[$runState] ?? 0) + 1;

            // ── 2. Skip non-successful runs ───────────────────────
            if ($runState !== 'success') {
                $failed++;
                if ($verbose) {
                    $this->line("  <fg=red>✗</> [{$runState}] {$device->display_name}" . ($errorCode ? " errorCode={$errorCode}" : ''));
                }
                continue;
            }

            // ── 3. Parse the script stdout (JSON) ─────────────────
            $raw  = trim($state['resultMessage'] ?? '');
            $data = json_decode($raw, true);

            if (! is_array($data)) {
                if ($verbose) {
                    $this->warn("  ⚠ Non-JSON result for {$device->display_name}: [{$raw}]");
                }
                $skipped++;
                continue;
            }

            // ── 4. Map fields ─────────────────────────────────────
            $usbEthJson = null;
            $usbEthRaw  = $data['usb_eth'] ?? $data['usb_eth_adapters'] ?? null;
            if (! empty($usbEthRaw) && is_array($usbEthRaw)) {
                $usbEthJson = json_encode($usbEthRaw);
            }

            $cpuName     = $data['cpu']          ?? $data['cpu_name']     ?? null;
            $wifiMac     = $normMac($data['wifi_mac']     ?? null);
            $ethernetMac = $normMac($data['ethernet_mac'] ?? null);

            // ── 5. Update azure_device ────────────────────────────
            $device->update([
                'teamviewer_id'      => $data['teamviewer_id'] ?? $device->teamviewer_id,
                'tv_version'         => $data['tv_version']    ?? $device->tv_version,
                'cpu_name'           => $cpuName               ?? $device->cpu_name,
                'wifi_mac'           => $wifiMac               ?? $device->wifi_mac,
                'ethernet_mac'       => $ethernetMac           ?? $device->ethernet_mac,
                'usb_eth_data'       => $usbEthJson            ?? $device->usb_eth_data,
                'net_data_synced_at' => now(),
            ]);

            // ── 5b. Propagate MACs to the linked ITAM device ──────
            if ($device->device_id && ($ethernetMac || $wifiMac)) {
                $itamDevice = \App\Models\Device::find($device->device_id);
                if ($itamDevice) {
                    $itamUpdate = [];
                    if ($ethernetMac) $itamUpdate['mac_address'] = $ethernetMac;
                    if ($wifiMac)     $itamUpdate['wifi_mac']    = $wifiMac;
                    $itamDevice->update($itamUpdate);
                }
            }

            // ── 6. Sync MACs into device_macs registry ────────────
            if ($ethernetMac) {
                \App\Models\DeviceMac::upsertMac($ethernetMac, [
                    'adapter_type'    => 'ethernet',
                    'adapter_name'    => 'Ethernet',
                    'azure_device_id' => $device->id,
                    'device_id'       => $device->device_id,
                    'source'          => 'intune',
                    'is_primary'      => true,
                ]);
            }
            if ($wifiMac) {
                \App\Models\DeviceMac::upsertMac($wifiMac, [
                    'adapter_type'    => 'wifi',
                    'adapter_name'    => 'Wi-Fi',
                    'azure_device_id' => $device->id,
                    'device_id'       => $device->device_id,
                    'source'          => 'intune',
                    'is_primary'      => false,
                ]);
            }
            foreach (json_decode($usbEthJson ?? '[]', true) as $usb) {
                $usbMac = $normMac($usb['mac'] ?? null);
                if ($usbMac) {
                    \App\Models\DeviceMac::upsertMac($usbMac, [
                        'adapter_type'       => 'usb_ethernet',
                        'adapter_name'       => $usb['name'] ?? 'USB LAN',
                        'adapter_description'=> $usb['desc'] ?? null,
                        'azure_device_id'    => $device->id,
                        'device_id'          => $device->device_id,
                        'source'             => 'intune',
                        'is_primary'         => false,
                    ]);
                }
            }

            if ($verbose) {
                $tvStr  = $device->teamviewer_id ? " TV={$device->teamviewer_id}" : '';
                $cpuStr = $cpuName ? " CPU=" . substr($cpuName, 0, 30) : '';
                $this->line("  <fg=green>✓</> {$device->display_name}{$cpuStr}{$tvStr}");
            }

            $updated++;
        }

        // ── Summary ───────────────────────────────────────────────────
        $summary = "✅ Done — Updated: {$updated} | Skipped: {$skipped} | Failed/Pending: {$failed}";
        $this->info($summary);

        if (! empty($stateCounts)) {
            $this->table(
                ['runState', 'count'],
                collect($stateCounts)->map(fn($c, $s) => [$s, $c])->values()->toArray()
            );
        }

        Log::info("[intune:sync-net-data] script={$scriptId} {$summary}");

        return self::SUCCESS;
    }

    // ─────────────────────────────────────────────────────────────────
    // Diagnose: dump raw Graph data to help debug ID format & JSON
    // ─────────────────────────────────────────────────────────────────

    private function runDiagnose(GraphService $graph, string $scriptId): int
    {
        $this->warn('── DIAGNOSE MODE ──────────────────────────────────────');
        $this->info("Script ID: {$scriptId}");
        $this->newLine();

        $shown         = 0;
        $firstSuccess  = null;
        $stateCounts   = [];
        $sampleIds     = [];

        $graph->listScriptRunStates($scriptId, function (array $states) use (
            &$shown, &$firstSuccess, &$stateCounts, &$sampleIds
        ) {
            foreach ($states as $state) {
                $runState = $state['runState'] ?? 'unknown';
                $stateCounts[$runState] = ($stateCounts[$runState] ?? 0) + 1;

                if (count($sampleIds) < 5) {
                    $sampleIds[] = $state['id'] ?? '(no id)';
                }

                if ($runState === 'success' && $firstSuccess === null) {
                    $firstSuccess = $state;
                }
            }
        });

        // 1. runState distribution
        $this->info('── runState distribution:');
        $this->table(['runState', 'count'],
            collect($stateCounts)->map(fn($c, $s) => [$s, $c])->values()->toArray()
        );

        // 2. Sample composite IDs — check format
        $this->info('── First 5 composite IDs (id field):');
        foreach ($sampleIds as $id) {
            $parts = explode(':', $id);
            $extracted = $parts[1] ?? '(could not extract)';
            $this->line("  raw id  : {$id}");
            $this->line("  parts[1]: {$extracted}");
            $this->newLine();
        }

        // 3. First successful resultMessage — check JSON format
        if ($firstSuccess) {
            $compositeId = $firstSuccess['id'] ?? '';
            $parts       = explode(':', $compositeId);
            $deviceId    = $parts[1] ?? '(unknown)';
            $raw         = trim($firstSuccess['resultMessage'] ?? '');
            $json        = json_decode($raw, true);

            $this->info("── First successful run (managedDeviceId: {$deviceId}):");
            $this->line("  errorCode : " . ($firstSuccess['errorCode'] ?? 'null'));
            $this->line("  lastUpdate: " . ($firstSuccess['lastStateUpdateDateTime'] ?? 'null'));
            $this->newLine();

            $this->info('── resultMessage (raw):');
            $this->line('  ' . ($raw ?: '(empty)'));
            $this->newLine();

            if (is_array($json)) {
                $this->info('── resultMessage (parsed JSON keys):');
                foreach ($json as $k => $v) {
                    $display = is_array($v) ? json_encode($v) : (string) $v;
                    $this->line("  {$k}: {$display}");
                }
                $this->newLine();

                // 4. Check if device exists locally (try intune_managed_device_id first)
                $device = AzureDevice::where('intune_managed_device_id', $deviceId)->first()
                       ?? AzureDevice::where('azure_device_id', $deviceId)->first();
                if ($device) {
                    $matchedOn = $device->intune_managed_device_id === $deviceId ? 'intune_managed_device_id' : 'azure_device_id';
                    $this->info("── Local DB match: ✅ Found via {$matchedOn} — {$device->display_name} (id={$device->id})");
                } else {
                    $this->warn("── Local DB match: ❌ No row found for managedDeviceId={$deviceId}");
                    $this->line('   → Run: php artisan itam:sync-devices (to populate intune_managed_device_id)');

                    // Show a sample of what IS in the table
                    $sample = AzureDevice::select('id', 'azure_device_id', 'display_name')->limit(3)->get();
                    if ($sample->isNotEmpty()) {
                        $this->info('── Sample azure_devices rows (first 3):');
                        $this->table(
                            ['id', 'azure_device_id', 'display_name'],
                            $sample->map(fn($d) => [$d->id, $d->azure_device_id, $d->display_name])->toArray()
                        );
                    } else {
                        $this->warn('   → azure_devices table is empty! Run identity:sync first.');
                    }
                }
            } else {
                $this->error('resultMessage is NOT valid JSON. Fix your PowerShell script output.');
                $this->line('  Tip: make sure the last line of your .ps1 is:');
                $this->line('       Write-Output (ConvertTo-Json $result -Compress)');
            }
        } else {
            $this->warn('No successful run states found for this script.');
            $this->line('  → Devices may not have run the script yet, or all runs failed.');
        }

        $this->warn('── END DIAGNOSE ────────────────────────────────────────');

        return self::SUCCESS;
    }
}
