<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Device;
use App\Models\Printer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PrinterController extends Controller
{
    public function index(Request $request)
    {
        $query = Printer::with(['branch', 'device.credentials'])
                    ->orderBy('branch_id')
                    ->orderBy('printer_name');

        if ($request->filled('branch')) {
            $query->where('branch_id', $request->branch);
        }
        if ($request->filled('department')) {
            $query->where('department', $request->department);
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('printer_name', 'like', "%{$s}%")
                  ->orWhere('ip_address',  'like', "%{$s}%")
                  ->orWhere('mac_address', 'like', "%{$s}%")
                  ->orWhere('model',       'like', "%{$s}%");
            });
        }

        $printers    = $query->paginate(50)->withQueryString();
        $branches    = Branch::orderBy('name')->get(['id', 'name']);
        $departments = Printer::whereNotNull('department')->distinct()->orderBy('department')->pluck('department');

        return view('admin.printers.index', compact('printers', 'branches', 'departments'));
    }

    public function show(Printer $printer)
    {
        $printer->load(['branch', 'device.credentials.creator']);
        $maintenanceLogs = $printer->maintenanceLogs()
            ->with('performedByUser')
            ->orderByDesc('performed_at')
            ->get();
        return view('admin.printers.show', compact('printer', 'maintenanceLogs'));
    }

    public function create()
    {
        $branches     = Branch::orderBy('name')->get(['id', 'name']);
        $departments  = Department::orderBy('sort_order')->orderBy('name')->get(['id', 'name']);
        $deviceModels = \App\Models\DeviceModel::where('device_type', 'printer')
            ->orderBy('manufacturer')->orderBy('name')
            ->get(['id', 'name', 'manufacturer']);
        return view('admin.printers.form', compact('branches', 'departments', 'deviceModels'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'printer_name'   => 'required|string|max:255',
            'manufacturer'   => 'nullable|string|max:100',
            'model'          => 'nullable|string|max:100',
            'serial_number'  => 'nullable|string|max:100',
            'mac_address'    => 'nullable|string|max:20',
            'ip_address'     => 'nullable|ip',
            'branch_id'      => 'required|exists:branches,id',
            'floor_id'       => 'required|exists:network_floors,id',
            'office_id'      => 'nullable|exists:network_offices,id',
            'department_id'  => 'nullable|exists:departments,id',
            'floor'          => 'nullable|string|max:50',
            'room'           => 'nullable|string|max:50',
            'department'     => 'nullable|string|max:100',
            'toner_model'    => 'nullable|string|max:100',
            'snmp_community' => 'nullable|string|max:100',
            'snmp_version'   => 'nullable|in:v1,v2c,v3',
            'notes'          => 'nullable|string',
        ]);

        // Ensure snmp_version is never null for the DB (column may not be nullable yet)
        if (empty($data['snmp_version'])) {
            unset($data['snmp_version']);
        }

        DB::transaction(function () use ($data, $request) {
            // Create unified device record first
            $device = Device::create([
                'type'          => 'printer',
                'name'          => $data['printer_name'],
                'model'         => $data['model'] ?? null,
                'serial_number' => $data['serial_number'] ?? null,
                'mac_address'   => $data['mac_address'] ?? null,
                'ip_address'    => $data['ip_address'] ?? null,
                'branch_id'     => $data['branch_id'] ?? null,
                'floor_id'      => $data['floor_id'] ?? null,
                'office_id'     => $data['office_id'] ?? null,
                'department_id' => $data['department_id'] ?? null,
                'source'        => 'printer',
                'source_id'     => $data['serial_number'] ?? null,
                'status'        => 'active',
            ]);

            $printer = Printer::create(array_merge($data, ['device_id' => $device->id]));

            ActivityLog::create([
                'model_type' => 'Printer',
                'model_id'   => $printer->id,
                'action'     => 'created',
                'changes'    => ['name' => $printer->printer_name],
                'user_id'    => Auth::id(),
            ]);
        });

        return redirect()->route('admin.printers.index')
                         ->with('success', "Printer \"{$data['printer_name']}\" created.");
    }

    public function edit(Printer $printer)
    {
        $branches     = Branch::orderBy('name')->get(['id', 'name']);
        $departments  = Department::orderBy('sort_order')->orderBy('name')->get(['id', 'name']);
        $deviceModels = \App\Models\DeviceModel::where('device_type', 'printer')
            ->orderBy('manufacturer')->orderBy('name')
            ->get(['id', 'name', 'manufacturer']);
        $printer->load(['networkFloor', 'office']);
        return view('admin.printers.form', compact('printer', 'branches', 'departments', 'deviceModels'));
    }

    public function update(Request $request, Printer $printer)
    {
        $data = $request->validate([
            'printer_name'   => 'required|string|max:255',
            'manufacturer'   => 'nullable|string|max:100',
            'model'          => 'nullable|string|max:100',
            'serial_number'  => 'nullable|string|max:100',
            'mac_address'    => 'nullable|string|max:20',
            'ip_address'     => 'nullable|ip',
            'branch_id'      => 'required|exists:branches,id',
            'floor_id'       => 'required|exists:network_floors,id',
            'office_id'      => 'nullable|exists:network_offices,id',
            'department_id'  => 'nullable|exists:departments,id',
            'floor'          => 'nullable|string|max:50',
            'room'           => 'nullable|string|max:50',
            'department'     => 'nullable|string|max:100',
            'toner_model'    => 'nullable|string|max:100',
            'snmp_community' => 'nullable|string|max:100',
            'snmp_version'   => 'nullable|in:v1,v2c,v3',
            'notes'          => 'nullable|string',
        ]);

        // Ensure snmp_version is never null for the DB (column may not be nullable yet)
        if (empty($data['snmp_version'])) {
            unset($data['snmp_version']);
        }

        DB::transaction(function () use ($data, $printer) {
            $printer->update($data);
            $printer->device?->update([
                'name'          => $data['printer_name'],
                'model'         => $data['model'] ?? null,
                'serial_number' => $data['serial_number'] ?? null,
                'mac_address'   => $data['mac_address'] ?? null,
                'ip_address'    => $data['ip_address'] ?? null,
                'branch_id'     => $data['branch_id'] ?? null,
                'floor_id'      => $data['floor_id'] ?? null,
                'office_id'     => $data['office_id'] ?? null,
                'department_id' => $data['department_id'] ?? null,
            ]);
        });

        ActivityLog::create([
            'model_type' => 'Printer',
            'model_id'   => $printer->id,
            'action'     => 'updated',
            'changes'    => ['name' => $printer->printer_name],
            'user_id'    => Auth::id(),
        ]);

        return back()->with('success', "Printer \"{$printer->printer_name}\" updated.");
    }

    public function destroy(Printer $printer)
    {
        $name = $printer->printer_name;
        ActivityLog::create([
            'model_type' => 'Printer',
            'model_id'   => $printer->id,
            'action'     => 'deleted',
            'changes'    => ['name' => $name],
            'user_id'    => Auth::id(),
        ]);
        // Device cascades to printer via FK
        $printer->device?->delete();

        return redirect()->route('admin.printers.index')
                         ->with('success', "Printer \"{$name}\" deleted.");
    }
}
