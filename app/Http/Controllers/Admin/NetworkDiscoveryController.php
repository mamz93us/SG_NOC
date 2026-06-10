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
use App\Models\Printer;
use App\Services\AssetCodeService;
use Illuminate\Database\QueryException;
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
            'name' => 'required|string|max:100',
            'range_input' => 'required|string|max:255',
            'branch_id' => 'nullable|exists:branches,id',
            'snmp_community' => 'nullable|string|max:100',
            'snmp_timeout' => 'nullable|integer|min:1|max:10',
        ]);

        $scan = DiscoveryScan::create([
            'name' => $data['name'],
            'range_input' => trim($data['range_input']),
            'branch_id' => $data['branch_id'] ?? null,
            'snmp_community' => $data['snmp_community'] ?? 'public',
            'snmp_timeout' => $data['snmp_timeout'] ?? 2,
            'status' => 'pending',
            'created_by' => Auth::id(),
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
            'scan' => $discoveryScan,
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

        // Duplicate guard — the host may already be in inventory from a
        // previous scan or a manual entry. Surface a flash message instead
        // of letting the devices.(source, source_id) unique index 500.
        $existing = Device::where(function ($q) use ($result) {
            $q->where('ip_address', $result->ip_address);
            if ($result->mac_address) {
                $q->orWhere('mac_address', $result->mac_address);
            }
        })->first();

        if ($existing) {
            return back()->with('error', "{$result->ip_address} is already in inventory as \"{$existing->name}\" (device #{$existing->id}) — not imported again.");
        }

        try {
            switch ($type) {
                case 'printer':
                    $model = DB::transaction(function () use ($result, $discoveryScan, $printerName) {
                        // Must create a Device record first (1-to-1 FK requirement)
                        $device = Device::create([
                            'type' => 'printer',
                            'asset_code' => $this->nextAssetCode('printer'),
                            'name' => $printerName,
                            'model' => $result->model,
                            'mac_address' => $result->mac_address,
                            'ip_address' => $result->ip_address,
                            'branch_id' => $discoveryScan->branch_id,
                            // devices.(source, source_id) is unique — give every
                            // discovery import its own id like other import paths.
                            'source' => 'printer',
                            'source_id' => 'discovery-'.$result->ip_address,
                            'status' => 'active',
                        ]);

                        return Printer::create([
                            'device_id' => $device->id,
                            'printer_name' => $printerName,
                            'ip_address' => $result->ip_address,
                            'mac_address' => $result->mac_address,
                            'model' => $result->model,
                            'manufacturer' => $result->vendor,
                            'branch_id' => $discoveryScan->branch_id,
                            'snmp_enabled' => $result->snmp_accessible,
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
                        'type' => 'switch',
                        'asset_code' => $this->nextAssetCode('switch'),
                        'name' => $printerName,
                        'ip_address' => $result->ip_address,
                        'mac_address' => $result->mac_address,
                        'manufacturer' => $result->vendor,
                        'model' => $result->model,
                        'branch_id' => $discoveryScan->branch_id,
                        'status' => 'active',
                        'source' => 'manual',
                        'source_id' => 'discovery-'.$result->ip_address,
                    ]);
                    $result->update(['already_imported' => true, 'imported_type' => 'device', 'imported_id' => $model->id]);
                    $label = 'Switch';
                    break;

                default: // device
                    $model = Device::create([
                        'asset_code' => $this->nextAssetCode('other'),
                        'name' => $printerName,
                        'ip_address' => $result->ip_address,
                        'mac_address' => $result->mac_address,
                        'model' => $result->model,
                        'branch_id' => $discoveryScan->branch_id,
                        'type' => 'other',
                        'status' => 'active',
                        'source' => 'manual',
                        'source_id' => 'discovery-'.$result->ip_address,
                    ]);
                    $result->update(['already_imported' => true, 'imported_type' => 'device', 'imported_id' => $model->id]);
                    $label = 'Device';
                    break;
            }
        } catch (QueryException $e) {
            // 23000 = integrity constraint violation (duplicate key) — e.g.
            // two results sharing a MAC/IP imported back-to-back.
            if (($e->errorInfo[0] ?? null) === '23000') {
                return back()->with('error', "{$result->ip_address} could not be imported — a matching device already exists in inventory.");
            }
            throw $e;
        }

        ActivityLog::log('Imported from Network Discovery', $model, [
            'ip_address' => $result->ip_address,
            'scan_id' => $discoveryScan->id,
            'type' => $label,
        ]);

        return back()->with('success', "{$result->ip_address} imported as {$label}.");
    }

    /**
     * Next sequential asset code for the type — same convention as manual
     * device creation. Non-fatal: returns null if settings/types are absent.
     */
    protected function nextAssetCode(string $type): ?string
    {
        try {
            return app(AssetCodeService::class)->generate($type);
        } catch (\Throwable) {
            return null;
        }
    }

    // ─── Sync MonitoredHost after printer import ─────────────────

    protected function syncPrinterMonitoredHost(Printer $printer): void
    {
        if (empty($printer->ip_address)) {
            return;
        }

        $host = MonitoredHost::firstOrNew(['ip' => $printer->ip_address]);
        $host->fill([
            'name' => $printer->printer_name,
            'type' => 'printer',
            'snmp_enabled' => (bool) $printer->snmp_enabled,
            'snmp_version' => $printer->snmp_version ?? 'v2c',
            'snmp_community' => $printer->snmp_community ?? 'public',
            'snmp_port' => 161,
            'snmp_security_level' => 'noAuthNoPriv', // column is NOT NULL
            'ping_enabled' => true,
            'branch_id' => $printer->branch_id,
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
