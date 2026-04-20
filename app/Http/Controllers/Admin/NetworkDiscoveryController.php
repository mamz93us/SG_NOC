<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\DiscoverSnmpDeviceJob;
use App\Jobs\RunDiscoveryScanJob;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Device;
use App\Models\DiscoveryResult;
use App\Models\DiscoveryScan;
use App\Models\MonitoredHost;
use App\Models\NetworkSwitch;
use App\Models\Printer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class NetworkDiscoveryController extends Controller
{
    // ─── Scan List ───────────────────────────────────────────────

    public function index()
    {
        $scans = DiscoveryScan::with('branch', 'creator')
            ->orderByDesc('created_at')
            ->paginate(20);

        $branches = Branch::orderBy('name')->get(['id', 'name']);

        return view('admin.network-discovery.index', compact('scans', 'branches'));
    }

    // ─── Store (create + dispatch) ───────────────────────────────

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'           => 'required|string|max:100',
            'range_input'    => 'required|string|max:255',
            'branch_id'      => 'nullable|exists:branches,id',
            'snmp_community' => 'nullable|string|max:100',
            'snmp_timeout'   => 'nullable|integer|min:1|max:10',
        ]);

        $scan = DiscoveryScan::create([
            'name'           => $data['name'],
            'range_input'    => trim($data['range_input']),
            'branch_id'      => $data['branch_id'] ?? null,
            'snmp_community' => $data['snmp_community'] ?? 'public',
            'snmp_timeout'   => $data['snmp_timeout'] ?? 2,
            'status'         => 'pending',
            'created_by'     => Auth::id(),
        ]);

        RunDiscoveryScanJob::dispatch($scan->id);

        return redirect()
            ->route('admin.network-discovery.show', $scan)
            ->with('success', 'Scan started — results will appear as hosts are discovered.');
    }

    // ─── Show (scan results) ─────────────────────────────────────

    public function show(DiscoveryScan $discoveryScan)
    {
        $discoveryScan->load('branch', 'creator');

        $results = $discoveryScan->results()
            ->orderByRaw("FIELD(device_type, 'printer', 'switch', 'device', 'unknown')")
            ->orderBy('ip_address')
            ->get();

        return view('admin.network-discovery.show', [
            'scan'    => $discoveryScan,
            'results' => $results,
        ]);
    }

    // ─── Import a single result ──────────────────────────────────

    public function import(Request $request, DiscoveryScan $discoveryScan, DiscoveryResult $result)
    {
        if ($result->already_imported) {
            return back()->with('error', 'This host is already imported.');
        }

        $type = $request->input('import_as', $result->device_type);

        $printerName = $result->sys_name ?: $result->hostname ?: $result->ip_address;

        switch ($type) {
            case 'printer':
                $model = DB::transaction(function () use ($result, $discoveryScan, $printerName) {
                    // Must create a Device record first (1-to-1 FK requirement)
                    $device = Device::create([
                        'type'        => 'printer',
                        'name'        => $printerName,
                        'model'       => $result->model,
                        'mac_address' => $result->mac_address,
                        'ip_address'  => $result->ip_address,
                        'branch_id'   => $discoveryScan->branch_id,
                        'source'      => 'printer',
                        'status'      => 'active',
                    ]);

                    return Printer::create([
                        'device_id'      => $device->id,
                        'printer_name'   => $printerName,
                        'ip_address'     => $result->ip_address,
                        'mac_address'    => $result->mac_address,
                        'model'          => $result->model,
                        'manufacturer'   => $result->vendor,
                        'branch_id'      => $discoveryScan->branch_id,
                        'snmp_enabled'   => $result->snmp_accessible,
                        'snmp_community' => $discoveryScan->snmp_community,
                    ]);
                });

                // Auto-register in SNMP monitoring (same as normal printer creation)
                $this->syncPrinterMonitoredHost($model);

                $result->update(['already_imported' => true, 'imported_type' => 'printer', 'imported_id' => $model->id]);
                $label = 'Printer';
                break;

            case 'switch':
                $model = Device::create([
                    'type'         => 'switch',
                    'name'         => $printerName,
                    'ip_address'   => $result->ip_address,
                    'mac_address'  => $result->mac_address,
                    'manufacturer' => $result->vendor,
                    'model'        => $result->model,
                    'branch_id'    => $discoveryScan->branch_id,
                    'status'       => 'active',
                    'source'       => 'discovery',
                    'source_id'    => (string) $result->id,
                ]);
                $result->update(['already_imported' => true, 'imported_type' => 'device', 'imported_id' => $model->id]);
                $label = 'Switch';
                break;

            default: // device
                $model = Device::create([
                    'name'        => $printerName,
                    'ip_address'  => $result->ip_address,
                    'mac_address' => $result->mac_address,
                    'model'       => $result->model,
                    'branch_id'   => $discoveryScan->branch_id,
                    'type'        => 'other',
                    'status'      => 'active',
                ]);
                $result->update(['already_imported' => true, 'imported_type' => 'device', 'imported_id' => $model->id]);
                $label = 'Device';
                break;
        }

        ActivityLog::log('Imported from Network Discovery', $model, [
            'ip_address' => $result->ip_address,
            'scan_id'    => $discoveryScan->id,
            'type'       => $label,
        ]);

        return back()->with('success', "{$result->ip_address} imported as {$label}.");
    }

    // ─── Sync MonitoredHost after printer import ─────────────────

    protected function syncPrinterMonitoredHost(Printer $printer): void
    {
        if (empty($printer->ip_address)) {
            return;
        }

        $host = MonitoredHost::firstOrNew(['ip' => $printer->ip_address]);
        $host->fill([
            'name'                => $printer->printer_name,
            'type'                => 'printer',
            'snmp_enabled'        => (bool) $printer->snmp_enabled,
            'snmp_version'        => $printer->snmp_version ?? 'v2c',
            'snmp_community'      => $printer->snmp_community ?? 'public',
            'snmp_port'           => 161,
            'snmp_security_level' => 'noAuthNoPriv', // column is NOT NULL
            'ping_enabled'        => true,
            'branch_id'           => $printer->branch_id,
        ]);

        $isNew = ! $host->exists;
        $host->save();

        if ($printer->snmp_enabled && ($isNew || $host->wasRecentlyCreated)) {
            DiscoverSnmpDeviceJob::dispatch($host);
        }
    }

    // ─── Delete a scan ───────────────────────────────────────────

    public function destroy(DiscoveryScan $discoveryScan)
    {
        $discoveryScan->delete();
        return redirect()->route('admin.network-discovery.index')
            ->with('success', 'Scan deleted.');
    }
}
