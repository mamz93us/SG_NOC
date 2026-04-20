<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\Credential;
use App\Models\Device;
use App\Models\DeviceMac;
use App\Models\NetworkSwitch;
use App\Models\SwitchCdpNeighbor;
use App\Models\SwitchInterfaceStat;
use App\Models\SwitchQosStat;
use App\Models\VqAlertEvent;
use App\Services\CiscoInterfaceParser;
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
        $rawQos = $rawCounters = $rawErrors = $rawCdp = '';

        try {
            $client->connect($ip, 23, 10.0);

            // 1. Login
            $client->waitFor(['Password:'], 10.0);
            $client->send((string) $telnet->password);
            $client->waitForPrompt(15.0);

            // 2. Privileged mode
            if ($enable) {
                $client->send('enable');
                $client->waitFor(['Password:'], 10.0);
                $client->send((string) $enable->password);
                $client->waitForPrompt(15.0);
            }

            // 3. Disable pagination
            $client->send('terminal length 0');
            $client->waitForPrompt(10.0);

            // 4. Collect all data in one session
            $client->send('show mls qos interface statistics');
            $rawQos = $client->waitForPrompt(60.0);

            $client->send('show interfaces counters');
            $rawCounters = $client->waitForPrompt(30.0);

            $client->send('show interfaces counters errors');
            $rawErrors = $client->waitForPrompt(30.0);

            $client->send('show cdp neighbors detail');
            $rawCdp = $client->waitForPrompt(30.0);

            try { $client->send('exit'); } catch (\Throwable) {}
        } finally {
            $client->close();
        }

        $qosParser = new MlsQosParser();
        $ifParser  = new CiscoInterfaceParser();

        $qosRows    = $qosParser->parse($rawQos);
        $counters   = $ifParser->parseInterfaceCounters($rawCounters);
        $errors     = $ifParser->parseInterfaceErrors($rawErrors);
        $cdpRows    = $ifParser->parseCdpNeighborsDetail($rawCdp);

        $this->line("    qos-ifaces=" . count($qosRows)
            . " counters=" . count($counters)
            . " errors=" . count($errors)
            . " cdp=" . count($cdpRows));

        if (empty($qosRows) && empty($counters)) {
            $this->warn("  x {$device->name} ({$ip}): nothing parsed");
            return;
        }

        if ($dryRun) {
            $this->info("  v {$device->name} ({$ip}): dry-run OK (not saved)");
            return;
        }

        $now = now();

        // Index QoS rows by interface name for quick drop% lookup
        $qosByIface = collect($qosRows)->keyBy('interface_name');

        // Save QoS rows + alerting (existing behavior)
        foreach ($qosRows as $r) {
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

        // Save interface counter+error rows. Union of iface names seen in counters & errors.
        $ifaceNames = array_unique(array_merge(array_keys($counters), array_keys($errors)));
        foreach ($ifaceNames as $name) {
            $c = $counters[$name] ?? [];
            $e = $errors[$name] ?? [];

            $outUcast = (int)($c['out_ucast_pkts'] ?? 0);
            $outMcast = (int)($c['out_mcast_pkts'] ?? 0);
            $outBcast = (int)($c['out_bcast_pkts'] ?? 0);
            $inUcast  = (int)($c['in_ucast_pkts'] ?? 0);
            $inMcast  = (int)($c['in_mcast_pkts'] ?? 0);
            $inBcast  = (int)($c['in_bcast_pkts'] ?? 0);
            $totalOut = $outUcast + $outMcast + $outBcast;
            $totalIn  = $inUcast + $inMcast + $inBcast;

            // Drop% uses QoS total_drops (cumulative) vs total packets transmitted (cumulative).
            $qos = $qosByIface->get($name);
            $dropPct = null;
            if ($qos && $totalOut > 0) {
                $drops = (int) ($qos['total_drops'] ?? 0);
                $dropPct = round($drops * 100.0 / ($totalOut + $drops), 4);
            }

            SwitchInterfaceStat::create([
                'device_id'       => $device->id,
                'device_name'     => $device->name,
                'device_ip'       => $ip,
                'branch_id'       => $device->branch_id,
                'interface_name'  => $name,

                'in_octets'       => (int)($c['in_octets'] ?? 0),
                'in_ucast_pkts'   => $inUcast,
                'in_mcast_pkts'   => $inMcast,
                'in_bcast_pkts'   => $inBcast,
                'out_octets'      => (int)($c['out_octets'] ?? 0),
                'out_ucast_pkts'  => $outUcast,
                'out_mcast_pkts'  => $outMcast,
                'out_bcast_pkts'  => $outBcast,

                'align_err'       => (int)($e['align_err'] ?? 0),
                'fcs_err'         => (int)($e['fcs_err'] ?? 0),
                'xmit_err'        => (int)($e['xmit_err'] ?? 0),
                'rcv_err'         => (int)($e['rcv_err'] ?? 0),
                'undersize'       => (int)($e['undersize'] ?? 0),
                'out_discards'    => (int)($e['out_discards'] ?? 0),
                'single_col'      => (int)($e['single_col'] ?? 0),
                'multi_col'       => (int)($e['multi_col'] ?? 0),
                'late_col'        => (int)($e['late_col'] ?? 0),
                'excess_col'      => (int)($e['excess_col'] ?? 0),
                'carri_sen'       => (int)($e['carri_sen'] ?? 0),
                'runts'           => (int)($e['runts'] ?? 0),
                'giants'          => (int)($e['giants'] ?? 0),

                'total_out_pkts'  => $totalOut,
                'total_in_pkts'   => $totalIn,
                'drop_percentage' => $dropPct,
                'polled_at'       => $now,
            ]);
        }

        // Replace CDP snapshot for this device (latest only — old rows kept for history)
        foreach ($cdpRows as $n) {
            // Try to derive a MAC from the CDP device-id. Cisco shows Meraki neighbors
            // as raw hex like "5c0610617cda"; hostnames return null from normalizeMac().
            $neighborMac = DeviceMac::normalizeMac($n['neighbor_device_id'] ?? null);

            // Resolve Meraki match by MAC (stored on NetworkSwitch.mac, any format).
            $merakiSerial = null;
            if ($neighborMac) {
                $hex = str_replace(':', '', $neighborMac);
                $merakiSerial = NetworkSwitch::whereRaw(
                    "UPPER(REPLACE(REPLACE(mac, ':', ''), '-', '')) = ?",
                    [$hex]
                )->value('serial');
            }

            // Resolve internal Device match by IP first, then MAC.
            $matchedDeviceId = null;
            if (!empty($n['neighbor_ip'])) {
                $matchedDeviceId = Device::where('ip_address', $n['neighbor_ip'])->value('id');
            }
            if (!$matchedDeviceId && $neighborMac) {
                $hex = str_replace(':', '', $neighborMac);
                $matchedDeviceId = Device::whereRaw(
                    "UPPER(REPLACE(REPLACE(mac_address, ':', ''), '-', '')) = ?",
                    [$hex]
                )->value('id');
            }

            SwitchCdpNeighbor::create([
                'device_id'             => $device->id,
                'device_name'           => $device->name,
                'device_ip'             => $ip,
                'local_interface'       => $n['local_interface'],
                'neighbor_device_id'    => $n['neighbor_device_id'],
                'neighbor_ip'           => $n['neighbor_ip'],
                'neighbor_mac'          => $neighborMac,
                'neighbor_port'         => $n['neighbor_port'],
                'platform'              => $n['platform'],
                'capabilities'          => $n['capabilities'],
                'version'               => $n['version'],
                'holdtime'              => $n['holdtime'],
                'matched_meraki_serial' => $merakiSerial,
                'matched_device_id'     => $matchedDeviceId,
                'polled_at'             => $now,
            ]);
        }

        $this->info("  v {$device->name} ({$ip}): qos=" . count($qosRows)
            . " ifaces=" . count($ifaceNames) . " cdp=" . count($cdpRows));
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
