<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Models\Branch;
use App\Models\SwitchDropStat;
use App\Models\VqAlertEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SwitchPollDrops extends Command
{
    protected $signature   = 'switch:poll-drops {--branch=all}';
    protected $description = 'Poll SNMP interface drop/error counters from switches and routers';

    private int $dropThreshold = 100;

    public function handle(): int
    {
        $branchFilter = $this->option('branch');

        $query = Device::whereIn('type', ['switch', 'router'])
            ->whereNotNull('ip_address');

        if ($branchFilter !== 'all') {
            $branch = Branch::where('name', $branchFilter)->first();
            if ($branch) {
                $query->where('branch_id', $branch->id);
            }
        }

        $devices = $query->get();
        $this->info("Polling {$devices->count()} switch/router device(s)...");

        foreach ($devices as $device) {
            try {
                $this->pollDevice($device);
            } catch (\Throwable $e) {
                Log::error("SwitchPollDrops: device {$device->ip_address} — " . $e->getMessage());
                $this->warn("  x {$device->name} ({$device->ip_address}): " . $e->getMessage());
            }
        }

        $this->info("Done.");
        return 0;
    }

    private function pollDevice(Device $device): void
    {
        $ip        = $device->ip_address;
        $community = 'public';

        // Walk interface descriptions
        $descrOid   = '1.3.6.1.2.1.2.2.1.2';
        $ifDescrs   = @snmprealwalk($ip, $community, $descrOid)  ?: [];

        if (empty($ifDescrs)) {
            $this->warn("  x {$device->name} ({$ip}): no SNMP response");
            return;
        }

        $oids = [
            'in_discards'  => '1.3.6.1.2.1.2.2.1.13',
            'out_discards' => '1.3.6.1.2.1.2.2.1.19',
            'in_errors'    => '1.3.6.1.2.1.2.2.1.14',
            'out_errors'   => '1.3.6.1.2.1.2.2.1.20',
            'in_octets'    => '1.3.6.1.2.1.2.2.1.10',
            'out_octets'   => '1.3.6.1.2.1.2.2.1.16',
            'crc_errors'   => '1.3.6.1.2.1.10.7.2.1.2', // dot3StatsFCSErrors
        ];

        $walks = [];
        foreach ($oids as $key => $oid) {
            $walks[$key] = @snmprealwalk($ip, $community, $oid) ?: [];
        }

        $branchName = $device->branch?->name ?? '';
        $now        = now();

        foreach ($ifDescrs as $oid => $descrVal) {
            preg_match('/\.(\d+)$/', $oid, $m);
            if (empty($m[1])) continue;
            $idx = (int) $m[1];

            $ifName = $this->parseSnmpString($descrVal);
            if (empty($ifName)) continue;

            $row = ['interface_index' => $idx];
            foreach ($oids as $key => $baseOid) {
                $fullOid = $baseOid . '.' . $idx;
                $val = $walks[$key][$fullOid] ?? null;
                $row[$key] = $val !== null ? (int) $this->parseSnmpInt($val) : 0;
            }

            $stat = SwitchDropStat::create([
                'device_name'     => $device->name,
                'device_ip'       => $ip,
                'branch'          => $branchName,
                'branch_id'       => $device->branch_id,
                'interface_name'  => $ifName,
                'interface_index' => $idx,
                'in_discards'     => $row['in_discards'],
                'out_discards'    => $row['out_discards'],
                'in_errors'       => $row['in_errors'],
                'out_errors'      => $row['out_errors'],
                'in_octets'       => $row['in_octets'],
                'out_octets'      => $row['out_octets'],
                'crc_errors'      => $row['crc_errors'],
                'polled_at'       => $now,
            ]);

            $totalDrops = $stat->total_drops;
            if ($totalDrops >= $this->dropThreshold) {
                VqAlertEvent::create([
                    'source_type' => 'switch',
                    'source_ref'  => "{$ip}/{$ifName}",
                    'branch'      => $branchName,
                    'metric'      => 'total_drops',
                    'value'       => $totalDrops,
                    'threshold'   => $this->dropThreshold,
                    'severity'    => $totalDrops >= $this->dropThreshold * 5 ? 'critical' : 'warning',
                    'message'     => "Interface {$ifName} on {$device->name} ({$ip}) has {$totalDrops} drops/errors.",
                ]);
            }
        }

        $this->info("  v {$device->name} ({$ip}): polled " . count($ifDescrs) . " interfaces");
    }

    private function parseSnmpString(string $val): string
    {
        return trim(preg_replace('/^(STRING:\s*|\"|\')/', '', strip_tags($val)), ' "\'');
    }

    private function parseSnmpInt(string $val): int
    {
        preg_match('/\d+/', $val, $m);
        return (int) ($m[0] ?? 0);
    }
}
