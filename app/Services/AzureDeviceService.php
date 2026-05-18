<?php

namespace App\Services;

use App\Models\AssetHistory;
use App\Models\AzureDevice;
use App\Models\Device;
use App\Models\EmployeeAsset;
use App\Services\Identity\GraphService;
use Illuminate\Support\Facades\Log;

class AzureDeviceService
{
    private GraphService $graph;

    public function __construct(?GraphService $graph = null)
    {
        $this->graph = $graph ?? new GraphService;
    }

    /**
     * Sync Azure AD / Intune devices into azure_devices table.
     * Returns summary: ['synced' => N, 'new' => N, 'auto_linked' => N, 'auto_assigned' => N]
     */
    public function syncDevices(): array
    {
        $synced = 0;
        $newCount = 0;
        $autoLinked = 0;
        $autoAssigned = 0;
        $skipped = 0;

        try {
            $azureDevices = $this->fetchAzureAdDevices();
            $intuneDevices = $this->fetchIntuneDevices();

            // Merge, prefer Intune data when device IDs overlap
            $merged = $this->mergeDeviceLists($azureDevices, $intuneDevices);

            foreach ($merged as $data) {
                try {
                    $result = $this->upsertDevice($data);
                    if ($result['new']) {
                        $newCount++;
                    }
                    if ($result['auto_linked']) {
                        $autoLinked++;
                    }
                    if ($result['auto_assigned']) {
                        $autoAssigned++;
                    }
                    $synced++;
                } catch (\Throwable $devEx) {
                    $skipped++;
                    Log::warning('AzureDeviceService: skipped '.($data['azure_device_id'] ?? '?').': '.$devEx->getMessage());
                }
            }
        } catch (\Throwable $e) {
            Log::error('AzureDeviceService::syncDevices failed: '.$e->getMessage());
            throw $e;
        }

        return [
            'synced' => $synced,
            'new' => $newCount,
            'auto_linked' => $autoLinked,
            'auto_assigned' => $autoAssigned,
            'skipped' => $skipped,
        ];
    }

