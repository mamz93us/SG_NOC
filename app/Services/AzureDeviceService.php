<?php

namespace App\Services;

use App\Models\AzureDevice;
use App\Models\Device;
use App\Services\Identity\GraphService;
use Illuminate\Support\Facades\Log;

class AzureDeviceService
{
    private GraphService $graph;

    public function __construct(?GraphService $graph = null)
    {
        $this->graph = $graph ?? new GraphService();
    }

    /**
     * Sync Azure AD / Intune devices into azure_devices table.
     * Returns summary: ['synced' => N, 'new' => N, 'auto_linked' => N]
     */
    public function syncDevices(): array
    {
        $synced     = 0;
        $newCount   = 0;
        $autoLinked = 0;

        try {
            $azureDevices  = $this->fetchAzureAdDevices();
            $intuneDevices = $this->fetchIntuneDevices();

            // Merge, prefer Intune data when device IDs overlap
            $merged = $this->mergeDeviceLists($azureDevices, $intuneDevices);

            foreach ($merged as $data) {
                $exists = AzureDevice::where('azure_device_id', $data['azure_device_id'])->exists();

                $azDev = AzureDevice::updateOrCreate(
                    ['azure_device_id' => $data['azure_device_id']],
                    [
                        'display_name'  => $data['display_name'],
                        'device_type'   => $data['device_type'] ?? null,
                        'os'            => $data['os'] ?? null,
                        'os_version'    => $data['os_version'] ?? null,
                        'upn'           => $data['upn'] ?? null,
                        'serial_number' => $data['serial_number'] ?? null,
                        'enrolled_date' => $data['enrolled_date'] ?? null,
                        'last_sync_at'  => now(),
                        'raw_data'      => $data,
                    ]
                );

                if (!$exists) $newCount++;
                $synced++;

                // Try auto-link for unlinked devices
                if ($azDev->link_status === 'unlinked' && $azDev->serial_number) {
                    $linked = $this->attemptAutoLink($azDev);
                    if ($linked) $autoLinked++;
                }
            }
        } catch (\Throwable $e) {
            Log::error('AzureDeviceService::syncDevices failed: ' . $e->getMessage());
            throw $e;
        }

        return ['synced' => $synced, 'new' => $newCount, 'auto_linked' => $autoLinked];
    }

    /**
     * Try to match an Azure device to a local Device by serial number.
     * Sets link_status to 'pending' if matched (requires human approval).
     */
    public function attemptAutoLink(AzureDevice $azDev): bool
    {
        if (!$azDev->serial_number) return false;

        $device = Device::where('serial_number', $azDev->serial_number)->first();

        if ($device) {
            $azDev->update([
                'device_id'   => $device->id,
                'link_status' => 'pending',
            ]);
            return true;
        }

        return false;
    }

    private function fetchAzureAdDevices(): array
    {
        try {
            // Use reflection to call the private paginate method via the graph service
            // Instead, call the public listUsers-style endpoint using Http directly
            $token    = $this->getToken();
            $response = \Illuminate\Support\Facades\Http::timeout(120)
                ->withToken($token)
                ->get('https://graph.microsoft.com/v1.0/devices', [
                    '$select' => 'id,displayName,operatingSystem,operatingSystemVersion,deviceId,physicalIds',
                    '$top'    => 999,
                ]);

            if (!$response->successful()) {
                Log::warning('AzureDeviceService: Failed to fetch Azure AD devices: ' . $response->body());
                return [];
            }

            return collect($response->json('value', []))->map(function ($d) {
                return [
                    'azure_device_id' => $d['id'],
                    'display_name'    => $d['displayName'] ?? 'Unknown',
                    'os'              => $d['operatingSystem'] ?? null,
                    'os_version'      => $d['operatingSystemVersion'] ?? null,
                    'serial_number'   => $this->extractSerial($d['physicalIds'] ?? []),
                ];
            })->toArray();
        } catch (\Throwable $e) {
            Log::warning('AzureDeviceService: Azure AD devices fetch exception: ' . $e->getMessage());
            return [];
        }
    }

    private function fetchIntuneDevices(): array
    {
        try {
            $token    = $this->getToken();
            $response = \Illuminate\Support\Facades\Http::timeout(120)
                ->withToken($token)
                ->get('https://graph.microsoft.com/v1.0/deviceManagement/managedDevices', [
                    '$select' => 'id,deviceName,serialNumber,userPrincipalName,operatingSystem,enrolledDateTime,model,manufacturer',
                    '$top'    => 999,
                ]);

            if (!$response->successful()) {
                Log::warning('AzureDeviceService: Failed to fetch Intune devices: ' . $response->body());
                return [];
            }

            return collect($response->json('value', []))->map(function ($d) {
                return [
                    'azure_device_id' => $d['id'],
                    'display_name'    => $d['deviceName'] ?? 'Unknown',
                    'os'              => $d['operatingSystem'] ?? null,
                    'serial_number'   => $d['serialNumber'] ?? null,
                    'upn'             => $d['userPrincipalName'] ?? null,
                    'enrolled_date'   => $d['enrolledDateTime'] ?? null,
                    'device_type'     => null,
                ];
            })->toArray();
        } catch (\Throwable $e) {
            Log::warning('AzureDeviceService: Intune devices fetch exception: ' . $e->getMessage());
            return [];
        }
    }

    private function mergeDeviceLists(array $azureAd, array $intune): array
    {
        $map = [];
        foreach ($azureAd as $d) {
            $map[$d['azure_device_id']] = $d;
        }
        foreach ($intune as $d) {
            // Intune data overrides Azure AD (more complete)
            $map[$d['azure_device_id']] = array_merge($map[$d['azure_device_id']] ?? [], $d);
        }
        return array_values($map);
    }

    private function extractSerial(array $physicalIds): ?string
    {
        foreach ($physicalIds as $id) {
            if (str_starts_with($id, '[SERIALNUMBER]:')) {
                return substr($id, strlen('[SERIALNUMBER]:'));
            }
        }
        return null;
    }

    private function getToken(): string
    {
        // Reuse GraphService token via reflection (access private getAccessToken)
        $ref    = new \ReflectionClass($this->graph);
        $method = $ref->getMethod('getAccessToken');
        $method->setAccessible(true);
        return $method->invoke($this->graph);
    }
}
