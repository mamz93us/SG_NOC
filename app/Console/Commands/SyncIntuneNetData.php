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
        {--diagnose   : Print first successful resultMessage and first 5 composite IDs, then exit}
        {--reset      : Clear all Intune-written net/hw data from azure_devices and device_macs, then exit}';

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
        $reset    = $this->option('reset');
        $graph    = new GraphService();

        // ── RESET MODE ────────────────────────────────────────────────
        if ($reset) {
            return $this->runReset();
        }

        // ── DIAGNOSE MODE ─────────────────────────────────────────────
        // Dumps raw Graph data so you can verify ID format & resultMessage
        if ($diagnose) {
            return $this->runDiagnose($graph, $scriptId);
        }

        // ── NORMAL SYNC ───────────────────────────────────────────────
        // Strategy:
        //   1. Paginate deviceRunStates once to build a managedDeviceId→state map.
        //      This avoids per-device API calls — the Intune proxy ignores $filter
        //      on this endpoint and returns the same first record for every query.
        //   2. Iterate local AzureDevice rows and look each one up in the map.
        $updated     = 0;
        $skipped     = 0;
        $failed      = 0;
        $stateCounts = [];

        // ── Step 1: Fetch all run states into a lookup map ────────────
        // Primary:  Intune Reports Export API  — returns ALL devices, no limit.
        // Fallback: deviceRunStates pagination  — broken Intune proxy caps at ~50.
        $this->info("[intune:sync-net-data] Building run-state map from Intune...");
        $runStateMap = []; // managedDeviceId (Intune GUID) => run-state array
        $exportUsed  = false;

        try {
            $runStateMap = $graph->exportScriptRunStates(
                $scriptId,
                fn (string $msg) => $this->line($msg)
            );
            $exportUsed = true;
        } catch (\RuntimeException $e) {
            $this->warn("  ⚠ Export API failed: " . $e->getMessage());
            $this->warn("  → Falling back to pagination (limited to ~50 devices)...");

            $graph->listScriptRunStates($scriptId, function (array $states) use (&$runStateMap) {
                foreach ($states as $state) {
                    $parts           = explode(':', $state['id'] ?? '', 2);
                    $managedDeviceId = $parts[1] ?? null;
                    if ($managedDeviceId) {
                        $runStateMap[$managedDeviceId] = $state;
                    }
                }
            });
        }

        $found  = count($runStateMap);
        $source = $exportUsed ? 'Export API' : 'pagination fallback';
        $this->info("  → Collected {$found} unique run states via {$source}");

        // ── Step 1b: Fetch resultMessage for success devices ──────────
        // The export CSV omits script output. For devices with runState=success
        // we fetch resultMessage individually via managedDevices/{id}/deviceManagementScriptStates
        // which uses the device GUID in the URL path and correctly returns per-device data.
        $successIds   = array_keys(array_filter($runStateMap, fn($s) => $s['runState'] === 'success'));
        $successCount = count($successIds);
        $this->info("  → Fetching resultMessage for {$successCount} success devices...");
        $fetched = 0;

        foreach ($successIds as $i => $managedDeviceId) {
            // 300 ms pause every 10 calls ≈ 33 req/s — well within Graph rate limits
            if ($i > 0 && $i % 10 === 0) {
                usleep(300 * 1000);
            }
            if ($verbose && $i > 0 && $i % 50 === 0) {
                $this->line("  → Progress: {$i}/{$successCount} queried, {$fetched} with results...");
            }

            $result = $graph->getDeviceScriptResult($managedDeviceId, $scriptId);
            if ($result !== null && ! empty($result['resultMessage'])) {
                $runStateMap[$managedDeviceId]['resultMessage'] = $result['resultMessage'];
                $fetched++;
            }
        }

        $this->info("  → Got resultMessage for {$fetched}/{$successCount} success devices");

        // ── Step 2: Match against local AzureDevice records ──────────
        $devices = AzureDevice::whereNotNull('intune_managed_device_id')
            ->select('id', 'display_name', 'intune_managed_device_id', 'device_id',
                     'teamviewer_id', 'tv_version', 'cpu_name',
                     'wifi_mac', 'ethernet_mac', 'usb_eth_data')
            ->get();

        $total = $devices->count();
        $this->info("[intune:sync-net-data] Matching {$found} Intune states against {$total} local devices...");

        $normMac = fn(?string $m) => $m
            ? strtoupper(implode(':', str_split(preg_replace('/[^a-fA-F0-9]/', '', $m), 2)))
            : null;

        foreach ($devices as $device) {
            $state = $runStateMap[$device->intune_managed_device_id] ?? null;

            if ($state === null) {
                $skipped++;
                if ($verbose) {
                    $this->line("  <fg=yellow>~</> {$device->display_name} — no run state");
                }
                continue;
            }

            $runState  = $state['runState']  ?? 'unknown';
            $errorCode = $state['errorCode'] ?? null;
            $stateCounts[$runState] = ($stateCounts[$runState] ?? 0) + 1;

            if ($runState !== 'success') {
                $failed++;
                if ($verbose) {
                    $this->line("  <fg=red>✗</> [{$runState}] {$device->display_name}" . ($errorCode ? " errorCode={$errorCode}" : ''));
                }
                continue;
            }

            $raw  = trim($state['resultMessage'] ?? '');
            $data = json_decode($raw, true);

            if (! is_array($data)) {
                if ($verbose) {
                    $this->warn("  ⚠ Non-JSON result for {$device->display_name}: [{$raw}]");
                }
                $skipped++;
                continue;
            }

            $usbEthJson = null;
            $usbEthRaw  = $data['usb_eth'] ?? $data['usb_eth_adapters'] ?? null;
            if (! empty($usbEthRaw) && is_array($usbEthRaw)) {
                $usbEthJson = json_encode($usbEthRaw);
            }

            $cpuName     = $data['cpu']     ?? $data['cpu_name']     ?? null;
            $wifiMac     = $normMac($data['wifi_mac']     ?? null);
            $ethernetMac = $normMac($data['ethernet_mac'] ?? null);

            $device->update([
                'teamviewer_id'      => $data['teamviewer_id'] ?? $device->teamviewer_id,
                'tv_version'         => $data['tv_version']    ?? $device->tv_version,
                'cpu_name'           => $cpuName               ?? $device->cpu_name,
                'wifi_mac'           => $wifiMac               ?? $device->wifi_mac,
                'ethernet_mac'       => $ethernetMac           ?? $device->ethernet_mac,
                'usb_eth_data'       => $usbEthJson            ?? $device->usb_eth_data,
                'net_data_synced_at' => now(),
            ]);

            if ($device->device_id && ($ethernetMac || $wifiMac)) {
                $itamDevice = \App\Models\Device::find($device->device_id);
                if ($itamDevice) {
                    $itamUpdate = [];
                    if ($ethernetMac) $itamUpdate['mac_address'] = $ethernetMac;
                    if ($wifiMac)     $itamUpdate['wifi_mac']    = $wifiMac;
                    $itamDevice->update($itamUpdate);
                }
            }

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
    // Reset: wipe all Intune-written hw/net data so a clean re-sync
    //        can write correct values.
    // ─────────────────────────────────────────────────────────────────

    private function runReset(): int
    {
        $this->warn('── RESET MODE ──────────────────────────────────────────');
        $this->line('  This will:');
        $this->line('    1. NULL out teamviewer_id, tv_version, cpu_name,');
        $this->line('       wifi_mac, ethernet_mac, usb_eth_data, net_data_synced_at');
        $this->line('       on ALL rows in azure_devices.');
        $this->line('    2. DELETE all rows in device_macs where source = "intune".');
        $this->newLine();

        if (! $this->confirm('Proceed with reset?', false)) {
            $this->info('Aborted.');
            return self::SUCCESS;
        }

        // 1. Clear Intune net/hw columns from azure_devices
        $azureCount = AzureDevice::query()->update([
            'teamviewer_id'      => null,
            'tv_version'         => null,
            'cpu_name'           => null,
            'wifi_mac'           => null,
            'ethernet_mac'       => null,
            'usb_eth_data'       => null,
            'net_data_synced_at' => null,
        ]);

        $this->info("  ✅ azure_devices — cleared {$azureCount} rows");

        // 2. Delete device_macs rows written by Intune
        $macCount = \App\Models\DeviceMac::where('source', 'intune')->delete();

        $this->info("  ✅ device_macs    — deleted {$macCount} rows (source=intune)");

        $this->newLine();
        $this->info('Reset complete. Run the sync again to populate fresh data:');
        $this->line('  php artisan intune:sync-net-data --verbose');
        $this->warn('── END RESET ────────────────────────────────────────────');

        Log::info("[intune:sync-net-data] RESET — cleared {$azureCount} azure_devices rows and {$macCount} device_macs rows");

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

        // 5. Test individual device run-state lookup for first successful device
        if ($firstSuccess) {
            $compositeId = $firstSuccess['id'] ?? '';
            $parts       = explode(':', $compositeId);
            $deviceId    = $parts[1] ?? null;

            if ($deviceId) {
                $this->info('── Individual getScriptRunState test:');
                $this->line("  Calling: /deviceRunStates/{$scriptId}:{$deviceId}");
                try {
                    $single = $graph->getScriptRunState($scriptId, $deviceId);
                    if ($single) {
                        $this->info("  ✅ Success — runState: " . ($single['runState'] ?? '?'));
                        $this->line("  resultMessage length: " . strlen($single['resultMessage'] ?? ''));
                    } else {
                        $this->warn("  ⚠ Returned null (exception was thrown and caught)");
                    }
                } catch (\RuntimeException $e) {
                    $this->error("  ❌ Error: " . $e->getMessage());
                    if (str_contains($e->getMessage(), '403')) {
                        $this->line('  → Missing permission: DeviceManagementConfiguration.Read.All');
                        $this->line('  → Add this Application permission in Azure portal → App Registrations → API Permissions');
                    }
                }
            }
        }

        $this->warn('── END DIAGNOSE ────────────────────────────────────────');

        return self::SUCCESS;
    }
}
