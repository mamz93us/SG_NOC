<?php

namespace App\Console\Commands;

use App\Models\DhcpLease;
use App\Models\FortigateFirewall;
use App\Models\SophosFirewall;
use App\Services\DhcpLeaseService;
use App\Services\Network\WifiMacDirectory;
use Illuminate\Console\Command;

/**
 * Recompute network_label on leases already in the table.
 *
 * The label is normally stamped at sync time, but a firewall's label (or the
 * Wi-Fi MAC registry behind it) can change after the fact — this backfills
 * without waiting for the next firewall sync.
 */
class RelabelDhcpNetworks extends Command
{
    protected $signature = 'dhcp:relabel-networks
                            {--dry-run : Report what would change without writing}';

    protected $description = 'Recompute the network/SSID label on existing DHCP leases';

    public function handle(DhcpLeaseService $service, WifiMacDirectory $wifiMacs): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->info("Known Wi-Fi MACs: {$wifiMacs->count()}");

        // Firewalls keyed by the IP that leases store in source_device.
        $firewalls = [];
        foreach (SophosFirewall::all() as $fw) {
            $firewalls['snmp'][$fw->ip] = $fw;
            $firewalls['sophos'][$fw->ip] = $fw;
        }
        foreach (FortigateFirewall::all() as $fw) {
            $firewalls['fortigate'][$fw->ip] = $fw;
        }

        if (! $firewalls) {
            $this->warn('No firewalls configured — nothing to label.');

            return self::SUCCESS;
        }

        $changed = 0;
        $scanned = 0;

        DhcpLease::whereIn('source', ['snmp', 'sophos', 'fortigate'])
            ->chunkById(500, function ($leases) use ($firewalls, $service, $dryRun, &$changed, &$scanned) {
                foreach ($leases as $lease) {
                    $scanned++;

                    $firewall = $firewalls[$lease->source][$lease->source_device] ?? null;
                    if (! $firewall) {
                        continue;
                    }

                    $label = $service->labelFor($firewall, $lease->mac_address);
                    if ($label === $lease->network_label) {
                        continue;
                    }

                    $changed++;
                    if (! $dryRun) {
                        $lease->update(['network_label' => $label]);
                    }
                }
            });

        $verb = $dryRun ? 'would change' : 'relabelled';
        $this->info("Scanned {$scanned} leases, {$verb} {$changed}.");

        if (! $dryRun) {
            $this->table(
                ['Network / SSID', 'Leases'],
                DhcpLease::selectRaw('COALESCE(network_label, "(unlabelled)") as label, COUNT(*) as total')
                    ->groupBy('label')
                    ->orderByDesc('total')
                    ->get()
                    ->map(fn ($r) => [$r->label, number_format($r->total)])
                    ->all()
            );
        }

        return self::SUCCESS;
    }
}
