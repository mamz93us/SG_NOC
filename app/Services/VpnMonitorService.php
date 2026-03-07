<?php

namespace App\Services;

use App\Models\VpnLog;
use App\Models\VpnTunnel;
use Illuminate\Support\Facades\Log;

class VpnMonitorService
{
    protected VpnControlService $vpnService;

    public function __construct(VpnControlService $vpnService)
    {
        $this->vpnService = $vpnService;
    }

    /**
     * Check status of all tunnels and update database.
     */
    public function checkAll(): void
    {
        $status = $this->vpnService->status();
        $output = $status['output'] ?? '';

        $tunnels = VpnTunnel::all();

        foreach ($tunnels as $tunnel) {
            $this->updateTunnelStatus($tunnel, $output);
        }
    }

    protected function updateTunnelStatus(VpnTunnel $tunnel, string $sasOutput): void
    {
        $oldStatus = $tunnel->status;
        
        // Simple regex to find if tunnel is ESTABLISHED
        // swanctl output usually contains "tunnel_name: #N, ESTABLISHED"
        $isEstablished = preg_match("/{$tunnel->name}: .*, ESTABLISHED/", $sasOutput);
        
        $newStatus = $isEstablished ? 'up' : 'down';

        if ($oldStatus !== $newStatus) {
            $tunnel->update(['status' => $newStatus]);
            
            VpnLog::create([
                'vpn_id' => $tunnel->id,
                'event_type' => $newStatus,
                'message' => "Tunnel status changed from $oldStatus to $newStatus."
            ]);

            // Trigger alerting logic here (Phase 6)
            if ($newStatus === 'down') {
                $this->triggerAlert($tunnel);
            }
        }

        $tunnel->update(['last_checked_at' => now()]);
    }

    protected function triggerAlert(VpnTunnel $tunnel): void
    {
        \App\Models\NocEvent::create([
            'module'      => 'VPN_HUB',
            'entity_type' => 'vpn_tunnel',
            'entity_id'   => $tunnel->id,
            'severity'    => 'critical',
            'title'       => "VPN Tunnel Down: {$tunnel->name}",
            'message'     => "The IPsec tunnel to {$tunnel->remote_public_ip} ({$tunnel->branch->name}) has been detected as DOWN.",
            'first_seen'  => now(),
            'last_seen'   => now(),
            'status'      => 'open',
        ]);

        Log::warning("VPN ALERT: Tunnel {$tunnel->name} is DOWN!");
    }
}
