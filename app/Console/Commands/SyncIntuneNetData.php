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
        $updated  = 0;
        $skipped  = 0;
        $failed   = 0;
        $stateCounts = [];

        $this->info("[intune:sync-net-data] Fetching run states for script: {$scriptId}");

        $graph->listScriptRunStates(
            $scriptId,
            function (array $states) use (&$updated, &$skipped, &$failed, &$stateCounts, $verbose) {
                foreach ($states as $state) {

                    $runState    = $state['runState'] ?? 'unknown';
                    $errorCode   = $state['errorCode'] ?? null;
                    $compositeId = $state['id'] ?? '';
                    $stateCounts[$runState] = ($stateCounts[$runState] ?? 0) + 1;

                    // ── 1. Skip non-successful runs ────────────────────
                    if ($runState !== 'success') {
                        $failed++;
                        if ($verbose) {
                            $this->line("  <fg=red>✗</> [{$runState}] id={$compositeId}" . ($errorCode ? " errorCode={$errorCode}" : ''));
                        }
                        continue;
                    }

                    // ── 2. Extract Intune managedDeviceId from composite key ──
                    // Graph returns id as "{scriptId}:{managedDeviceId}"
                    $parts           = explode(':', $compositeId);
                    $managedDeviceId = $parts[1] ?? null;

                    if (! $managedDeviceId) {
                        $this->warn("  ⚠ Could not parse managedDeviceId from id: [{$compositeId}]");
                        $skipped++;
                        continue;
                    }

                    // ── 3. Parse the script stdout (JSON) ─────────────
                    $raw  = trim($state['resultMessage'] ?? '');
                    $data = json_decode($raw, true);

                    if (! is_array($data)) {
                        if ($verbose) {
                            $this->warn("  ⚠ Non-JSON result for {$managedDeviceId}: [{$raw}]");
                        }
                        $skipped++;
                        continue;
                    }

                    // ── 4. Match to local AzureDevice record ───────────
                    $device = AzureDevice::where('azure_device_id', $managedDeviceId)->first();

                    if (! $device) {
                        if ($verbose) {
                            $this->line("  <fg=yellow>~</> No local record for managedDeviceId={$managedDeviceId}");
                        }
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

                    if ($verbose) {
                        $this->line("  <fg=green>✓</> Updated {$device->display_name} ({$managedDeviceId})");
                    }

                    $updated++;
                }
            }
        );

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

                // 4. Check if device exists locally
                $device = AzureDevice::where('azure_device_id', $deviceId)->first();
                if ($device) {
                    $this->info("── Local DB match: ✅ Found — {$device->display_name} (id={$device->id})");
                } else {
                    $this->warn("── Local DB match: ❌ No row in azure_devices with azure_device_id={$deviceId}");
                    $this->line('   → Run php artisan identity:sync first to populate azure_devices.');

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
