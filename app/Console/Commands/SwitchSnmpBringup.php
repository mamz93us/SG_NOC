<?php

namespace App\Console\Commands;

use App\Jobs\CollectSnmpMetricsJob;
use App\Jobs\DiscoverSnmpDeviceJob;
use App\Jobs\DiscoverSnmpInterfacesJob;
use App\Models\MonitoredHost;
use Illuminate\Console\Command;

/**
 * SNMP bring-up for the imported switches: for each non-Meraki switch host,
 * run device discovery + interface discovery (creates the sensors), then poll
 * once. Same jobs the SNMP Monitoring UI's Discover/Poll buttons use, just
 * batched. Run after switches:snmp-config has set the community.
 *
 *   php artisan switches:snmp-bringup
 *   php artisan switches:snmp-bringup --ip=10.1.0.100
 */
class SwitchSnmpBringup extends Command
{
    protected $signature = 'switches:snmp-bringup {--ip= : Limit to a single host IP}';

    protected $description = 'Discover + poll SNMP for the imported (non-Meraki) switch hosts';

    public function handle(): int
    {
        $hosts = MonitoredHost::where('type', 'switch')
            ->where('snmp_enabled', true)
            ->whereHas('device', fn ($q) => $q->where('source', '!=', 'meraki')->orWhereNull('source'))
            ->when($this->option('ip'), fn ($q, $ip) => $q->where('ip', $ip))
            ->orderBy('ip')
            ->get();

        if ($hosts->isEmpty()) {
            $this->warn('No SNMP-enabled switch hosts found. Run switches:import / switches:snmp-config first.');

            return self::SUCCESS;
        }

        $this->info("Discovering + polling {$hosts->count()} switch host(s) over SNMP… (synchronous, be patient)");

        $results = [];
        foreach ($hosts as $host) {
            $note = 'ok';
            try {
                dispatch_sync(new DiscoverSnmpDeviceJob($host));
                dispatch_sync(new DiscoverSnmpInterfacesJob($host));
                CollectSnmpMetricsJob::dispatchSync($host);
            } catch (\Throwable $e) {
                $note = $this->short($e->getMessage());
            }

            $host->refresh();
            $results[] = [
                $host->ip,
                $host->name,
                strtoupper((string) ($host->status ?: 'unknown')),
                $host->snmpSensors()->count(),
                $host->last_snmp_at?->diffForHumans() ?? 'never',
                $note,
            ];
        }

        $this->table(['IP', 'Name', 'Status', 'Sensors', 'Last SNMP', 'Note'], $results);

        $up = collect($results)->where(2, 'UP')->count();
        $this->info("Done. {$up}/".count($results).' host(s) reporting UP. Metrics now collect every 5 min via the scheduler.');

        return self::SUCCESS;
    }

    private function short(?string $s): string
    {
        $s = trim((string) $s);

        return strlen($s) > 60 ? substr($s, 0, 57).'…' : $s;
    }
}
