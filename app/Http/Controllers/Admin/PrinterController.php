<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\DiscoverSnmpDeviceJob;
use App\Jobs\PollPrinterSnmpJob;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Device;
use App\Models\MonitoredHost;
use App\Models\Printer;
use App\Models\PrinterMaintenanceLog;
use App\Models\PrinterSupply;
use App\Models\SnmpSensor;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PrinterController extends Controller
{
    public function dashboard()
    {
        // KPI counts
        $total = Printer::count();

        $snmpEnabled    = Printer::where('snmp_enabled', true)->count();
        $recentlyPolled = Printer::where('snmp_enabled', true)
            ->whereNotNull('snmp_last_polled_at')
            ->where('snmp_last_polled_at', '>=', now()->subMinutes(30))
            ->count();

        // Low-toner printers: any toner supply below warning_threshold (default 20%)
        $lowTonerPrinterIds = PrinterSupply::where('supply_type', 'toner')
            ->whereNotNull('supply_percent')
            ->where('supply_percent', '>=', 0)
            ->whereRaw('supply_percent <= COALESCE(warning_threshold, 20)')
            ->distinct()
            ->pluck('printer_id');
        $lowTonerCount = $lowTonerPrinterIds->count();

        // Critical toner
        $criticalTonerPrinterIds = PrinterSupply::where('supply_type', 'toner')
            ->whereNotNull('supply_percent')
            ->where('supply_percent', '>=', 0)
            ->whereRaw('supply_percent <= COALESCE(critical_threshold, 5)')
            ->distinct()
            ->pluck('printer_id');
        $criticalTonerCount = $criticalTonerPrinterIds->count();

        // Maintenance due
        $maintenanceDue = Printer::whereNotNull('service_interval_days')
            ->whereNotNull('last_service_date')
            ->whereRaw('DATE_ADD(last_service_date, INTERVAL service_interval_days DAY) < NOW()')
            ->count();

        // Low toner supplies table (worst first, up to 20)
        $lowTonerSupplies = PrinterSupply::with(['printer.branch'])
            ->where('supply_type', 'toner')
            ->whereNotNull('supply_percent')
            ->where('supply_percent', '>=', 0)
            ->whereRaw('supply_percent <= COALESCE(warning_threshold, 20)')
            ->orderBy('supply_percent')
            ->limit(20)
            ->get();

        // Branch distribution
        $branchDist = Printer::select('branch_id', DB::raw('COUNT(*) as total'))
            ->with('branch:id,name')
            ->groupBy('branch_id')
            ->orderByDesc('total')
            ->get();

        // Recent maintenance logs
        $recentMaintenance = PrinterMaintenanceLog::with(['printer.branch', 'performedByUser'])
            ->orderByDesc('performed_at')
            ->limit(10)
            ->get();

        // Highest-usage printers (by page_count_total, non-null)
        $topByUsage = Printer::with('branch:id,name')
            ->whereNotNull('page_count_total')
            ->where('page_count_total', '>', 0)
            ->orderByDesc('page_count_total')
            ->limit(10)
            ->get();

        // Printers with errors / status
        $errorPrinters = Printer::with('branch:id,name')
            ->where('snmp_enabled', true)
            ->where('printer_status', 'error')
            ->orderByDesc('snmp_last_polled_at')
            ->limit(10)
            ->get();

        // Printers with active low-alert flag
        $isLowAlertPrinters = Printer::with(['branch:id,name', 'supplies' => fn($q) => $q->where('is_low_alert_active', true)])
            ->whereHas('supplies', fn($q) => $q->where('is_low_alert_active', true))
            ->orderBy('printer_name')
            ->limit(10)
            ->get();

        return view('admin.printers.dashboard', compact(
            'total', 'snmpEnabled', 'recentlyPolled',
            'lowTonerCount', 'criticalTonerCount', 'maintenanceDue',
            'lowTonerSupplies', 'branchDist', 'recentMaintenance',
            'topByUsage', 'errorPrinters', 'isLowAlertPrinters'
        ));
    }

    public function index(Request $request)
    {
        $query = Printer::with(['branch', 'device.credentials'])
                    ->withCount('drivers')
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
        $printer->load(['branch', 'device.credentials.creator', 'supplies', 'assignedEmployees']);
        $maintenanceLogs = $printer->maintenanceLogs()
            ->with('performedByUser')
            ->orderByDesc('performed_at')
            ->get();
        $intuneGroups = \App\Models\IntuneGroup::orderBy('name')
            ->get(['id', 'name', 'azure_group_id', 'group_type', 'sync_status']);
        return view('admin.printers.show', compact('printer', 'maintenanceLogs', 'intuneGroups'));
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
            'printer_url'    => 'nullable|url|max:500',
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
            'snmp_enabled'   => 'nullable|boolean',
            'toner_warning_threshold'  => 'nullable|integer|min:1|max:100',
            'toner_critical_threshold' => 'nullable|integer|min:1|max:100',
            'paper_warning_threshold'  => 'nullable|integer|min:1|max:100',
            'notes'          => 'nullable|string',
        ]);

        $data['snmp_enabled'] = $request->boolean('snmp_enabled');

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

        // Auto-create / update MonitoredHost for SNMP monitoring
        $printer = Printer::where('printer_name', $data['printer_name'])
                          ->where('ip_address', $data['ip_address'] ?? null)
                          ->latest()->first();
        if ($printer) {
            $this->syncMonitoredHost($printer);
        }

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
            'printer_url'    => 'nullable|url|max:500',
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
            'snmp_enabled'   => 'nullable|boolean',
            'toner_warning_threshold'  => 'nullable|integer|min:1|max:100',
            'toner_critical_threshold' => 'nullable|integer|min:1|max:100',
            'paper_warning_threshold'  => 'nullable|integer|min:1|max:100',
            'notes'          => 'nullable|string',
        ]);

        $data['snmp_enabled'] = $request->boolean('snmp_enabled');

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

        // Keep MonitoredHost in sync with printer SNMP settings
        $printer->refresh();
        $this->syncMonitoredHost($printer);

        return back()->with('success', "Printer \"{$printer->printer_name}\" updated.");
    }

    /**
     * Create or update the MonitoredHost record that mirrors this printer's
     * SNMP settings so it appears in the SNMP Monitoring dashboard.
     * If SNMP is disabled or IP is missing the host is soft-disabled (snmp_enabled=false).
     */
    protected function syncMonitoredHost(Printer $printer): void
    {
        if (empty($printer->ip_address)) {
            return;
        }

        $host = MonitoredHost::firstOrNew(['ip' => $printer->ip_address]);

        $host->fill([
            'name'              => $printer->printer_name,
            'type'              => 'printer',
            'snmp_enabled'      => (bool) $printer->snmp_enabled,
            'snmp_version'      => $printer->snmp_version ?? 'v2c',
            'snmp_community'    => $printer->snmp_community,   // raw value; MonitoredHost accessor will encrypt
            'snmp_port'         => 161,
            'ping_enabled'      => true,
            'branch_id'         => $printer->branch_id,
        ]);

        $isNew = !$host->exists;
        $host->save();

        // Trigger full SNMP discovery (creates sensors) for newly added hosts
        // or when SNMP was just enabled.
        if ($printer->snmp_enabled && ($isNew || $host->wasRecentlyCreated)) {
            DiscoverSnmpDeviceJob::dispatch($host);
        }
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

    // ─── SNMP Dashboard ─────────────────────────────────────────

    public function snmpStatus(Request $request)
    {
        $query = Printer::with(['branch', 'supplies'])
            ->where('snmp_enabled', true)
            ->orderBy('printer_name');

        if ($request->filled('branch')) {
            $query->where('branch_id', $request->branch);
        }
        if ($request->filled('status')) {
            $query->where('printer_status', $request->status);
        }
        if ($request->filled('low_toner')) {
            $query->where(function ($q) {
                $q->where('toner_black', '<=', 20)
                  ->orWhere('toner_cyan', '<=', 20)
                  ->orWhere('toner_magenta', '<=', 20)
                  ->orWhere('toner_yellow', '<=', 20);
            });
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('printer_name', 'like', "%{$s}%")
                  ->orWhere('ip_address', 'like', "%{$s}%")
                  ->orWhere('model', 'like', "%{$s}%");
            });
        }

        $printers = $query->get();
        $branches = Branch::orderBy('name')->get(['id', 'name']);

        return view('admin.printers.snmp-status', compact('printers', 'branches'));
    }

    public function snmpPoll(Printer $printer)
    {
        PollPrinterSnmpJob::dispatchSync($printer->id);

        return back()->with('success', "SNMP poll completed for \"{$printer->printer_name}\".");
    }

    public function snmpPollAll()
    {
        PollPrinterSnmpJob::dispatch();

        return back()->with('success', 'SNMP poll job dispatched for all enabled printers.');
    }

    public function toggleSnmp(Request $request, Printer $printer)
    {
        $printer->update(['snmp_enabled' => !$printer->snmp_enabled]);

        $state = $printer->snmp_enabled ? 'enabled' : 'disabled';
        return back()->with('success', "SNMP monitoring {$state} for \"{$printer->printer_name}\".");
    }

    // ─── Manual Employee Assignment ─────────────────────────────

    /**
     * POST /admin/printers/{printer}/assign
     * Manually assign an employee to this printer.
     */
    public function assignEmployee(Request $request, Printer $printer)
    {
        $data = $request->validate([
            'employee_email' => 'required|email|exists:employees,email',
            'notes'          => 'nullable|string|max:500',
        ]);

        $employee = \App\Models\Employee::where('email', $data['employee_email'])->firstOrFail();

        // Sync without detach — attach only if not already assigned
        if (! $printer->assignedEmployees()->where('employee_id', $employee->id)->exists()) {
            $printer->assignedEmployees()->attach($employee->id, [
                'assigned_by' => Auth::id(),
                'notes'       => $data['notes'] ?? null,
            ]);
        }

        return back()->with('success', "{$employee->first_name} {$employee->last_name} has been assigned to \"{$printer->printer_name}\".");
    }

    /**
     * DELETE /admin/printers/{printer}/assign/{employee}
     * Remove a manually assigned employee from this printer.
     */
    public function unassignEmployee(Printer $printer, \App\Models\Employee $employee)
    {
        $printer->assignedEmployees()->detach($employee->id);
        return back()->with('success', "{$employee->first_name} {$employee->last_name} removed from \"{$printer->printer_name}\".");
    }

    // ─── Toner History (Chart.js API endpoint) ───────────────

    public function tonerHistory(Request $request, Printer $printer)
    {
        $days = (int) $request->get('days', 14);
        $days = min($days, 90);

        $host = MonitoredHost::where('ip', $printer->ip_address)->first();

        $result = [];

        if ($host) {
            $tonerSensors = SnmpSensor::where('host_id', $host->id)
                ->where(function ($q) {
                    $q->where('sensor_group', 'like', '%toner%')
                      ->orWhere('name', 'like', '%Toner%')
                      ->orWhere('name', 'like', '%toner%');
                })
                ->get();

            $useHourly = class_exists(\App\Models\SensorMetricHourly::class);

            foreach ($tonerSensors as $sensor) {
                if ($useHourly) {
                    $points = \App\Models\SensorMetricHourly::where('sensor_id', $sensor->id)
                        ->where('hour', '>=', now()->subDays($days))
                        ->orderBy('hour')
                        ->get(['hour', 'value_avg'])
                        ->map(fn ($r) => [
                            'ts' => Carbon::parse($r->hour)->toIso8601String(),
                            'v'  => round($r->value_avg, 1),
                        ])
                        ->toArray();
                } else {
                    // Fallback: use raw metrics
                    $points = \App\Models\SensorMetric::where('sensor_id', $sensor->id)
                        ->where('recorded_at', '>=', now()->subDays($days))
                        ->orderBy('recorded_at')
                        ->get(['recorded_at', 'value'])
                        ->map(fn ($r) => [
                            'ts' => Carbon::parse($r->recorded_at)->toIso8601String(),
                            'v'  => round($r->value, 1),
                        ])
                        ->toArray();
                }

                if (!empty($points)) {
                    $result[] = ['label' => $sensor->name, 'data' => $points];
                }
            }
        }

        return response()->json($result);
    }
}