    /**
     * Upsert a single Azure device row from a normalised payload. Returns
     * flags indicating whether the row was newly created, auto-linked to a
     * local Device, and auto-assigned to an Employee.
     *
     * Public so tests can drive it without mocking the Graph fetches.
     *
     * @param  array<string,mixed>  $data
     * @return array{new:bool, auto_linked:bool, auto_assigned:bool, model:AzureDevice}
     */
    public function upsertDevice(array $data): array
    {
        // Sanitise UPN — strip any leading hex garbage that occasionally
        // appears when Intune concatenates the Azure AD object ID prefix.
        $rawUpn = $data['upn'] ?? null;
        if ($rawUpn && preg_match('/[0-9a-f]{32}(.+@.+)/i', $rawUpn, $m)) {
            $rawUpn = $m[1];
        }

        // intune_managed_device_id is uniquely indexed. Microsoft's latest
        // sync wins — null out the same value on any other row before upsert
        // so a re-enrolled or swapped device doesn't trigger 1062.
        $intuneId = $data['intune_id'] ?? null;
        if ($intuneId) {
            AzureDevice::where('intune_managed_device_id', $intuneId)
                ->where('azure_device_id', '!=', $data['azure_device_id'])
                ->update(['intune_managed_device_id' => null]);
        }

        // ── Locate existing row ────────────────────────────────────
        // 1. Exact azure_device_id match (the happy path).
        // 2. Fallback: same serial_number, different azure_device_id.
        //    This catches reformatted / re-enrolled machines that
        //    Azure issues a fresh deviceId for. We merge in place to
        //    avoid duplicate AzureDevice rows.
        $azDev = AzureDevice::where('azure_device_id', $data['azure_device_id'])->first();
        $exists = (bool) $azDev;

        $serial = $data['serial_number'] ?? null;
        if (! $azDev && $serial) {
            $candidate = AzureDevice::where('serial_number', $serial)
                ->where('azure_device_id', '!=', $data['azure_device_id'])
                ->orderByDesc('last_sync_at')
                ->first();

            if ($candidate) {
                $oldUpn = $candidate->upn;

                // If the new UPN differs from the old, the device was
                // reassigned to another employee — soft-return the
                // existing EmployeeAsset row so the new owner gets a
                // clean slate from attemptAutoAssign() below.
                if ($candidate->device_id && $oldUpn && $rawUpn && strcasecmp($oldUpn, $rawUpn) !== 0) {
                    EmployeeAsset::where('asset_id', $candidate->device_id)
                        ->whereNull('returned_date')
                        ->update([
                            'returned_date' => now(),
                            'notes' => 'Azure device rotated (serial '.$serial.') — old UPN '.$oldUpn,
                        ]);

                    $localDevice = Device::find($candidate->device_id);
                    if ($localDevice) {
                        AssetHistory::record(
                            $localDevice,
                            'returned',
                            "Auto-released — Azure deviceId rotated on serial {$serial} (old UPN {$oldUpn} → new UPN {$rawUpn})"
                        );
                        $localDevice->update(['status' => 'available']);
                    }
                }

                $azDev = $candidate;
                $exists = true; // merging, not new
            }
        }

        if (! $azDev) {
            $azDev = new AzureDevice;
        }

        $azDev->fill([
            'azure_device_id' => $data['azure_device_id'],
            'intune_managed_device_id' => $intuneId,
            'display_name' => $data['display_name'],
            'device_type' => $data['device_type'] ?? null,
            'os' => $data['os'] ?? null,
            'os_version' => $data['os_version'] ?? null,
            'upn' => $rawUpn,
            'serial_number' => $serial,
            'manufacturer' => $data['manufacturer'] ?? null,
            'model' => $data['model'] ?? null,
            'enrolled_date' => $data['enrolled_date'] ?? null,
            'last_activity_at' => $data['last_activity'] ?? null,
            'last_sync_at' => now(),
            'raw_data' => $data,
        ])->save();

        $this->syncLinkedDeviceName($azDev);

        $autoLinked = false;
        $autoAssigned = false;

        if ($azDev->link_status === 'unlinked' && $azDev->serial_number) {
            $autoLinked = $this->attemptAutoLink($azDev);
        }

        if ($azDev->link_status === 'linked' && $azDev->device_id && $rawUpn) {
            $autoAssigned = $this->attemptAutoAssign($azDev, $rawUpn);
        }

        return [
            'new' => ! $exists,
            'auto_linked' => $autoLinked,
            'auto_assigned' => $autoAssigned,
            'model' => $azDev,
        ];
    }

    /**
     * Try to match an Azure device to a local Device by serial number.
     * Sets link_status to 'pending' if matched (requires human approval).
     */
    public function attemptAutoLink(AzureDevice $azDev): bool
    {
        if (! $azDev->serial_number) {
            return false;
        }

        $device = Device::where('serial_number', $azDev->serial_number)->first();

        if ($device) {
            // PO-tagged devices are provenance-verified — auto-promote to 'linked'.
            // Manually-created device stubs still require human approval ('pending').
            $status = $device->purchase_order_id ? 'linked' : 'pending';

            $azDev->update([
                'device_id' => $device->id,
                'link_status' => $status,
            ]);

            $this->syncLinkedDeviceName($azDev);

            return true;
        }

        return false;
    }

    /**
     * Push the latest AzureDevice display_name into the linked ITAM Device.name.
     * No-op if the AzureDevice isn't linked, has no display_name, or names already
     * match. Logs the previous name to AssetHistory so admins can audit the rename.
     */
    private function syncLinkedDeviceName(AzureDevice $azDev): void
    {
        if (! $azDev->device_id || $azDev->link_status !== 'linked') {
            return;
        }

        $newName = trim((string) $azDev->display_name);
        if ($newName === '') {
            return;
        }

        $device = Device::find($azDev->device_id);
        if (! $device) {
            return;
        }

        $oldName = (string) $device->name;
        if ($oldName === $newName) {
            return;
        }

        AssetHistory::record(
            $device,
            'updated',
            "Name changed from '{$oldName}' to '{$newName}' via Azure sync.",
            [
                'azure_device_id' => $azDev->azure_device_id,
                'old_name' => $oldName,
                'new_name' => $newName,
            ]
        );

        $device->update(['name' => $newName]);

        Log::info("AzureDeviceService: renamed Device #{$device->id} '{$oldName}' → '{$newName}' from Azure display_name.");
    }

