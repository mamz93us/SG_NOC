<?php

namespace App\Services;

use App\Models\NocEvent;
use App\Models\VpnLog;
use App\Models\VpnTunnel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class VpnMonitorService
{
    public function __construct(
        protected VpnControlService    $vpnService,
        protected NotificationService  $notifications
    ) {}

    public function checkAll(): void
    {
        $status = $this->vpnService->status();
        $output = $status['output'] ?? '';

        $tunnels = VpnTunnel::all();

        foreach ($tunnels as $tunnel) {
            $this->updateTunnelStatus($tunnel, $output);
        }

        // strongSwan caches the resolved IP from `remote_addrs` at load time and
        // never re-resolves it — `dpd_action = restart` retries the stale IP forever.
        // If any down tunnel uses a hostname, trigger one `swanctl --load-all` so the
        // next IKE attempt picks up the current DNS answer. Throttled to avoid
        // hammering the daemon when many tunnels share the same outage.
        $hasDownHostnameTunnel = $tunnels->contains(fn ($t) =>
            $t->status === 'down' && !filter_var($t->remote_public_ip, FILTER_VALIDATE_IP)
        );

        if ($hasDownHostnameTunnel && Cache::add('vpn_dns_reload_lock', true, 120)) {
            Log::info('VpnMonitor: DDNS-based tunnel down — reloading swanctl conns to refresh DNS.');
            $this->vpnService->reload();
        }
    }

    protected function updateTunnelStatus(VpnTunnel $tunnel, string $sasOutput): void
    {
        $oldStatus = $tunnel->status;
        $isEstablished = preg_match("/{$tunnel->name}: .*, ESTABLISHED/", $sasOutput);
        $newStatus = $isEstablished ? 'up' : 'down';

        if ($oldStatus !== $newStatus) {
            $tunnel->update(['status' => $newStatus]);

            VpnLog::create([
                'vpn_id'     => $tunnel->id,
                'event_type' => $newStatus,
                'message'    => "Tunnel status changed from {$oldStatus} to {$newStatus}.",
            ]);

            if ($newStatus === 'down') {
                $this->triggerAlert($tunnel);
            } else {
                // Tunnel came back up — resolve open NOC events. Iterate+save
                // (not ->update()) so NocEventObserver::updated fires and the
                // auto-escalated incident is closed in lockstep.
                NocEvent::where('module', 'VPN_HUB')
                    ->where('entity_type', 'vpn_tunnel')
                    ->where('entity_id', $tunnel->id)
                    ->where('status', 'open')
                    ->get()
                    ->each(fn ($ev) => $ev->update(['status' => 'resolved', 'resolved_at' => now()]));
            }
        }

        $tunnel->update(['last_checked_at' => now()]);
    }

    protected function triggerAlert(VpnTunnel $tunnel): void
    {
        $branchName = $tunnel->branch->name ?? 'Unknown';

        NocEvent::create([
            'module'      => 'VPN_HUB',
            'entity_type' => 'vpn_tunnel',
            'entity_id'   => $tunnel->id,
            'severity'    => 'critical',
            'title'       => "VPN Tunnel Down: {$tunnel->name}",
            'message'     => "The IPsec tunnel to {$tunnel->remote_public_ip} ({$branchName}) has been detected as DOWN.",
            'first_seen'  => now(),
            'last_seen'   => now(),
            'status'      => 'open',
        ]);

        $this->notifications->notifyAdmins(
            'noc_alert',
            "VPN Tunnel Down: {$tunnel->name}",
            "The IPsec tunnel '{$tunnel->name}' to {$tunnel->remote_public_ip} is DOWN.",
            null,
            'critical'
        );

        Log::warning("VPN ALERT: Tunnel {$tunnel->name} is DOWN!");
    }
}
