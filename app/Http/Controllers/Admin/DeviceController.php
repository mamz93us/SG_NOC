<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Credential;
use App\Models\Department;
use App\Models\Device;
use App\Models\DeviceModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DeviceController extends Controller
{
    public function index(Request $request)
    {
        $query = Device::with(['branch', 'credentials'])
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
                  ->orWhere('serial_number','like',"%{$s}%");
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
        $device->load(['branch', 'credentials.creator', 'printer']);
        return view('admin.devices.show', compact('device'));
    }

    public function create()
    {
        $branches     = Branch::orderBy('name')->get(['id', 'name']);
        $departments  = Department::orderBy('sort_order')->orderBy('name')->get(['id', 'name']);
        $deviceModels = DeviceModel::orderBy('name')->get(['id', 'name', 'manufacturer', 'device_type']);
        return view('admin.devices.form', compact('branches', 'departments', 'deviceModels'));
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
        ]);

        $device = Device::create(array_merge($data, ['source' => 'manual']));

        ActivityLog::log('created', $device, $data);

        return redirect()->route('admin.devices.show', $device)
                         ->with('success', "Device \"{$device->name}\" created.");
    }

    public function edit(Device $device)
    {
        $branches     = Branch::orderBy('name')->get(['id', 'name']);
        $departments  = Department::orderBy('sort_order')->orderBy('name')->get(['id', 'name']);
        $deviceModels = DeviceModel::orderBy('name')->get(['id', 'name', 'manufacturer', 'device_type']);
        $device->load(['floor', 'office']);
        return view('admin.devices.form', compact('device', 'branches', 'departments', 'deviceModels'));
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
}
