<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AzureDevice;
use App\Models\Device;
use App\Models\AssetHistory;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\AzureBranchMapping;
use App\Services\AzureDeviceService;
use Illuminate\Http\Request;

class AzureSyncController extends Controller
{
    public function index(Request $request)
    {
        $pending = AzureDevice::where('link_status', 'pending')
            ->with('device')
            ->orderBy('display_name')
            ->get();

        $query = AzureDevice::with('device');

        // Search (Global)
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('display_name', 'like', "%{$s}%")
                  ->orWhere('serial_number', 'like', "%{$s}%")
                  ->orWhere('upn', 'like', "%{$s}%");
            });
        }

        // Specific Filters
        if ($request->filled('status')) {
            $query->where('link_status', $request->status);
        }
        if ($request->filled('upn')) {
            $query->where('upn', 'like', "%{$request->upn}%");
        }

        // Sorting
        $sort      = $request->get('sort', 'display_name');
        $direction = $request->get('direction', 'asc');
        $allowed   = ['display_name', 'os', 'serial_number', 'upn', 'link_status', 'last_activity_at', 'last_sync_at', 'net_data_synced_at'];
        
        if (in_array($sort, $allowed)) {
            $query->orderBy($sort, $direction === 'desc' ? 'desc' : 'asc');
        } else {
            $query->orderBy('display_name', 'asc');
        }

        $azureDevices = $query->paginate(50)->withQueryString();
        $lastSync     = AzureDevice::max('last_sync_at');
        $statuses     = AzureDevice::LINK_STATUSES;

        return view('admin.itam.azure.index', compact('pending', 'azureDevices', 'lastSync', 'statuses'));
    }

    public function sync(Request $request)
    {
        // Run inline via the artisan command (avoids queue-worker dependency).
        // set_time_limit to prevent 504 on large tenants.
        try {
            set_time_limit(300);
            $service = new AzureDeviceService();
            $result  = $service->syncDevices();
            ActivityLog::log("Azure device sync completed: {$result['synced']} synced, {$result['new']} new, {$result['auto_linked']} auto-linked");

            return back()->with('success',
                "Sync complete — {$result['synced']} devices synced, {$result['new']} new, {$result['auto_linked']} auto-linked."
            );
        } catch (\Throwable $e) {
            ActivityLog::log("Azure device sync failed: " . $e->getMessage());
            return back()->with('error', 'Sync failed: ' . $e->getMessage());
        }
    }

    public function approve(Request $request, AzureDevice $azureDevice)
    {
        if (!$azureDevice->device_id) {
            return back()->with('error', 'No device linked to approve.');
        }

        $azureDevice->update(['link_status' => 'linked']);

        // Record in asset history
        $device = Device::find($azureDevice->device_id);
        if ($device) {
            AssetHistory::record($device, 'note_added', "Azure device link approved: {$azureDevice->display_name} ({$azureDevice->azure_device_id})");
        }

        ActivityLog::log("Approved Azure device link: {$azureDevice->display_name}");

        return back()->with('success', "Device '{$azureDevice->display_name}' linked successfully.");
    }

    public function reject(Request $request, AzureDevice $azureDevice)
    {
        $azureDevice->update(['link_status' => 'rejected', 'device_id' => null]);
        ActivityLog::log("Rejected Azure device link: {$azureDevice->display_name}");

        return back()->with('success', "Device link rejected.");
    }

    public function linkDevice(Request $request, AzureDevice $azureDevice)
    {
        $request->validate(['device_id' => 'required|exists:devices,id']);
        $azureDevice->update([
            'device_id'   => $request->device_id,
            'link_status' => 'pending',
        ]);
        $device = Device::find($request->device_id);
        if ($device) {
            AssetHistory::record($device, 'note_added', "Azure device linked (pending approval): {$azureDevice->display_name}");
        }
        ActivityLog::log("Linked Azure device {$azureDevice->display_name} to device ID {$request->device_id} (pending)");
        return response()->json(['ok' => true, 'message' => 'Linked — pending approval.']);
    }

    /**
     * Full-page device detail — shows net data, MACs, IP, TeamViewer.
     */
    public function show(AzureDevice $azureDevice)
    {
        $azureDevice->load(['device.branch', 'macs']);

        // Resolve IP address — priority: linked ITAM device → DHCP lease (by any known MAC) → SNMP host
        $monitoredHost = \App\Models\MonitoredHost::where('name', $azureDevice->display_name)->first()
            ?? \App\Models\MonitoredHost::where('name', 'like', '%' . $azureDevice->display_name . '%')->first();

        $ipAddress = $azureDevice->device?->ip_address;

        if (! $ipAddress) {
            // Collect all known MACs for this device (ethernet, wifi, usb adapters)
            $rawMacs = $azureDevice->macs->pluck('mac_address')->toArray();
            if ($azureDevice->ethernet_mac) $rawMacs[] = $azureDevice->ethernet_mac;
            if ($azureDevice->wifi_mac)     $rawMacs[] = $azureDevice->wifi_mac;

            // Normalize to uppercase no-separator for comparison
            $normalised = array_values(array_unique(array_filter(
                array_map(fn ($m) => strtoupper(preg_replace('/[^a-fA-F0-9]/', '', $m)), $rawMacs)
            )));

            if (! empty($normalised)) {
                // DHCP leases may store MAC with colons (Meraki: lowercase) or dashes — compare stripped
                $dhcpLease = \App\Models\DhcpLease::whereRaw(
                    'UPPER(REPLACE(REPLACE(mac_address, \':\', \'\'), \'-\', \'\')) IN (' .
                    implode(',', array_fill(0, count($normalised), '?')) . ')',
                    $normalised
                )->latest('last_seen')->first();

                $ipAddress = $dhcpLease?->ip_address;
            }
        }

        // Final fallback: SNMP monitored host
        $ipAddress = $ipAddress ?? $monitoredHost?->ip;

        return view('admin.itam.azure.show', compact('azureDevice', 'monitoredHost', 'ipAddress'));
    }

    /**
     * JSON endpoint used by the index page modal (AJAX).
     */
    public function showJson(AzureDevice $azureDevice)
    {
        $azureDevice->load('device.branch');
        return response()->json([
            'id'           => $azureDevice->id,
            'display_name' => $azureDevice->display_name,
            'azure_id'     => $azureDevice->azure_device_id,
            'device_type'  => $azureDevice->device_type,
            'os'           => $azureDevice->os,
            'os_version'   => $azureDevice->os_version,
            'serial'       => $azureDevice->serial_number,
            'manufacturer' => $azureDevice->manufacturer,
            'model'        => $azureDevice->model,
            'upn'          => $azureDevice->upn,
            'enrolled_at'    => $azureDevice->enrolled_date?->format('d M Y'),
            'last_activity'  => $azureDevice->last_activity_at?->format('d M Y H:i'),
            'last_sync'      => $azureDevice->last_sync_at?->format('d M Y H:i'),
            'link_status'  => $azureDevice->link_status,
            'raw_data'     => $azureDevice->raw_data,
            'linked_device' => $azureDevice->device ? [
                'id'         => $azureDevice->device->id,
                'name'       => $azureDevice->device->name,
                'type'       => $azureDevice->device->type,
                'serial'     => $azureDevice->device->serial_number,
                'model'      => $azureDevice->device->deviceModel?->name,
                'branch'     => $azureDevice->device->branch?->name,
                'asset_code' => $azureDevice->device->asset_code,
                'url'        => route('admin.devices.show', $azureDevice->device),
            ] : null,
        ]);
    }

    public function createDevice(AzureDevice $azureDevice)
    {
        $raw = $azureDevice->raw_data ?? [];

        return redirect()->route('admin.devices.create', [
            'name'            => $azureDevice->display_name,
            'serial_number'   => $azureDevice->serial_number,
            'type'            => $this->guessDeviceType($azureDevice),
            'az_manufacturer' => $azureDevice->manufacturer,
            'az_model'        => $azureDevice->model,
            'az_upn'          => $azureDevice->upn,
            'azure_sync_id'   => $azureDevice->id,
        ]);
    }

    public function previewImport(AzureDevice $azureDevice)
    {
        $codeService = new \App\Services\AssetCodeService();
        $type        = $this->guessDeviceType($azureDevice);
        $code        = $codeService->generate($type); // Use global sequence (SG-LAP-XXXX)
        $employee    = $this->findEmployeeByUpn($azureDevice->upn);

        // Detect Branch
        $branchId = $this->detectBranchId($azureDevice);
        $branch   = $branchId ? \App\Models\Branch::find($branchId) : null;

        return response()->json([
            'proposed_code'   => $code,
            'proposed_user'   => $employee ? [
                'id'    => $employee->id,
                'name'  => $employee->name,
                'email' => $employee->email,
            ] : null,
            'device_type'     => $type,
            'proposed_branch' => $branch ? $branch->name : null,
            'proposed_branch_id' => $branchId,
        ]);
    }

    /**
     * Final approval: Create the asset and link the user.
     */
    public function importToItam(Request $request, AzureDevice $azureDevice)
    {
        if ($azureDevice->link_status === 'linked') {
            return back()->with('error', 'Device is already imported/linked.');
        }

        $request->validate([
            'type'       => 'required|string',
            'asset_code' => 'required|string|unique:devices,asset_code',
        ]);

        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($azureDevice, $request) {
                // Correctly map from enrolled_date (vps data)
                $enrollDate = $azureDevice->enrolled_date ? \Carbon\Carbon::parse($azureDevice->enrolled_date) : now();

                // 1. Check if an asset with this Azure ID already exists (maybe created manually)
                $device = Device::where('source', 'azure')
                                ->where('source_id', $azureDevice->azure_device_id)
                                ->first();

                if ($device) {
                    // Just link it and we're done
                    $azureDevice->update([
                        'device_id'   => $device->id,
                        'link_status' => 'linked',
                    ]);
                } else {
                    // 2. Create or Find the Device Model
                    $deviceModel = \App\Models\DeviceModel::firstOrCreate(
                        [
                            'manufacturer' => $azureDevice->manufacturer ?? 'Unknown', 
                            'name'         => $azureDevice->model ?? 'Common Model'
                        ],
                        [
                            'device_type'  => $request->type
                        ]
                    );

                    // 3. Create the Device
                    $device = Device::create([
                        'type'                => $request->type,
                        'name'                => $azureDevice->display_name,
                        'manufacturer'        => $azureDevice->manufacturer,
                        'model'               => $azureDevice->model,
                        'device_model_id'     => $deviceModel->id,
                        'serial_number'       => $azureDevice->serial_number,
                        'asset_code'          => $request->asset_code,
                        'status'              => 'active',
                        'source'              => 'azure',
                        'source_id'           => $azureDevice->azure_device_id,
                        'branch_id'           => $this->detectBranchId($azureDevice),
                        'purchase_date'       => $enrollDate,
                        'warranty_expiry'     => (clone $enrollDate)->addYear(),
                        'depreciation_years'  => 3,
                        'depreciation_method' => 'straight_line',
                        'notes'               => "Imported from Azure/Intune sync on " . now()->toDateTimeString(),
                    ]);

                    // 4. Link AzureDevice
                    $azureDevice->update([
                        'device_id'   => $device->id,
                        'link_status' => 'linked',
                    ]);
                }

                // 5. Assign to Employee if UPN matches
                $employee = $this->findEmployeeByUpn($azureDevice->upn);
                if ($employee) {
                    \App\Models\EmployeeAsset::updateOrCreate(
                        ['employee_id' => $employee->id, 'asset_id' => $device->id],
                        [
                            'assigned_date' => now(),
                            'condition'     => 'used',
                            'notes'         => 'Assigned during Azure import.',
                        ]
                    );

                    $device->update(['status' => 'assigned']);
                }

                AssetHistory::record($device, 'assigned', "Imported from Azure Sync. Assigned to user: " . ($employee->name ?? 'None'));
                if (class_exists('App\Models\ActivityLog')) {
                    \App\Models\ActivityLog::log("Imported Azure device {$azureDevice->display_name} as asset {$device->asset_code}");
                }
            });

            return back()->with('success', "Device successfully imported/linked.");
        } catch (\Throwable $e) {
            return back()->with('error', 'Import failed: ' . $e->getMessage());
        }
    }

    /**
     * Batch import multiple devices at once.
     */
    public function batchImport(Request $request)
    {
        $ids = $request->input('ids');
        if (empty($ids) || !is_array($ids)) {
            return back()->with('error', 'No devices selected for batch import.');
        }

        $successCount = 0;
        $errorCount   = 0;
        $codeService  = new \App\Services\AssetCodeService();

        foreach ($ids as $id) {
            try {
                $azureDevice = AzureDevice::find($id);
                if (!$azureDevice || $azureDevice->link_status === 'linked') continue;

                \Illuminate\Support\Facades\DB::transaction(function () use ($azureDevice, $codeService, &$successCount) {
                    $type = $this->guessDeviceType($azureDevice);
                    
                    // Enrollment date is treated as purchase date
                    $enrollDate = $azureDevice->enrolled_date ? \Carbon\Carbon::parse($azureDevice->enrolled_date) : now();

                    // 1. Check if already exists in devices table
                    $device = Device::where('source', 'azure')
                                    ->where('source_id', $azureDevice->azure_device_id)
                                    ->first();

                    if ($device) {
                        $azureDevice->update([
                            'device_id'   => $device->id,
                            'link_status' => 'linked',
                        ]);
                    } else {
                        $code = $codeService->generate($type);

                        // 2. Create or Find the Device Model
                        $deviceModel = \App\Models\DeviceModel::firstOrCreate(
                            [
                                'manufacturer' => $azureDevice->manufacturer ?? 'Unknown', 
                                'name'         => $azureDevice->model ?? 'Common Model'
                            ],
                            [
                                'device_type'  => $type
                            ]
                        );

                        // 3. Create the Device
                        $device = Device::create([
                            'type'                => $type,
                            'name'                => $azureDevice->display_name,
                            'manufacturer'        => $azureDevice->manufacturer,
                            'model'               => $azureDevice->model,
                            'device_model_id'     => $deviceModel->id,
                            'serial_number'       => $azureDevice->serial_number,
                            'asset_code'          => $code,
                            'status'              => 'active',
                            'source'              => 'azure',
                            'source_id'           => $azureDevice->azure_device_id,
                            'branch_id'           => $this->detectBranchId($azureDevice),
                            'purchase_date'       => $enrollDate,
                            'warranty_expiry'     => (clone $enrollDate)->addYear(),
                            'depreciation_years'  => 3,
                            'depreciation_method' => 'straight_line',
                            'notes'               => "Batch imported from Azure/Intune sync on " . now()->toDateTimeString(),
                        ]);

                        // 4. Link AzureDevice
                        $azureDevice->update([
                            'device_id'   => $device->id,
                            'link_status' => 'linked',
                        ]);
                    }

                    // 5. Assign to Employee
                    $employee = $this->findEmployeeByUpn($azureDevice->upn);
                    if ($employee) {
                        \App\Models\EmployeeAsset::updateOrCreate(
                            ['employee_id' => $employee->id, 'asset_id' => $device->id],
                            [
                                'assigned_date' => now(),
                                'condition'     => 'used',
                                'notes'         => 'Assigned during batch import.',
                            ]
                        );
                        $device->update(['status' => 'assigned']);
                    }

                    AssetHistory::record($device, 'assigned', "Imported from Azure Sync. Assigned to user: " . ($employee->name ?? 'None'));
                    if (class_exists('App\Models\ActivityLog')) {
                        \App\Models\ActivityLog::log("Imported Azure device {$azureDevice->display_name} as asset {$device->asset_code}");
                    }
                    $successCount++;
                });
            } catch (\Throwable $e) {
                \Log::error("Batch import failed for Azure Device ID {$id}: " . $e->getMessage());
                $errorCount++;
            }
        }

        ActivityLog::log("Performed batch import of {$successCount} Azure devices to itam.");

        $msg = "Successfully imported {$successCount} devices.";
        if ($errorCount > 0) $msg .= " Failed to import {$errorCount} devices. Check logs for details.";

        return back()->with($errorCount > 0 ? 'warning' : 'success', $msg);
    }

    private function guessDeviceType(AzureDevice $az): string
    {
        return 'laptop';
    }

    /**
     * Find the Employee who owns this Azure device.
     *
     * Lookup chain (first match wins):
     *   1. Employee.email = upn                        (exact match, works when domains align)
     *   2. IdentityUser.user_principal_name = upn      (synced from Entra ID)
     *      → Employee.azure_id = IdentityUser.azure_id (linked via shared Azure object ID)
     *   3. IdentityUser.mail = upn                     (proxy / alias address)
     *      → Employee.email   = IdentityUser.mail
     */
    /**
     * Fetch and apply hardware/network data for a single device from Intune.
     * Calls the Graph API run-state endpoint directly for this device's Intune ID,
     * parses the JSON result and saves it — same logic as the artisan command.
     */
    public function syncHwData(AzureDevice $azureDevice)
    {
        $scriptId = \App\Models\Setting::get()->intune_net_data_script_id ?? null;
        if (! $scriptId) {
            return back()->with('error', 'Script ID not configured. Set intune_net_data_script_id in Settings → Graph.');
        }

        $managedId = $azureDevice->intune_managed_device_id;
        if (! $managedId) {
            return back()->with('error', 'Device has no Intune Managed Device ID yet. Run itam:sync-devices first.');
        }

        try {
            $graph    = new \App\Services\Identity\GraphService();
            $state    = $graph->getScriptRunState($scriptId, $managedId);

            if (! $state) {
                return back()->with('error', 'No script run state found for this device. Device may not have run the script yet.');
            }

            $runState = $state['runState'] ?? 'unknown';
            if ($runState !== 'success') {
                return back()->with('error', "Script run state is '{$runState}' — device hasn't returned data yet.");
            }

            // Parse JSON from script stdout
            $raw  = trim(preg_replace('/^\xEF\xBB\xBF/', '', $state['resultMessage'] ?? ''));
            $data = json_decode($raw, true);
            if (! is_array($data)) {
                return back()->with('error', 'Script result is not valid JSON.');
            }

            // Normalize MACs
            $normMac = fn(?string $m) => $m
                ? strtoupper(implode(':', str_split(preg_replace('/[^a-fA-F0-9]/', '', $m), 2)))
                : null;

            $cpuName     = $data['cpu']          ?? $data['cpu_name']     ?? null;
            $wifiMac     = $normMac($data['wifi_mac']     ?? null);
            $ethernetMac = $normMac($data['ethernet_mac'] ?? null);
            $usbEthRaw   = $data['usb_eth']       ?? $data['usb_eth_adapters'] ?? null;
            $usbEthJson  = (!empty($usbEthRaw) && is_array($usbEthRaw)) ? json_encode($usbEthRaw) : null;

            $azureDevice->update([
                'teamviewer_id'      => $data['teamviewer_id'] ?? $azureDevice->teamviewer_id,
                'tv_version'         => $data['tv_version']    ?? $azureDevice->tv_version,
                'cpu_name'           => $cpuName               ?? $azureDevice->cpu_name,
                'wifi_mac'           => $wifiMac               ?? $azureDevice->wifi_mac,
                'ethernet_mac'       => $ethernetMac           ?? $azureDevice->ethernet_mac,
                'usb_eth_data'       => $usbEthJson            ?? $azureDevice->usb_eth_data,
                'net_data_synced_at' => now(),
            ]);

            // Sync MACs to device_macs registry
            if ($ethernetMac) {
                \App\Models\DeviceMac::upsertMac($ethernetMac, [
                    'adapter_type'    => 'ethernet',
                    'adapter_name'    => 'Ethernet',
                    'azure_device_id' => $azureDevice->id,
                    'device_id'       => $azureDevice->device_id,
                    'source'          => 'intune',
                    'is_primary'      => true,
                ]);
            }
            if ($wifiMac) {
                \App\Models\DeviceMac::upsertMac($wifiMac, [
                    'adapter_type'    => 'wifi',
                    'adapter_name'    => 'Wi-Fi',
                    'azure_device_id' => $azureDevice->id,
                    'device_id'       => $azureDevice->device_id,
                    'source'          => 'intune',
                    'is_primary'      => false,
                ]);
            }
            foreach (json_decode($usbEthJson ?? '[]', true) as $usb) {
                $usbMac = $normMac($usb['mac'] ?? null);
                if ($usbMac) {
                    \App\Models\DeviceMac::upsertMac($usbMac, [
                        'adapter_type'        => 'usb_ethernet',
                        'adapter_name'        => $usb['name'] ?? 'USB LAN',
                        'adapter_description' => $usb['desc'] ?? null,
                        'azure_device_id'     => $azureDevice->id,
                        'device_id'           => $azureDevice->device_id,
                        'source'              => 'intune',
                        'is_primary'          => false,
                    ]);
                }
            }

            // Propagate MACs to the linked ITAM device record
            // so the asset profile page shows them without loading azure_device relation
            if ($azureDevice->device_id && ($ethernetMac || $wifiMac)) {
                $itamUpdate = [];
                if ($ethernetMac) $itamUpdate['mac_address'] = $ethernetMac;
                if ($wifiMac)     $itamUpdate['wifi_mac']    = $wifiMac;
                \App\Models\Device::where('id', $azureDevice->device_id)->update($itamUpdate);
            }

            ActivityLog::log("Intune HW sync for {$azureDevice->display_name}: CPU={$cpuName} TV={$data['teamviewer_id']}");

            return back()->with('success', "Hardware data synced for {$azureDevice->display_name}.");

        } catch (\Throwable $e) {
            return back()->with('error', 'Sync failed: ' . $e->getMessage());
        }
    }

    private function findEmployeeByUpn(?string $upn): ?\App\Models\Employee
    {
        if (empty($upn)) return null;

        // 1. Direct email match
        $employee = \App\Models\Employee::where('email', $upn)->first();
        if ($employee) return $employee;

        // 2 & 3. Via IdentityUser (handles UPN ≠ email, e.g. @tenant.onmicrosoft.com vs @company.com)
        $identityUser = \App\Models\IdentityUser::where('user_principal_name', $upn)
            ->orWhere('mail', $upn)
            ->first();

        if (! $identityUser) return null;

        // Try linking by shared azure_id first
        if ($identityUser->azure_id) {
            $employee = \App\Models\Employee::where('azure_id', $identityUser->azure_id)->first();
            if ($employee) return $employee;
        }

        // Fall back to matching the synced mail address
        if ($identityUser->mail) {
            $employee = \App\Models\Employee::where('email', $identityUser->mail)->first();
            if ($employee) return $employee;
        }

        return null;
    }

    // --- Branch Mapping Management ---

    public function mappings()
    {
        $mappings = AzureBranchMapping::with('branch')->orderBy('keyword')->get();
        $branches = Branch::orderBy('name')->get();
        return view('admin.itam.azure.mappings', compact('mappings', 'branches'));
    }

    public function storeMapping(Request $request)
    {
        $request->validate([
            'keyword'   => 'required|string|max:100',
            'branch_id' => 'required|exists:branches,id',
        ]);

        AzureBranchMapping::updateOrCreate(
            ['keyword' => $request->keyword],
            ['branch_id' => $request->branch_id]
        );

        return back()->with('success', 'Mapping saved.');
    }

    public function deleteMapping(AzureBranchMapping $mapping)
    {
        $mapping->delete();
        return back()->with('success', 'Mapping removed.');
    }

    private function detectBranchId(AzureDevice $az): ?int
    {
        $mappings = AzureBranchMapping::all();
        if ($mappings->isEmpty()) return null;

        // Collect all possible strings to search in
        $searchStrings = [];
        
        // From Device Data
        if ($az->display_name) $searchStrings[] = $az->display_name;
        if (!empty($az->raw_data['officeLocation'])) $searchStrings[] = $az->raw_data['officeLocation'];
        if (!empty($az->raw_data['location'])) $searchStrings[] = $az->raw_data['location'];
        
        // From Associated User Data (Most reliable for branch/office)
        if ($az->upn) {
            $user = \App\Models\IdentityUser::where('user_principal_name', $az->upn)
                ->orWhere('mail', $az->upn)
                ->first();
            
            if ($user) {
                if ($user->office_location) $searchStrings[] = $user->office_location;
                if ($user->city) $searchStrings[] = $user->city;
                if ($user->department) $searchStrings[] = $user->department;
            }
        }

        foreach ($searchStrings as $str) {
            if (!$str) continue;
            foreach ($mappings as $m) {
                if (stripos($str, $m->keyword) !== false) {
                    return $m->branch_id;
                }
            }
        }

        return null;
    }

    public function reDetectBranch(AzureDevice $azureDevice)
    {
        $branchId = $this->detectBranchId($azureDevice);
        if (!$branchId) {
            return back()->with('error', 'Could not detect a matching branch based on current keywords.');
        }

        if (!$azureDevice->device_id) {
            return back()->with('error', 'This device is not linked to an ITAM asset yet.');
        }

        $device = Device::find($azureDevice->device_id);
        if ($device) {
            $device->update(['branch_id' => $branchId]);
            $branch = Branch::find($branchId);
            return back()->with('success', "Branch updated to: " . ($branch->name ?? 'Unknown'));
        }

        return back()->with('error', 'Linked ITAM asset not found.');
    }

    /**
     * Bulk update branches for all linked Azure devices based on current mappings.
     */
    public function bulkSyncBranches()
    {
        $linked = AzureDevice::where('link_status', 'linked')->whereNotNull('device_id')->get();
        $updated = 0;

        foreach ($linked as $az) {
            $branchId = $this->detectBranchId($az);
            if ($branchId) {
                $device = Device::find($az->device_id);
                if ($device && $device->branch_id != $branchId) {
                    $device->update(['branch_id' => $branchId]);
                    $updated++;
                }
            }
        }

        return back()->with('success', "Branch sync complete. Updated {$updated} devices based on mappings.");
    }
}
