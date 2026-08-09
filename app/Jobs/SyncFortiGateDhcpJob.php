<?php

namespace App\Jobs;

use App\Models\FortigateFirewall;
use App\Services\DhcpLeaseService;
use App\Services\FortiGate\FortiGateApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Pull the DHCP lease table from one FortiGate and fold it into dhcp_leases.
 *
 * Runs inline from the scheduler (production has no queue worker — see CLAUDE.md).
 */
class SyncFortiGateDhcpJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public FortigateFirewall $firewall) {}

    public function handle(): int
    {
        $api = new FortiGateApiService($this->firewall);

        try {
            $payload = $api->dhcpLeases();
        } catch (\Throwable $e) {
            $this->firewall->forceFill([
                'last_sync_error' => $e->getMessage(),
            ])->save();

            Log::error("FortiGate DHCP sync failed for {$this->firewall->name}: {$e->getMessage()}");
            throw $e;
        }

        $count = app(DhcpLeaseService::class)
            ->syncFromFortiGate($payload['results'], $this->firewall);

        $this->firewall->forceFill([
            'serial_number' => $payload['serial'] ?: $this->firewall->serial_number,
            'firmware_version' => $payload['version'] ?: $this->firewall->firmware_version,
            'last_lease_count' => $count,
            'last_synced_at' => now(),
            'last_sync_error' => null,
        ])->save();

        Log::info("FortiGate DHCP sync: {$count} leases from {$this->firewall->name}");

        return $count;
    }
}
