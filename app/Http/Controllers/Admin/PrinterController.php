<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\PollPrinterSnmpJob;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\CupsPrinter;
use App\Models\Department;
use App\Models\Device;
use App\Models\DiscoveryScan;
use App\Models\MonitoredHost;
use App\Models\NocEvent;
use App\Models\Printer;
use App\Models\PrinterMaintenanceLog;
use App\Models\PrinterSupply;
use App\Models\SnmpSensor;
use App\Services\Printers\PrinterDiscoveryService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PrinterController extends Controller
{
    public function dashboard()
    {
        // KPI counts
        $total = Printer::count();

        $snmpEnabled = Printer::where('snmp_enabled', true)->count();
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
        $isLowAlertPrinters = Printer::with(['branch:id,name', 'supplies' => fn ($q) => $q->where('is_low_alert_active', true)])
            ->whereHas('supplies', fn ($q) => $q->where('is_low_alert_active', true))
            ->orderBy('printer_name')
            ->limit(10)
            ->get();

        // Cross-system bridges (CUPS + asset link counts)
        $cupsLinkedCount = CupsPrinter::whereNotNull('printer_id')->count();
        $cupsOnlineCount = CupsPrinter::whereIn('status', ['online', 'idle', 'printing'])->count();
        $assetLinkedCount = Printer::whereNotNull('device_id')->count();
        $wasteFullCount = Printer::whereNotNull('toner_waste')
            ->where('toner_waste', '>=', 0)
            ->where('toner_waste', '<=', 5)
            ->count();
        $openPrinterAlerts = NocEvent::where('source_type', 'printer')
            ->whereIn('status', ['open', 'acknowledged'])
            ->count();

        return view('admin.printers.dashboard', compact(
            'total', 'snmpEnabled', 'recentlyPolled',
            'lowTonerCount', 'criticalTonerCount', 'maintenanceDue',
            'lowTonerSupplies', 'branchDist', 'recentMaintenance',
            'topByUsage', 'errorPrinters', 'isLowAlertPrinters',
            'cupsLinkedCount', 'cupsOnlineCount', 'assetLinkedCount',
            'wasteFullCount', 'openPrinterAlerts'
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
                    ->orWhere('ip_address', 'like', "%{$s}%")
                    ->orWhere('mac_address', 'like', "%{$s}%")
                    ->orWhere('model', 'like', "%{$s}%");
            });
        }

        $printers = $query->paginate(50)->withQueryString();
        $branches = Branch::orderBy('name')->get(['id', 'name']);
        $departments = Printer::whereNotNull('department')->distinct()->orderBy('department')->pluck('department');

        // Most recent auto-discovery scan (for the progress banner) + count of
        // SNMP printers still missing sensors (for the "Discover Sensors" badge).
        $lastScan = DiscoveryScan::where('auto_import_printers', true)
            ->where('created_at', '>=', now()->subHours(6))
            ->orderByDesc('id')
            ->first();
        $missingSensors = MonitoredHost::where('snmp_enabled', true)
            ->where('type', 'printer')
            ->whereDoesntHave('snmpSensors')
            ->count();

        return view('admin.printers.index', compact('printers', 'branches', 'departments', 'lastScan', 'missingSensors'));
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
        $branches = Branch::orderBy('name')->get(['id', 'name']);
        $departments = Department::orderBy('sort_order')->orderBy('name')->get(['id', 'name']);
        $deviceModels = \App\Models\DeviceModel::where('device_type', 'printer')
            ->orderBy('manufacturer')->orderBy('name')
            ->get(['id', 'name', 'manufacturer']);

        return view('admin.printers.form', compact('branches', 'departments', 'deviceModels'));
    }

    public function store(Request $request, PrinterDiscoveryService $discovery)
    {
        $data = $request->validate([
            'printer_name' => 'required|string|max:255',
            'manufacturer' => 'nullable|string|max:100',
            'model' => 'nullable|string|max:100',
            'serial_number' => 'nullable|string|max:100',
            'mac_address' => 'nullable|string|max:20',
            'ip_address' => 'nullable|ip',
            'printer_url' => 'nullable|url|max:500',
            'branch_id' => 'required|exists:branches,id',
            'floor_id' => 'required|exists:network_floors,id',
            'office_id' => 'nullable|exists:network_offices,id',
            'department_id' => 'nullable|exists:departments,id',
            'floor' => 'nullable|string|max:50',
            'room' => 'nullable|string|max:50',
            'department' => 'nullable|string|max:100',
            'toner_model' => 'nullable|string|max:100',
            'snmp_community' => 'nullable|string|max:100',
            'snmp_version' => 'nullable|in:v1,v2c,v3',
            'snmp_enabled' => 'nullable|boolean',
            'toner_warning_threshold' => 'nullable|integer|min:1|max:100',
            'toner_critical_threshold' => 'nullable|integer|min:1|max:100',
            'paper_warning_threshold' => 'nullable|integer|min:1|max:100',
            'notes' => 'nullable|string',
        ]);

        $data = $this->applySnmpDefaults($data, $request);

        // Auto-generate asset code for the printer device record
        try {
            $assetCode = (new \App\Services\AssetCodeService)->generate('printer');
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('PrinterController: asset code generation failed: '.$e->getMessage());
            // Hard fallback — use raw DB sequence so the record is never left without a code
            $lastCode = \App\Models\Device::where('asset_code', 'like', 'SG-PRN-%')
                ->orderByRaw('LENGTH(asset_code) DESC, asset_code DESC')
                ->value('asset_code');
            $seq = $lastCode ? ((int) ltrim(substr($lastCode, 7), '0') + 1) : 1;
            $assetCode = 'SG-PRN-'.str_pad($seq, 6, '0', STR_PAD_LEFT);
        }

        $printer = null;
        DB::transaction(function () use ($data, $assetCode, &$printer) {
            // Create unified device record first
            $device = Device::create([
                'type' => 'printer',
                'name' => $data['printer_name'],
                'model' => $data['model'] ?? null,
                'serial_number' => $data['serial_number'] ?? null,
                'mac_address' => $data['mac_address'] ?? null,
                'ip_address' => $data['ip_address'] ?? null,
                'branch_id' => $data['branch_id'] ?? null,
                'floor_id' => $data['floor_id'] ?? null,
                'office_id' => $data['office_id'] ?? null,
                'department_id' => $data['department_id'] ?? null,
                'source' => 'printer',
                // Fall back to a synthetic id when no serial is provided, so
                // multiple serialless printers don't collide under the
                // devices.(source, source_id) unique index.
                'source_id' => $data['serial_number'] ?: ('printer-'.\Illuminate\Support\Str::random(12)),
                'status' => 'active',
                'asset_code' => $assetCode,
            ]);

            $printer = Printer::create(array_merge($data, ['device_id' => $device->id]));

            ActivityLog::create([
                'model_type' => 'Printer',
                'model_id' => $printer->id,
                'action' => 'created',
                'changes' => ['name' => $printer->printer_name],
                'user_id' => Auth::id(),
            ]);
        });

        // Auto discover + ping + pull immediately when SNMP is enabled, so the
        // new printer shows live toner/status right away. Otherwise just mirror
        // it into SNMP monitoring (for ping checks).
        $message = "Printer \"{$data['printer_name']}\" created.";
        if ($printer && $data['snmp_enabled'] && ! empty($data['ip_address'])) {
            $result = $discovery->discoverAndPoll($printer);
            $message .= ' '.$result['message'];
        } elseif ($printer) {
            $discovery->syncMonitoredHost($printer);
        }

        return redirect()->route('admin.printers.index')->with('success', $message);
    }

    /**
     * Normalise SNMP fields: when monitoring is on, ensure a community and
     * version are set so polling actually runs; when off, leave version unset
     * so the column default applies.
     */
    protected function applySnmpDefaults(array $data, Request $request): array
    {
        $data['snmp_enabled'] = $request->boolean('snmp_enabled');

        if ($data['snmp_enabled']) {
            if (empty($data['snmp_community'])) {
                $data['snmp_community'] = 'public';
            }
            if (empty($data['snmp_version'])) {
                $data['snmp_version'] = 'v2c';
            }
        } elseif (empty($data['snmp_version'])) {
            // Ensure snmp_version is never null for the DB (column may not be nullable yet)
            unset($data['snmp_version']);
        }

        return $data;
    }

    public function edit(Printer $printer)
    {
        $branches = Branch::orderBy('name')->get(['id', 'name']);
        $departments = Department::orderBy('sort_order')->orderBy('name')->get(['id', 'name']);
        $deviceModels = \App\Models\DeviceModel::where('device_type', 'printer')
            ->orderBy('manufacturer')->orderBy('name')
            ->get(['id', 'name', 'manufacturer']);
        $printer->load(['networkFloor', 'office']);

        return view('admin.printers.form', compact('printer', 'branches', 'departments', 'deviceModels'));
    }

    public function update(Request $request, Printer $printer, PrinterDiscoveryService $discovery)
    {
        $data = $request->validate([
            'printer_name' => 'required|string|max:255',
            'manufacturer' => 'nullable|string|max:100',
            'model' => 'nullable|string|max:100',
            'serial_number' => 'nullable|string|max:100',
            'mac_address' => 'nullable|string|max:20',
            'ip_address' => 'nullable|ip',
            'printer_url' => 'nullable|url|max:500',
            'branch_id' => 'required|exists:branches,id',
            'floor_id' => 'required|exists:network_floors,id',
            'office_id' => 'nullable|exists:network_offices,id',
            'department_id' => 'nullable|exists:departments,id',
            'floor' => 'nullable|string|max:50',
            'room' => 'nullable|string|max:50',
            'department' => 'nullable|string|max:100',
            'toner_model' => 'nullable|string|max:100',
            'snmp_community' => 'nullable|string|max:100',
            'snmp_version' => 'nullable|in:v1,v2c,v3',
            'snmp_enabled' => 'nullable|boolean',
            'toner_warning_threshold' => 'nullable|integer|min:1|max:100',
            'toner_critical_threshold' => 'nullable|integer|min:1|max:100',
            'paper_warning_threshold' => 'nullable|integer|min:1|max:100',
            'notes' => 'nullable|string',
        ]);

        // Snapshot the SNMP-relevant state before saving so we can tell whether
        // monitoring was just turned on or its connection settings changed.
        $before = [
            'snmp_enabled' => (bool) $printer->snmp_enabled,
            'ip_address' => $printer->ip_address,
            'snmp_community' => $printer->snmp_community,
            'snmp_version' => $printer->snmp_version,
        ];

        $data = $this->applySnmpDefaults($data, $request);

        DB::transaction(function () use ($data, $printer) {
            $printer->update($data);
            $printer->device?->update([
                'name' => $data['printer_name'],
                'model' => $data['model'] ?? null,
                'serial_number' => $data['serial_number'] ?? null,
                'mac_address' => $data['mac_address'] ?? null,
                'ip_address' => $data['ip_address'] ?? null,
                'branch_id' => $data['branch_id'] ?? null,
                'floor_id' => $data['floor_id'] ?? null,
                'office_id' => $data['office_id'] ?? null,
                'department_id' => $data['department_id'] ?? null,
            ]);
        });

        ActivityLog::create([
            'model_type' => 'Printer',
            'model_id' => $printer->id,
            'action' => 'updated',
            'changes' => ['name' => $printer->printer_name],
            'user_id' => Auth::id(),
        ]);

        $printer->refresh();

        // Re-discover + pull when SNMP is on AND something poll-relevant changed
        // (just enabled, or IP / community / version edited). Plain edits to an
        // already-monitored printer just keep the MonitoredHost mirror in sync.
        $message = "Printer \"{$printer->printer_name}\" updated.";
        $snmpChanged = ! $before['snmp_enabled']
            || $before['ip_address'] !== $printer->ip_address
            || $before['snmp_community'] !== $printer->snmp_community
            || $before['snmp_version'] !== $printer->snmp_version;

        if ($printer->snmp_enabled && ! empty($printer->ip_address) && $snmpChanged) {
            $result = $discovery->discoverAndPoll($printer);
            $message .= ' '.$result['message'];
        } else {
            $discovery->syncMonitoredHost($printer);
        }

        return back()->with('success', $message);
    }

    public function destroy(Printer $printer)
    {
        $name = $printer->printer_name;
        ActivityLog::create([
            'model_type' => 'Printer',
            'model_id' => $printer->id,
            'action' => 'deleted',
            'changes' => ['name' => $name],
            'user_id' => Auth::id(),
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
        $missingSensors = MonitoredHost::where('snmp_enabled', true)
            ->where('type', 'printer')
            ->whereDoesntHave('snmpSensors')
            ->count();

        // Pull the live host-monitoring state (ping up/down, last poll, sensor
        // count) so the SNMP page reflects the same reachability monitoring sees.
        $hostsByIp = MonitoredHost::withCount('snmpSensors')
            ->whereIn('ip', $printers->pluck('ip_address')->filter()->unique())
            ->get()
            ->keyBy('ip');

        return view('admin.printers.snmp-status', compact('printers', 'branches', 'missingSensors', 'hostsByIp'));
    }

    public function snmpPoll(Printer $printer)
    {
        // Manual poll → force (bypass the recent-poll lock) so it always refreshes.
        PollPrinterSnmpJob::dispatchSync($printer->id, true);

        return back()->with('success', "SNMP poll completed for \"{$printer->printer_name}\".");
    }

    /**
     * Force an immediate SNMP pull for every enabled printer. Flags the
     * every-minute 'force-poll-printers' task rather than polling inline — an
     * unreachable printer can take ~30-50s of SNMP timeouts, so polling the
     * whole fleet in one web request would risk a gateway timeout.
     */
    public function snmpPollAll()
    {
        $count = Printer::where('snmp_enabled', true)
            ->whereNotNull('ip_address')
            ->whereNotNull('snmp_community')
            ->count();

        if ($count === 0) {
            return back()->with('info', 'No SNMP-enabled printers with an IP to poll.');
        }

        Cache::put('printers.force_poll_all', true, 600);

        return back()->with('info',
            "Force-pulling all {$count} SNMP printer(s) now — data refreshes within a minute. Reload to see it."
        );
    }

    public function toggleSnmp(Request $request, Printer $printer)
    {
        $printer->update(['snmp_enabled' => ! $printer->snmp_enabled]);

        $state = $printer->snmp_enabled ? 'enabled' : 'disabled';

        return back()->with('success', "SNMP monitoring {$state} for \"{$printer->printer_name}\".");
    }

    /**
     * POST /admin/printers/discover-scan
     * Queue an SNMP network scan that auto-creates + polls every printer found.
     * The scan runs in the background (scheduled processor) so large ranges never
     * hit a gateway timeout; printers appear in the list as they're imported.
     */
    public function discoverScan(Request $request)
    {
        $data = $request->validate([
            'range_input' => 'required|string|max:255',
            'branch_id' => 'nullable|exists:branches,id',
            'snmp_community' => 'nullable|string|max:100',
            'snmp_timeout' => 'nullable|integer|min:1|max:5',
        ]);

        $scan = DiscoveryScan::create([
            'name' => 'Printer auto-discovery',
            'range_input' => trim($data['range_input']),
            'branch_id' => $data['branch_id'] ?? null,
            'snmp_community' => $data['snmp_community'] ?: 'public',
            'snmp_timeout' => $data['snmp_timeout'] ?? 2,
            'auto_import_printers' => true,
            'status' => 'pending',
            'created_by' => Auth::id(),
        ]);

        return redirect()->route('admin.printers.index')->with('info',
            "Discovery scan queued for {$scan->range_input}. Discovered printers are added "
            .'automatically within a minute or two — refresh this page to see them.'
        );
    }

    /**
     * POST /admin/printers/discover-sensors
     * Discover SNMP sensors for printer hosts that don't have any yet. Runs a
     * small bounded batch inline (so the request can't time out); the scheduled
     * 'discover-printer-sensors' task clears any remainder every 10 minutes.
     */
    public function discoverSensors(PrinterDiscoveryService $discovery)
    {
        // Small inline batch keeps the request well under the gateway timeout;
        // the scheduled 'discover-printer-sensors' task clears the remainder.
        $res = $discovery->discoverPrinterSensors(limit: 3, onlyMissing: true);

        if ($res['processed'] === 0 && $res['remaining'] === 0) {
            return back()->with('info', 'All SNMP-enabled printers already have sensors discovered.');
        }

        $msg = "Discovered SNMP sensors for {$res['processed']} printer(s).";
        if ($res['remaining'] > 0) {
            $msg .= " {$res['remaining']} still pending — processed automatically every 10 minutes.";
        }

        return back()->with('success', $msg);
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
            'notes' => 'nullable|string|max:500',
        ]);

        $employee = \App\Models\Employee::where('email', $data['employee_email'])->firstOrFail();

        // Sync without detach — attach only if not already assigned
        if (! $printer->assignedEmployees()->where('employee_id', $employee->id)->exists()) {
            $printer->assignedEmployees()->attach($employee->id, [
                'assigned_by' => Auth::id(),
                'notes' => $data['notes'] ?? null,
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
                            'v' => round($r->value_avg, 1),
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
                            'v' => round($r->value, 1),
                        ])
                        ->toArray();
                }

                if (! empty($points)) {
                    $result[] = ['label' => $sensor->name, 'data' => $points];
                }
            }
        }

        return response()->json($result);
    }
}