    /**
     * Attempt to assign the ITAM asset (linked via $azDev->device_id) to the
     * employee matching the given UPN.  Only creates the assignment when the
     * asset has no current employee assignment.  Returns true if a new
     * assignment was created.
     */
    public function attemptAutoAssign(AzureDevice $azDev, string $upn): bool
    {
        $employee = $this->findEmployeeByUpn($upn);
        if (! $employee) {
            return false;
        }

        $device = Device::find($azDev->device_id);
        if (! $device) {
            return false;
        }

        // Don't overwrite an existing assignment
        $existing = \App\Models\EmployeeAsset::where('asset_id', $device->id)
            ->whereNull('returned_date')
            ->first();
        if ($existing) {
            return false;
        }

        \App\Models\EmployeeAsset::create([
            'employee_id' => $employee->id,
            'asset_id' => $device->id,
            'assigned_date' => now(),
            'condition' => 'used',
            'notes' => 'Auto-assigned from Intune UPN during Azure sync.',
        ]);
        $device->update(['status' => 'assigned']);

        \App\Models\AssetHistory::record(
            $device,
            'assigned',
            "Auto-assigned to {$employee->name} from Intune UPN ({$upn}) during Azure sync."
        );

        Log::info("AzureDeviceService: auto-assigned {$device->name} to {$employee->name} (UPN={$upn})");

        return true;
    }

    /**
     * Find (or auto-create) the Employee who owns this UPN.
     *
     * Lookup chain (first match wins):
     *   1. Employee.email = upn                         (exact)
     *   2. IdentityUser matched → Employee by azure_id  (shared object ID)
     *   3. IdentityUser.mail    → Employee.email        (proxy address)
     *   4. IdentityUser found + account_enabled = true  → create Employee from
     *      IdentityUser.display_name so future syncs can also assign.
     *      Disabled (ex-employee) accounts are intentionally skipped.
     */
    public function findEmployeeByUpn(?string $upn): ?\App\Models\Employee
    {
        if (empty($upn)) {
            return null;
        }

        // 1. Direct email match
        $employee = \App\Models\Employee::where('email', $upn)->first();
        if ($employee) {
            return $employee;
        }

        // 2 & 3. Via IdentityUser
        $identityUser = \App\Models\IdentityUser::where('user_principal_name', $upn)
            ->orWhere('mail', $upn)
            ->first();

        if (! $identityUser) {
            return null;
        }

        if ($identityUser->azure_id) {
            $employee = \App\Models\Employee::where('azure_id', $identityUser->azure_id)->first();
            if ($employee) {
                return $employee;
            }
        }

        if ($identityUser->mail) {
            $employee = \App\Models\Employee::where('email', $identityUser->mail)->first();
            if ($employee) {
                return $employee;
            }
        }

        // 4. Account exists in Azure AD but no Employee record yet.
        //    Only auto-create for ACTIVE (enabled) accounts — disabled = left the company.
        if (! $identityUser->account_enabled) {
            return null;
        }
        if (empty($identityUser->display_name)) {
            return null;
        }

        $email = $identityUser->mail ?: $upn;

        try {
            $employee = \App\Models\Employee::create([
                'name' => $identityUser->display_name,
                'email' => $email,
                'azure_id' => $identityUser->azure_id,
                'status' => 'active',
            ]);
            Log::info("AzureDeviceService: auto-created Employee '{$employee->name}' ({$email}) from IdentityUser during UPN lookup.");

            return $employee;
        } catch (\Throwable $e) {
            Log::warning("AzureDeviceService: failed to auto-create Employee for UPN {$upn}: ".$e->getMessage());

            return null;
        }
    }

