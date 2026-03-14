<?php

namespace App\Services;

use App\Models\NetworkClient;
use App\Models\NetworkSwitch;
use App\Models\PhonePortMap;
use App\Models\UcmExtensionCache;
use Illuminate\Support\Facades\Log;

class PhonePortDetectionService
{
    /**
     * Correlate all UCM extensions with Meraki network clients by IP address.
     * Returns the number of successful correlations.
     */
    public function correlateAll(): int
    {
        $extensions = UcmExtensionCache::whereNotNull('ip_address')
            ->where('ip_address', '!=', '')
            ->get();

        $correlated = 0;

        // Pre-load a map of IP → NetworkClient for efficiency
        $clientsByIp = NetworkClient::whereNotNull('ip')
            ->where('ip', '!=', '')
            ->get()
            ->keyBy('ip');

        // Pre-load switch names
        $switchNames = NetworkSwitch::pluck('name', 'serial')->toArray();

        foreach ($extensions as $ext) {
            $result = $this->correlateExtension($ext, $clientsByIp, $switchNames);

            if ($result) {
                PhonePortMap::updateOrCreate(
                    ['ucm_server_id' => $ext->ucm_id, 'extension' => $ext->extension],
                    [
                        'phone_ip'      => $ext->ip_address,
                        'phone_mac'     => $result['mac'],
                        'switch_name'   => $result['switch_name'],
                        'switch_serial' => $result['switch_serial'],
                        'switch_port'   => $result['port_id'],
                        'vlan'          => $result['vlan'],
                        'last_seen_at'  => now(),
                    ]
                );
                $correlated++;
            }
        }

        // Clean up stale mappings for extensions that no longer have an IP
        $activeExtKeys = $extensions->map(fn ($e) => $e->ucm_id . '-' . $e->extension)->toArray();
        $allMaps = PhonePortMap::all();
        foreach ($allMaps as $map) {
            $key = $map->ucm_server_id . '-' . $map->extension;
            if (!in_array($key, $activeExtKeys)) {
                $map->delete();
            }
        }

        Log::info("PhonePortDetectionService: Correlated {$correlated} of {$extensions->count()} extensions.");

        return $correlated;
    }

    /**
     * Correlate a single extension with Meraki data.
     */
    public function correlateExtension(
        UcmExtensionCache $ext,
        $clientsByIp = null,
        array $switchNames = []
    ): ?array {
        if (!$ext->ip_address) {
            return null;
        }

        // Find the network client matching this phone's IP
        $client = $clientsByIp
            ? ($clientsByIp[$ext->ip_address] ?? null)
            : NetworkClient::where('ip', $ext->ip_address)->first();

        if (!$client) {
            return null;
        }

        $switchName = $switchNames[$client->switch_serial] ?? null;
        if (!$switchName) {
            $switch = NetworkSwitch::where('serial', $client->switch_serial)->first();
            $switchName = $switch?->name ?? $client->switch_serial;
        }

        return [
            'mac'           => strtoupper($client->mac ?? ''),
            'switch_name'   => $switchName,
            'switch_serial' => $client->switch_serial,
            'port_id'       => $client->port_id ?? $client->port_name ?? null,
            'vlan'          => $client->vlan,
        ];
    }
}
