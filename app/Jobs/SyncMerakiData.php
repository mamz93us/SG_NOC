<?php

namespace App\Jobs;

use App\Models\DhcpLease;
use App\Models\NetworkClient;
use App\Models\NetworkEvent;
use App\Models\NetworkPort;
use App\Models\NetworkSwitch;
use App\Models\NetworkSyncLog;
use App\Models\Setting;
use App\Services\Network\MerakiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncMerakiData implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries   = 2;

    // ─────────────────────────────────────────────────────────────
    // Execute
    // ─────────────────────────────────────────────────────────────

    public function handle(): void
    {
        $settings = Setting::get();

        if (!$settings->meraki_enabled) {
            Log::info('SyncMerakiData: Meraki integration is disabled — skipping.');
            return;
        }

        if (empty($settings->meraki_api_key) || empty($settings->meraki_org_id)) {
            Log::warning('SyncMerakiData: API key or Org ID not configured — skipping.');
            return;
        }

        // ── Create sync log ────────────────────────────────────────
        $syncLog = NetworkSyncLog::create([
            'status'     => 'started',
            'started_at' => now(),
        ]);

        Log::info('SyncMerakiData: Starting sync (log #' . $syncLog->id . ')');

        $switchesSynced = 0;
        $portsSynced    = 0;
        $clientsSynced  = 0;
        $eventsSynced   = 0;

        try {
            $meraki = new MerakiService();

            // ── 1. Fetch networks so we can resolve ID → name ──────────
            $networkNames = collect($meraki->getNetworks())->keyBy('id')
                                ->map(fn ($n) => $n['name'] ?? null);

            // ── 2. Fetch all devices and their statuses ────────────────
            $devices  = $meraki->getDevices();
            $statuses = collect($meraki->getDeviceStatuses())->keyBy('serial');

            // ── 3. Gather unique networks (for event sync) ─────────────
            $networkIds = [];

            // ── 3. Filter to MS* (Meraki Switches) ────────────────────
            $switches = array_filter($devices, fn ($d) => str_starts_with($d['model'] ?? '', 'MS'));

            foreach ($switches as $device) {
                $serial = $device['serial'] ?? null;
                if (!$serial) {
                    continue;
                }

                $statusData = $statuses->get($serial, []);
                $status     = strtolower($statusData['status'] ?? 'unknown');
                $networkId  = $device['networkId'] ?? null;

                if ($networkId) {
                    $networkIds[$networkId] = $networkId;
                }

                // ── Upsert switch record ───────────────────────────────
                $switch = NetworkSwitch::updateOrCreate(
                    ['serial' => $serial],
                    [
                        'network_id'        => $networkId,
                        'network_name'      => $networkNames->get($networkId) ?? $networkId,
                        'name'              => $device['name'] ?? $serial,
                        'model'             => $device['model'] ?? null,
                        'mac'               => $device['mac'] ?? null,
                        'lan_ip'            => $device['lanIp'] ?? null,
                        'firmware'          => $device['firmware'] ?? null,
                        'status'            => $status,
                        'last_reported_at'  => $statusData['lastReportedAt'] ?? null,
                        'raw_data'          => $device,
                    ]
                );

                // ── Sync ports ─────────────────────────────────────────
                $portsCount   = $this->syncPorts($meraki, $switch, $serial);
                $portsSynced += $portsCount;

                // ── Sync clients ───────────────────────────────────────
                $clientsCount   = $this->syncClients($meraki, $switch, $serial);
                $clientsSynced += $clientsCount;

                $switchesSynced++;
                Log::info("SyncMerakiData: switch {$serial} synced ({$portsCount} ports, {$clientsCount} clients)");
            }

            // ── 4. Sync events per unique network ─────────────────────
            foreach ($networkIds as $networkId) {
                $eventsSynced += $this->syncEvents($meraki, $networkId);
            }

            Log::info('SyncMerakiData: Sync complete. Switches: ' . $switchesSynced);

            // ── Mark log as completed ──────────────────────────────────
            $syncLog->update([
                'status'          => 'completed',
                'switches_synced' => $switchesSynced,
                'ports_synced'    => $portsSynced,
                'clients_synced'  => $clientsSynced,
                'events_synced'   => $eventsSynced,
                'completed_at'    => now(),
            ]);

        } catch (\Throwable $e) {
            Log::error('SyncMerakiData: Sync failed — ' . $e->getMessage());

            $syncLog->update([
                'status'          => 'failed',
                'switches_synced' => $switchesSynced,
                'ports_synced'    => $portsSynced,
                'clients_synced'  => $clientsSynced,
                'events_synced'   => $eventsSynced,
                'error_message'   => $e->getMessage(),
                'completed_at'    => now(),
            ]);

            throw $e;
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Port sync — returns count of ports synced
    // ─────────────────────────────────────────────────────────────

    private function syncPorts(MerakiService $meraki, NetworkSwitch $switch, string $serial): int
    {
        $portConfigs  = $meraki->getSwitchPorts($serial);
        $portStatuses = collect($meraki->getSwitchPortStatuses($serial))->keyBy('portId');

        $portCount = 0;

        foreach ($portConfigs as $port) {
            $portId = (string) ($port['portId'] ?? null);
            if ($portId === '') {
                continue;
            }

            $ps       = $portStatuses->get($portId, []);
            // Only use Meraki's explicit isUplink flag — trunk type is a VLAN config, NOT an uplink indicator
            $isUplink = ($ps['isUplink'] ?? false) === true;
            $portCount++;

            NetworkPort::updateOrCreate(
                ['switch_serial' => $serial, 'port_id' => $portId],
                [
                    'name'            => $port['name'] ?? null,
                    'enabled'         => $port['enabled'] ?? true,
                    'type'            => $port['type'] ?? null,
                    'vlan'            => $port['vlan'] ?? null,
                    'allowed_vlans'   => is_array($port['allowedVlans'] ?? null)
                                            ? implode(',', $port['allowedVlans'])
                                            : ($port['allowedVlans'] ?? null),
                    'poe_enabled'     => $port['poeEnabled'] ?? false,
                    'is_uplink'       => $isUplink,
                    'status'          => $ps['status'] ?? null,
                    'speed'           => $ps['speed'] ?? null,
                    'duplex'          => $ps['duplex'] ?? null,
                    'client_mac'      => $ps['clientMac'] ?? null,
                    'client_hostname' => null,
                ]
            );
        }

        $switch->update(['port_count' => $portCount]);

        return $portCount;
    }

    // ─────────────────────────────────────────────────────────────
    // Client sync — returns count of clients synced
    // ─────────────────────────────────────────────────────────────

    private function syncClients(MerakiService $meraki, NetworkSwitch $switch, string $serial): int
    {
        $clients     = $meraki->getDeviceClients($serial);
        $clientCount = count($clients);

        foreach ($clients as $client) {
            $mac = $client['mac'] ?? null;
            if (!$mac) {
                continue;
            }

            $usageSent = $client['usage']['sent'] ?? 0;
            $usageRecv = $client['usage']['recv'] ?? 0;

            NetworkClient::updateOrCreate(
                ['mac' => $mac],
                [
                    'client_id'     => $client['id'] ?? null,
                    'switch_serial' => $serial,
                    'ip'            => $client['ip'] ?? null,
                    'hostname'      => $client['dhcpHostname'] ?? $client['hostname'] ?? null,
                    'description'   => $client['description'] ?? null,
                    'vlan'          => $client['vlan'] ?? null,
                    'port_id'       => (string) ($client['switchport'] ?? ''),
                    'status'        => $client['status'] ?? null,
                    'usage_kb'      => $usageSent + $usageRecv,
                    'manufacturer'  => $client['manufacturer'] ?? null,
                    'os'            => $client['os'] ?? null,
                    'last_seen'     => isset($client['lastSeen'])
                                        ? \Carbon\Carbon::parse($client['lastSeen'])
                                        : null,
                ]
            );

            // Also create/update DHCP lease for centralized tracking
            if ($client['ip'] ?? null) {
                try {
                    DhcpLease::updateOrCreate(
                        ['mac_address' => $mac, 'source' => 'meraki'],
                        [
                            'ip_address'    => $client['ip'],
                            'hostname'      => $client['description'] ?? $client['dhcpHostname'] ?? null,
                            'vendor'        => $client['manufacturer'] ?? null,
                            'vlan'          => $client['vlan'] ?? null,
                            'switch_serial' => $serial,
                            'port_id'       => (string) ($client['switchport'] ?? ''),
                            'source_device' => $serial,
                            'branch_id'     => $switch->branch_id,
                            'last_seen'     => now(),
                        ]
                    );
                } catch (\Throwable $e) {
                    Log::debug("SyncMerakiData: DHCP lease sync failed for {$mac}: " . $e->getMessage());
                }
            }
        }

        $switch->update(['clients_count' => $clientCount]);

        return $clientCount;
    }

    // ─────────────────────────────────────────────────────────────
    // Event sync — returns count of events synced
    // ─────────────────────────────────────────────────────────────

    private function syncEvents(MerakiService $meraki, string $networkId): int
    {
        $response = $meraki->getNetworkEvents($networkId, 100);
        $events   = $response['events'] ?? [];

        foreach ($events as $event) {
            $occurredAt = isset($event['occurredAt'])
                ? \Carbon\Carbon::parse($event['occurredAt'])
                : null;

            // Use deviceSerial to link to a switch if possible
            $serial = $event['deviceSerial'] ?? null;

            NetworkEvent::updateOrCreate(
                [
                    'network_id'    => $networkId,
                    'event_type'    => $event['type'] ?? 'unknown',
                    'occurred_at'   => $occurredAt,
                    'switch_serial' => $serial,
                ],
                [
                    'description' => $event['description'] ?? null,
                    'details'     => $event,
                ]
            );
        }

        Log::info("SyncMerakiData: synced " . count($events) . " events for network {$networkId}");

        return count($events);
    }
}
