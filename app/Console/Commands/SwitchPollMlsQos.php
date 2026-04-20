<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\Credential;
use App\Models\Device;
use App\Models\SwitchQosStat;
use App\Models\VqAlertEvent;
use App\Services\CiscoTelnetClient;
use App\Services\MlsQosParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SwitchPollMlsQos extends Command
{
    protected $signature   = 'switch:poll-mls-qos {--branch=all} {--device=} {--dry-run}';
    protected $description = 'Poll Cisco `show mls qos interface statistics` over Telnet and store per-interface queue counters';

    /** Raise a warning alert when queue-drop counter grows by this much between polls. */
    private int $deltaWarnThreshold = 100;

    /** Raise a critical alert when delta exceeds this. */
    private int $deltaCriticalThreshold = 500;

    public function handle(): int
    {
        $branchFilter = $this->option('branch');
        $deviceOpt    = $this->option('device');
        $dryRun       = (bool) $this->option('dry-run');

        $query = Device::whereIn('type', ['switch', 'router'])
            ->whereNotNull('ip_address');

        if ($deviceOpt !== null && $deviceOpt !== '') {
            if (ctype_digit((string) $deviceOpt)) {
                $query->where('id', (int) $deviceOpt);
            } else {
                $query->where('ip_address', $deviceOpt);
            }
        } elseif ($branchFilter !== 'all') {
            $branch = Branch::where('name', $branchFilter)->first();
            if ($branch) {
                $query->where('branch_id', $branch->id);
            }
        }

        $devices = $query->get();
        $this->info("QoS poll: {$devices->count()} candidate device(s).");

        $polled = 0;
        $skipped = 0;

        foreach ($devices as $device) {
            $telnet = $device->credentials()->where('category', 'telnet')->first();
            if (!$telnet) {
                $skipped++;
                $this->line("  - {$device->name} ({$device->ip_address}): no 'telnet' credential, skipping");
                continue;
            }
            $enable = $device->credentials()->where('category', 'enable')->first();

            try {
                $this->pollDevice($device, $telnet, $enable, $dryRun);
                $polled++;
            } catch (\Throwable $e) {
                Log::error("SwitchPollMlsQos: {$device->ip_address} — " . $e->getMessage());
                $this->warn("  x {$device->name} ({$device->ip_address}): " . $e->getMessage());
            }
        }

        $this->info("Done. polled={$polled}, skipped={$skipped}.");
        return 0;
    }

    private function pollDevice(Device $device, Credential $telnet, ?Credential $enable, bool $dryRun): void
    {
        $ip = $device->ip_address;
        $client = new CiscoTelnetClient();
        $raw = '';

        try {
            $client->connect($ip, 23, 10.0);

            // 1. Login password
            $client->waitFor(['Password:'], 10.0);
            $client->send((string) $telnet->password);

            // 2. User-mode prompt
            $client->waitForPrompt(15.0);

            // 3. Enter privileged mode if we have the enable secret
            if ($enable) {
                $client->send('enable');
                $client->waitFor(['Password:'], 10.0);
                $client->send((string) $enable->password);
                $client->waitForPrompt(15.0);
            }

            // 4. Disable pagination
            $client->send('terminal length 0');
            $client->waitForPrompt(10.0);

            // 5. Collect stats
            $client->send('show mls qos interface statistics');
            $raw = $client->waitForPrompt(60.0);

            // 6. Polite exit
            try {
                $client->send('exit');
            } catch (\Throwable) {
                // ignore — connection may drop immediately on exit
            }
        } finally {
            $client->close();
        }

        $parser = new MlsQosParser();
        $rows = $parser->parse($raw);

        if (empty($rows)) {
            $this->warn("  x {$device->name} ({$ip}): parser returned 0 interfaces");
            return;
        }

        if ($dryRun) {
            $this->info("  v {$device->name} ({$ip}): parsed " . count($rows) . " interfaces (dry-run, not saved)");
            return;
        }

        $now = now();
        foreach ($rows as $r) {
            $previous = SwitchQosStat::where('device_ip', $ip)
                ->where('interface_name', $r['interface_name'])
                ->latest('polled_at')
                ->first();

            $stat = SwitchQosStat::create(array_merge($r, [
                'device_id'   => $device->id,
                'device_name' => $device->name,
                'device_ip'   => $ip,
                'branch_id'   => $device->branch_id,
                'polled_at'   => $now,
            ]));

            $this->maybeAlert($device, $stat, $previous);
        }

        $this->info("  v {$device->name} ({$ip}): saved " . count($rows) . " interface rows");
    }

    private function maybeAlert(Device $device, SwitchQosStat $current, ?SwitchQosStat $previous): void
    {
        // First poll for this interface — no delta yet.
        if (!$previous) {
            return;
        }

        $delta = (int) $current->total_drops - (int) $previous->total_drops;

        // Negative delta = counter reset (switch reboot or `clear` command). Ignore.
        if ($delta <= 0) {
            return;
        }

        if ($delta < $this->deltaWarnThreshold) {
            return;
        }

        $severity = $delta >= $this->deltaCriticalThreshold ? 'critical' : 'warning';

        VqAlertEvent::create([
            'source_type' => 'switch-qos',
            'source_ref'  => "{$current->device_ip}/{$current->interface_name}",
            'branch'      => $device->branch?->name,
            'metric'      => 'qos_drop_delta',
            'value'       => $delta,
            'threshold'   => $this->deltaWarnThreshold,
            'severity'    => $severity,
            'message'     => "QoS queue drops on {$current->interface_name} ({$current->device_name}) grew by {$delta} since last poll.",
        ]);
    }
}
