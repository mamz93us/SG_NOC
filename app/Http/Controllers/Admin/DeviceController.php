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
        $query = Device::with(['branch', 'credentials', 'currentAssignment.employee', 'deviceModel'])
                    ->orderBy('type')
                    ->orderBy('name');

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

        $devices  = $query->paginate(50)->withQueryString();
        $branches = Branch::orderBy('name')->get(['id', 'name']);
        $types    = ['ucm', 'switch', 'router', 'firewall', 'ap', 'printer', 'server',
                     'laptop', 'desktop', 'monitor', 'keyboard', 'mouse', 'headset', 'tablet', 'other'];

        return view('admin.devices.index', compact('devices', 'branches', 'types'));
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
            'azureDevice', 'currentAssignment.employee',
        ]);
        $depreciation = new DepreciationService();
        $employees    = Employee::orderBy('name')->get(['id', 'name', 'employee_id']);
        return view('admin.devices.show', compact('device', 'depreciation', 'employees'));
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
            'type'                 => 'required|in:ucm,switch,router,firewall,ap,printer,server,laptop,desktop,monitor,keyboard,mouse,headset,tablet,other',
            'name'                 => 'required|string|max:255',
            'device_model_id'      => 'nullable|exists:device_models,id',
            'serial_number'        => 'nullable|string|max:100',
            'mac_address'          => 'nullable|string|max:20',
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

        $device = Device::create(array_merge($data, ['source' => 'manual']));

        // Calculate initial depreciation value
        if ($device->purchase_cost && $device->depreciation_method === 'straight_line') {
            $device->update(['current_value' => (new DepreciationService())->currentValue($device)]);
        }

        // Log asset history
        AssetHistory::record($device, 'created', "Device created: {$device->name}");
        ActivityLog::log('created', $device, $data);

        return redirect()->route('admin.devices.show', $device)
                         ->with('success', "Device \"{$device->name}\" created.");
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
            'type'                 => 'required|in:ucm,switch,router,firewall,ap,printer,server,laptop,desktop,monitor,keyboard,mouse,headset,tablet,other',
            'name'                 => 'required|string|max:255',
            'device_model_id'      => 'nullable|exists:device_models,id',
            'serial_number'        => 'nullable|string|max:100',
            'mac_address'          => 'nullable|string|max:20',
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
        ]);

        $device->update($data);

        ActivityLog::log('updated', $device, $data);

        return back()->with('success', "Device \"{$device->name}\" updated.");
    }

    public function destroy(Device $device)
    {
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