    private function fetchAzureAdDevices(): array
    {
        try {
            // Use reflection to call the private paginate method via the graph service
            // Instead, call the public listUsers-style endpoint using Http directly
            $token = $this->getToken();
            $response = \Illuminate\Support\Facades\Http::timeout(120)
                ->withToken($token)
                ->get('https://graph.microsoft.com/v1.0/devices', [
                    '$select' => 'id,displayName,operatingSystem,operatingSystemVersion,deviceId,physicalIds,approximateLastSignInDateTime',
                    '$top' => 999,
                ]);

            if ($response->status() === 403) {
                $token = $this->graph->refreshToken();
                $response = \Illuminate\Support\Facades\Http::timeout(120)
                    ->withToken($token)
                    ->get('https://graph.microsoft.com/v1.0/devices', [
                        '$select' => 'id,displayName,operatingSystem,operatingSystemVersion,deviceId,physicalIds,approximateLastSignInDateTime',
                        '$top' => 999,
                    ]);
            }

            if (! $response->successful()) {
                Log::warning('AzureDeviceService: Failed to fetch Azure AD devices: '.$response->body());

                return [];
            }

            return collect($response->json('value', []))->map(function ($d) {
                return [
                    'azure_device_id' => $d['deviceId'] ?? $d['id'], // Use hardware deviceId for matching
                    'graph_id' => $d['id'],
                    'display_name' => $d['displayName'] ?? 'Unknown',
                    'os' => $d['operatingSystem'] ?? null,
                    'os_version' => $d['operatingSystemVersion'] ?? null,
                    'serial_number' => $this->extractSerial($d['physicalIds'] ?? []),
                    'last_activity' => $d['approximateLastSignInDateTime'] ?? null,
                ];
            })->toArray();
        } catch (\Throwable $e) {
            Log::warning('AzureDeviceService: Azure AD devices fetch exception: '.$e->getMessage());

            return [];
        }
    }

    private function fetchIntuneDevices(): array
    {
        try {
            $token = $this->getToken();
            $response = \Illuminate\Support\Facades\Http::timeout(120)
                ->withToken($token)
                ->get('https://graph.microsoft.com/v1.0/deviceManagement/managedDevices', [
                    '$select' => 'id,deviceName,serialNumber,userPrincipalName,operatingSystem,enrolledDateTime,lastSyncDateTime,model,manufacturer,azureADDeviceId',
                    '$top' => 999,
                ]);

            if ($response->status() === 403) {
                $token = $this->graph->refreshToken();
                $response = \Illuminate\Support\Facades\Http::timeout(120)
                    ->withToken($token)
                    ->get('https://graph.microsoft.com/v1.0/deviceManagement/managedDevices', [
                        '$select' => 'id,deviceName,serialNumber,userPrincipalName,operatingSystem,enrolledDateTime,lastSyncDateTime,model,manufacturer,azureADDeviceId',
                        '$top' => 999,
                    ]);
            }

            if (! $response->successful()) {
                Log::warning('AzureDeviceService: Failed to fetch Intune devices: '.$response->body());

                return [];
            }

            return collect($response->json('value', []))->map(function ($d) {
                return [
                    'azure_device_id' => $d['azureADDeviceId'] ?? $d['id'], // Match with Azure AD hardware ID
                    'intune_id' => $d['id'],
                    'display_name' => $d['deviceName'] ?? 'Unknown',
                    'os' => $d['operatingSystem'] ?? null,
                    'serial_number' => $d['serialNumber'] ?? null,
                    'manufacturer' => $d['manufacturer'] ?? null,
                    'model' => $d['model'] ?? null,
                    'upn' => $d['userPrincipalName'] ?? null,
                    'enrolled_date' => $d['enrolledDateTime'] ?? null,
                    'last_activity' => $d['lastSyncDateTime'] ?? null,
                    'device_type' => null,
                ];
            })->toArray();
        } catch (\Throwable $e) {
            Log::warning('AzureDeviceService: Intune devices fetch exception: '.$e->getMessage());

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
        $ref = new \ReflectionClass($this->graph);
        $method = $ref->getMethod('getAccessToken');
        $method->setAccessible(true);

        return $method->invoke($this->graph);
    }
}
