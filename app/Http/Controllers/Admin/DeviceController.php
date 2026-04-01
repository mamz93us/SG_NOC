<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AssetHistory;
use App\Models\Branch;
use App\Models\Credential;
use App\Models\Department;
use App\Models\Device;
use App\Models\DeviceModel;
use App\Models\Employee;
use App\Models\EmployeeAsset;
use App\Models\License;
use App\Models\LicenseAssignment;
use App\Models\Supplier;
use App\Services\AssetCodeService;
use App\Services\DepreciationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DeviceController extends Controller
{
    public function index(Request $request)
    {
        $allowed = ['asset_code', 'status', 'type', 'name', 'manufacturer', 'model', 'updated_at'];
        $sort    = in_array($request->sort, $allowed) ? $request->sort : null;
        $dir     = $request->direction === 'asc' ? 'asc' : 'desc';

        $query = Device::with(['branch', 'credentials', 'currentAssignment.employee', 'deviceModel']);

        if ($sort) {
            $query->orderBy($sort, $dir);
        } else {
            $query->orderBy('type')->orderBy('name');
        }

        if ($request->filled('branch')) {
            $query->where('branch_id', $request->branch);
        }
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('name',          'like', "%{$s}%")
                  ->orWhere('ip_address',  'like', "%{$s}%")
                  ->orWhere('mac_address', 'like', "%{$s}%")
                  ->orWhere('serial_number','like',"%{$s}%")
                  ->orWhere('asset_code',  'like', "%{$s}%");
            });
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('model_id')) {
            $query->where('device_model_id', $request->model_id);
        }

        $devices   = $query->paginate(50)->withQueryString();
        $branches  = Branch::orderBy('name')->get(['id', 'name']);
        $employees = Employee::orderBy('name')->get(['id', 'name']);
        $types     = \App\Models\AssetType::allSlugs();

        return view('admin.devices.index', compact('devices', 'branches', 'employees', 'types'));
    }

    public function firmware(Request $request)
    {
        $query = Device::with('branch');

        if ($request->filled('status')) {
            switch ($request->status) {
                case 'outdated':
                    $query->whereNotNull('firmware_version')->whereNotNull('latest_firmware')
                          ->whereColumn('firmware_version', '!=', 'latest_firmware');
                    break;
                case 'current':
                    $query->whereNotNull('firmware_version')->whereNotNull('latest_firmware')
                          ->whereColumn('firmware_version', '=', 'latest_firmware');
                    break;
                case 'unknown':
                    $query->where(fn($q) => $q->whereNull('firmware_version')->orWhereNull('latest_firmware'));
                    break;
            }
        }

        if ($request->filled('branch')) {
            $query->where('branch_id', $request->branch);
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn($q) => $q->where('name', 'like', "%{$s}%")->orWhere('model', 'like', "%{$s}%"));
        }

        $devices = $query->orderBy('name')->paginate(25)->withQueryString();
        $branches = Branch::orderBy('name')->get();

        $outdatedCount = Device::whereNotNull('firmware_version')->whereNotNull('latest_firmware')
            ->whereColumn('firmware_version', '!=', 'latest_firmware')->count();
        $uptodateCount = Device::whereNotNull('firmware_version')->whereNotNull('latest_firmware')
            ->whereColumn('firmware_version', '=', 'latest_firmware')->count();
        $unknownCount = Device::where(fn($q) => $q->whereNull('firmware_version')->orWhereNull('latest_firmware'))->count();

        return view('admin.devices.firmware', compact(
            'devices', 'branches', 'outdatedCount', 'uptodateCount', 'unknownCount'
        ));
    }

    public function show(Device $device)
    {
        $device->load([
            'branch', 'floor', 'office', 'department',
            'credentials.creator', 'printer', 'deviceModel',
            'supplier', 'assetHistory.user', 'licenseAssignments.license',
            'azureDevice.macs', 'currentAssignment.employee',
        ]);
        $depreciation = new DepreciationService();
        $employees    = Employee::orderBy('name')->get(['id', 'name']);

        // Access panel data
        $sshSessions = $device->sshSessions()
            ->with('user')
            ->latest('started_at')
            ->limit(15)
            ->get();

        $accessLogs = $device->accessLogs()
            ->with('user')
            ->limit(30)
            ->get();

        // Resolve IP from DHCP leases using all known MACs (device + azure device adapters)
        $dhcpLease  = null;
        $rawMacs    = array_filter([$device->mac_address, $device->wifi_mac]);
        $az         = $device->azureDevice;
        if ($az) {
            if ($az->ethernet_mac) $rawMacs[] = $az->ethernet_mac;
            if ($az->wifi_mac)     $rawMacs[] = $az->wifi_mac;
            foreach ($az->usb_eth_decoded() as $usb) {
                if (!empty($usb['mac'])) $rawMacs[] = $usb['mac'];
            }
        }
        $normMacs = array_values(array_unique(array_filter(
            array_map(fn($m) => strtoupper(preg_replace('/[^a-fA-F0-9]/', '', $m)), $rawMacs)
        )));
        if (!empty($normMacs)) {
            $dhcpLease = \App\Models\DhcpLease::whereRaw(
                'UPPER(REPLACE(REPLACE(mac_address,\':\',\'\'),\'-\',\'\')) IN (' .
                implode(',', array_fill(0, count($normMacs), '?')) . ')',
                $normMacs
            )->latest('last_seen')->first();
        }

        return view('admin.devices.show', compact(
            'device', 'depreciation', 'employees',
            'sshSessions', 'accessLogs', 'dhcpLease'
        ));
    }

    /**
     * AJAX: look up a DHCP lease by MAC or IP.
     * GET /admin/devices/dhcp-lookup?mac=AA:BB:CC:DD:EE:FF
     * GET /admin/devices/dhcp-lookup?ip=192.168.1.10
     * Returns: {ip, mac, hostname, source, last_seen}
     */
    public function dhcpLookup(\Illuminate\Http\Request $request)
    {
        $mac = $request->get('mac');
        $ip  = $request->get('ip');

        if ($mac) {
            $normMac = strtoupper(preg_replace('/[^a-fA-F0-9]/', '', $mac));
            if (strlen($normMac) !== 12) return response()->json(['error' => 'Invalid MAC'], 422);
            $lease = \App\Models\DhcpLease::whereRaw(
                'UPPER(REPLACE(REPLACE(mac_address,\':\',\'\'),\'-\',\'\')) = ?', [$normMac]
            )->latest('last_seen')->first();
        } elseif ($ip) {
            $lease = \App\Models\DhcpLease::where('ip_address', $ip)->latest('last_seen')->first();
        } else {
            return response()->json(['error' => 'Provide mac or ip'], 422);
        }

        if (! $lease) return response()->json(null);

        return response()->json([
            'ip'        => $lease->ip_address,
            'mac'       => strtoupper(str_replace(['-', ':'], '', $lease->mac_address)),
            'mac_fmt'   => strtoupper(implode(':', str_split(
                strtoupper(preg_replace('/[^a-fA-F0-9]/', '', $lease->mac_address)), 2
            ))),
            'hostname'  => $lease->hostname,
            'source'    => $lease->source,
            'last_seen' => $lease->last_seen?->diffForHumans(),
        ]);
    }

    public function create()
    {
        $branches     = Branch::orderBy('name')->get(['id', 'name']);
        $departments  = Department::orderBy('sort_order')->orderBy('name')->get(['id', 'name']);
        $deviceModels = DeviceModel::orderBy('name')->get(['id', 'name', 'manufacturer', 'device_type']);
        $suppliers    = Supplier::orderBy('name')->get(['id', 'name']);
        return view('admin.devices.form', compact('branches', 'departments', 'deviceModels', 'suppliers'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'type'                 => 'required|in:' . implode(',', \App\Models\AssetType::allSlugs()),
            'name'                 => 'required|string|max:255',
            'device_model_id'      => 'nullable|exists:device_models,id',
            'serial_number'        => 'nullable|string|max:100',
            'mac_address'          => 'nullable|string|max:20',
            'wifi_mac'             => 'nullable|string|max:17',
            'ip_address'           => 'nullable|ip',
            'branch_id'            => 'nullable|exists:branches,id',
            'floor_id'             => 'nullable|exists:network_floors,id',
            'office_id'            => 'nullable|exists:network_offices,id',
            'department_id'        => 'nullable|exists:departments,id',
            'location_description' => 'nullable|string|max:255',
            'notes'                => 'nullable|string',
            'status'               => 'required|in:active,available,assigned,maintenance,retired',
            'purchase_date'        => 'nullable|date',
            'warranty_expiry'      => 'nullable|date',
            // ITAM fields
            'asset_code'           => 'nullable|string|max:50|unique:devices,asset_code',
            'purchase_cost'        => 'nullable|numeric|min:0',
            'supplier_id'          => 'nullable|exists:suppliers,id',
            'condition'            => 'nullable|in:new,used,refurbished,damaged',
            'depreciation_method'  => 'nullable|in:straight_line,none',
            'depreciation_years'   => 'nullable|integer|min:1|max:30',
        ]);

        // Auto-generate asset_code if blank
        if (empty($data['asset_code'])) {
            try {
                $data['asset_code'] = (new AssetCodeService())->generate($data['type']);
            } catch (\Throwable) {
                // Non-fatal — leave null if service fails (e.g. settings not yet configured)
            }
        }

        // Auto-calculate WiFi MAC for IP phones (LAN MAC last byte + 1)
        if ($data['type'] === 'phone' && !empty($data['mac_address']) && empty($data['wifi_mac'])) {
            $data['wifi_mac'] = Device::calculatePhoneWifiMac($data['mac_address']);
        }

        // Sync manufacturer/model strings from DeviceModel for backward compatibility
        if (!empty($data['device_model_id'])) {
            $dm = DeviceModel::find($data['device_model_id']);
            if ($dm) {
                $data['manufacturer'] = $dm->manufacturer;
                $data['model']        = $dm->name;
            }
        }

        $device = Device::create(array_merge($data, ['source' => 'manual']));

        // Calculate initial depreciation value
        if ($device->purchase_cost && $device->depreciation_method === 'straight_line') {
            $device->update(['current_value' => (new DepreciationService())->currentValue($device)]);
        }

        // Register phone MACs in the central device_macs registry for RADIUS
        if ($device->type === 'phone') {
            if ($device->mac_address) {
                \App\Models\DeviceMac::upsertMac($device->mac_address, [
                    'adapter_type' => 'ethernet',
                    'adapter_name' => 'LAN',
                    'device_id'    => $device->id,
                    'source'       => 'manual',
                    'is_primary'   => true,
                ]);
            }
            if ($device->wifi_mac) {
                \App\Models\DeviceMac::upsertMac($device->wifi_mac, [
                    'adapter_type' => 'wifi',
                    'adapter_name' => 'WiFi',
                    'device_id'    => $device->id,
                    'source'       => 'manual',
                    'is_primary'   => false,
                ]);
            }
        }

        // Log asset history
        AssetHistory::record($device, 'created', "Device created: {$device->name}");
        ActivityLog::log('created', $device, $data);

        return redirect()->route('admin.devices.show', $device)
                         ->with('success', "Device \"{$device->name}\" created.");
    }

    public function batchCreate()
    {
        $branches     = Branch::orderBy('name')->get(['id', 'name']);
        $deviceModels = DeviceModel::orderBy('name')->get(['id', 'name', 'manufacturer', 'device_type']);
        $suppliers    = Supplier::orderBy('name')->get(['id', 'name']);
        return view('admin.devices.batch', compact('branches', 'deviceModels', 'suppliers'));
    }

    public function batchStore(Request $request)
    {
        $request->validate([
            'type'            => 'required|string',
            'prefix'          => 'required|string|max:50',
            'device_model_id' => 'nullable|exists:device_models,id',
            'branch_id'       => 'nullable|exists:branches,id',
            'status'          => 'required|string',
            'serials'         => 'required|string', // newline separated
            'purchase_date'   => 'nullable|date',
            'purchase_cost'   => 'nullable|numeric',
            'supplier_id'     => 'nullable|exists:suppliers,id',
            'condition'       => 'required|string',
        ]);

        $serials = array_filter(array_map('trim', explode("\n", $request->serials)));
        $count   = 0;
        $errors  = [];

        foreach ($serials as $index => $sn) {
            if (empty($sn)) continue;

            // Check if SN already exists
            if (Device::where('serial_number', $sn)->exists()) {
                $errors[] = "Serial number \"{$sn}\" already exists. Skipped.";
                continue;
            }

            $name = $request->prefix . ' ' . ($index + 1);
            $type = $request->type;

            $data = [
                'type'            => $type,
                'name'            => $name,
                'device_model_id' => $request->device_model_id,
                'serial_number'   => $sn,
                'branch_id'       => $request->branch_id,
                'status'          => $request->status,
                'purchase_date'   => $request->purchase_date,
                'purchase_cost'   => $request->purchase_cost,
                'supplier_id'     => $request->supplier_id,
                'condition'       => $request->condition,
                'source'          => 'manual',
            ];

            // Sync manufacturer/model strings from DeviceModel
            if (!empty($data['device_model_id'])) {
                $dm = DeviceModel::find($data['device_model_id']);
                if ($dm) {
                    $data['manufacturer'] = $dm->manufacturer;
                    $data['model']        = $dm->name;
                }
            }

            // Auto-generate asset_code
            try {
                $data['asset_code'] = (new AssetCodeService())->generate($type);
            } catch (\Throwable) {}

            $device = Device::create($data);
            AssetHistory::record($device, 'created', "Batch created device: {$device->name}");
            $count++;
        }

        $msg = "Successfully added {$count} devices.";
        if (!empty($errors)) {
            $msg .= " " . implode(" ", $errors);
        }

        return redirect()->route('admin.devices.index')->with($count > 0 ? 'success' : 'error', $msg);
    }

    public function edit(Device $device)
    {
        $branches     = Branch::orderBy('name')->get(['id', 'name']);
        $departments  = Department::orderBy('sort_order')->orderBy('name')->get(['id', 'name']);
        $deviceModels = DeviceModel::orderBy('name')->get(['id', 'name', 'manufacturer', 'device_type']);
        $suppliers    = Supplier::orderBy('name')->get(['id', 'name']);
        $device->load(['floor', 'office']);
        return view('admin.devices.form', compact('device', 'branches', 'departments', 'deviceModels', 'suppliers'));
    }

    public function update(Request $request, Device $device)
    {
        $data = $request->validate([
            'type'                 => 'required|in:' . implode(',', \App\Models\AssetType::allSlugs()),
            'name'                 => 'required|string|max:255',
            'device_model_id'      => 'nullable|exists:device_models,id',
            'serial_number'        => 'nullable|string|max:100',
            'mac_address'          => 'nullable|string|max:20',
            'wifi_mac'             => 'nullable|string|max:17',
            'ip_address'           => 'nullable|ip',
            'branch_id'            => 'nullable|exists:branches,id',
            'floor_id'             => 'nullable|exists:network_floors,id',
            'office_id'            => 'nullable|exists:network_offices,id',
            'department_id'        => 'nullable|exists:departments,id',
            'location_description' => 'nullable|string|max:255',
            'notes'                => 'nullable|string',
            'status'               => 'required|in:active,available,assigned,maintenance,retired',
            'purchase_date'        => 'nullable|date',
            'warranty_expiry'      => 'nullable|date',
            // ITAM fields
            'asset_code'           => 'nullable|string|max:50|unique:devices,asset_code,' . $device->id,
            'purchase_cost'        => 'nullable|numeric|min:0',
            'supplier_id'          => 'nullable|exists:suppliers,id',
            'condition'            => 'nullable|in:new,used,refurbished,damaged',
            'depreciation_method'  => 'nullable|in:straight_line,none',
            'depreciation_years'   => 'nullable|integer|min:1|max:30',
        ]);

        // Auto-calculate WiFi MAC for IP phones when LAN MAC changes
        if ($data['type'] === 'phone' && !empty($data['mac_address']) && empty($data['wifi_mac'])) {
            $data['wifi_mac'] = Device::calculatePhoneWifiMac($data['mac_address']);
        }

        // Sync manufacturer/model strings from DeviceModel
        if (!empty($data['device_model_id'])) {
            $dm = DeviceModel::find($data['device_model_id']);
            if ($dm) {
                $data['manufacturer'] = $dm->manufacturer;
                $data['model']        = $dm->name;
            }
        }

        $device->update($data);

        // Update device_macs registry when phone MACs change
        if ($device->type === 'phone') {
            if ($device->mac_address) {
                \App\Models\DeviceMac::upsertMac($device->mac_address, [
                    'adapter_type' => 'ethernet',
                    'adapter_name' => 'LAN',
                    'device_id'    => $device->id,
                    'source'       => 'manual',
                    'is_primary'   => true,
                ]);
            }
            if ($device->wifi_mac) {
                \App\Models\DeviceMac::upsertMac($device->wifi_mac, [
                    'adapter_type' => 'wifi',
                    'adapter_name' => 'WiFi',
                    'device_id'    => $device->id,
                    'source'       => 'manual',
                    'is_primary'   => false,
                ]);
            }
        }

        // Recalculate depreciation if cost or method changed
        if ($device->purchase_cost && $device->depreciation_method === 'straight_line') {
            $device->update(['current_value' => (new DepreciationService())->currentValue($device)]);
        }

        ActivityLog::log('updated', $device, $data);

        return back()->with('success', "Device \"{$device->name}\" updated.");
    }

    public function destroy(Device $device)
    {
        // Block deletion if device is currently assigned to someone
        $activeAssignment = EmployeeAsset::where('asset_id', $device->id)
            ->whereNull('returned_date')
            ->with('employee')
            ->first();

        if ($activeAssignment) {
            $assigneeName = $activeAssignment->employee?->name ?? 'an employee';
            return back()->with('error',
                "Cannot delete \"{$device->name}\" — it is currently assigned to {$assigneeName}. Return the device first."
            );
        }

        // Block if device has active accessory assignments
        $activeAccessories = \App\Models\AccessoryAssignment::where('device_id', $device->id)
            ->whereNull('returned_date')
            ->exists();

        if ($activeAccessories) {
            return back()->with('error',
                "Cannot delete \"{$device->name}\" — it has active accessory assignments. Return accessories first."
            );
        }

        // Block if device has active license assignments
        $activeLicenses = LicenseAssignment::where('assignable_type', Device::class)
            ->where('assignable_id', $device->id)
            ->exists();

        if ($activeLicenses) {
            return back()->with('error',
                "Cannot delete \"{$device->name}\" — it has active license assignments. Unassign licenses first."
            );
        }

        $name = $device->name;
        ActivityLog::log('deleted device: ' . $name, $device);
        $device->delete();

        return redirect()->route('admin.devices.index')
                         ->with('success', "Device \"{$name}\" deleted.");
    }

    public function label(Device $device)
    {
        return view('admin.devices.label', compact('device'));
    }

    public function scan()
    {
        return view('admin.devices.scan');
    }

    public function quickAssign(Request $request, Device $device)
    {
        $data = $request->validate([
            'employee_id'   => 'required|exists:employees,id',
            'assigned_date' => 'required|date',
            'condition'     => 'required|in:good,fair,poor',
            'notes'         => 'nullable|string|max:500',
        ]);

        $existing = EmployeeAsset::where('asset_id', $device->id)->whereNull('returned_date')->first();
        if ($existing) {
            return back()->with('error', 'Device is already assigned.');
        }

        EmployeeAsset::create([
            'employee_id'   => $data['employee_id'],
            'asset_id'      => $device->id,
            'assigned_date' => $data['assigned_date'],
            'condition'     => $data['condition'],
            'notes'         => $data['notes'] ?? null,
        ]);
        $device->update(['status' => 'assigned']);
        AssetHistory::record($device, 'assigned', "Assigned to employee ID {$data['employee_id']}");

        return back()->with('success', 'Device assigned successfully.');
    }

    public function quickReturn(Request $request, Device $device)
    {
        $data = $request->validate([
            'returned_date' => 'required|date',
            'condition'     => 'required|in:good,fair,poor',
            'notes'         => 'nullable|string|max:500',
        ]);

        $assignment = EmployeeAsset::where('asset_id', $device->id)->whereNull('returned_date')->firstOrFail();
        $assignment->update([
            'returned_date' => $data['returned_date'],
            'condition'     => $data['condition'],
            'notes'         => $data['notes'] ?? null,
        ]);
        $device->update(['status' => 'available']);
        AssetHistory::record($device, 'returned', "Returned from employee {$assignment->employee?->name}");

        return back()->with('success', 'Device returned successfully.');
    }

    public function generateCode(Request $request)
    {
        $type = $request->query('type', 'other');
        try {
            $code = (new AssetCodeService())->generate($type);
            return response()->json(['code' => $code]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
