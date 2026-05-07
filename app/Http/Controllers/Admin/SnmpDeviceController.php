<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BranchLogCollector;
use App\Models\SnmpDevice;
use App\Models\SnmpDiscoveredDevice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin CRUD for the operator-curated list of SNMP devices each branch
 * VM polls. Branches pull this list via /api/branch-config/snmp-devices
 * (see BranchConfigController) and feed it to Telegraf.
 */
class SnmpDeviceController extends Controller
{
    public function index(Request $request): View
    {
        $branches = BranchLogCollector::orderBy('code')->get();

        $q = SnmpDevice::with('branch')
            ->orderBy('branch_log_collector_id')
            ->orderBy('host');

        $filters = [
            'branch'      => trim((string) $request->get('branch', '')),
            'device_type' => trim((string) $request->get('device_type', '')),
            'enabled'     => $request->get('enabled', ''),
        ];
        if ($filters['branch']) {
            $q->whereHas('branch', fn ($qq) => $qq->where('code', $filters['branch']));
        }
        if ($filters['device_type']) {
            $q->where('device_type', $filters['device_type']);
        }
        if ($filters['enabled'] !== '' && $filters['enabled'] !== null) {
            $q->where('enabled', (bool) $filters['enabled']);
        }

        $devices       = $q->paginate(50)->withQueryString();
        $pendingCount  = SnmpDiscoveredDevice::where('status', 'pending')->count();

        return view('admin.snmp-devices.index', compact(
            'devices', 'branches', 'filters', 'pendingCount'
        ));
    }

    public function create(): View
    {
        return view('admin.snmp-devices.create', [
            'device'   => new SnmpDevice([
                'snmp_version'       => '2c',
                'snmp_port'          => 161,
                'polling_interval_s' => 60,
                'enabled'            => true,
                'device_type'        => 'switch_generic',
            ]),
            'branches' => BranchLogCollector::orderBy('code')->get(),
            'types'    => SnmpDevice::TYPES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, null);
        SnmpDevice::create($data);
        return redirect()
            ->route('admin.snmp-devices.index')
            ->with('success', "Device '{$data['name']}' added — branch will pick it up on the next sync.");
    }

    public function edit(SnmpDevice $snmpDevice): View
    {
        return view('admin.snmp-devices.edit', [
            'device'   => $snmpDevice,
            'branches' => BranchLogCollector::orderBy('code')->get(),
            'types'    => SnmpDevice::TYPES,
        ]);
    }

    public function update(Request $request, SnmpDevice $snmpDevice): RedirectResponse
    {
        $data = $this->validated($request, $snmpDevice);
        if (empty($data['snmp_community'])) unset($data['snmp_community']);
        $snmpDevice->update($data);
        return redirect()
            ->route('admin.snmp-devices.index')
            ->with('success', "Device '{$snmpDevice->name}' updated.");
    }

    public function destroy(SnmpDevice $snmpDevice): RedirectResponse
    {
        $name = $snmpDevice->name;
        $snmpDevice->delete();
        return redirect()
            ->route('admin.snmp-devices.index')
            ->with('success', "Device '{$name}' removed. Branch will stop polling on next sync.");
    }

    /**
     * Discovery inbox — devices nmap found that are awaiting review.
     */
    public function discovered(Request $request): View
    {
        $branches = BranchLogCollector::orderBy('code')->get();
        $status   = $request->get('status', 'pending');

        $q = SnmpDiscoveredDevice::with('branch')
            ->orderBy('last_seen_at', 'desc');
        if ($request->filled('branch')) {
            $q->whereHas('branch', fn ($qq) => $qq->where('code', $request->get('branch')));
        }
        if (in_array($status, ['pending', 'approved', 'rejected'], true)) {
            $q->where('status', $status);
        }

        $rows = $q->paginate(50)->withQueryString();

        return view('admin.snmp-devices.discovered', [
            'rows'     => $rows,
            'branches' => $branches,
            'status'   => $status,
            'types'    => SnmpDevice::TYPES,
        ]);
    }

    /**
     * Approve a discovered device → create a real SnmpDevice row + mark
     * the discovery row 'approved'. Operator picks the device_type and
     * community in the same form on the discovered page.
     */
    public function approveDiscovered(Request $request, SnmpDiscoveredDevice $discovery): RedirectResponse
    {
        $data = $request->validate([
            'name'           => ['required', 'string', 'max:100'],
            'device_type'    => ['required', 'string', Rule::in(array_keys(SnmpDevice::TYPES))],
            'snmp_community' => ['nullable', 'string', 'max:128'],
            'snmp_version'   => ['nullable', Rule::in(['1', '2c', '3'])],
            'snmp_port'      => ['nullable', 'integer', 'between:1,65535'],
        ]);

        SnmpDevice::create([
            'branch_log_collector_id' => $discovery->branch_log_collector_id,
            'name'           => $data['name'],
            'host'           => $discovery->host,
            'snmp_version'   => $data['snmp_version']   ?? '2c',
            'snmp_port'      => $data['snmp_port']      ?? 161,
            'snmp_community' => $data['snmp_community'] ?: 'public',
            'device_type'    => $data['device_type'],
            'enabled'        => true,
            'notes'          => $discovery->sys_descr ? "Auto-imported from discovery — {$discovery->sys_descr}" : null,
        ]);

        $discovery->update(['status' => 'approved']);

        return redirect()
            ->route('admin.snmp-devices.discovered')
            ->with('success', "Approved {$discovery->host} — branch will start polling on next sync.");
    }

    public function rejectDiscovered(SnmpDiscoveredDevice $discovery): RedirectResponse
    {
        $discovery->update(['status' => 'rejected']);
        return redirect()
            ->route('admin.snmp-devices.discovered')
            ->with('success', "Rejected {$discovery->host} — won't be re-suggested for 30 days.");
    }

    private function validated(Request $request, ?SnmpDevice $existing): array
    {
        return $request->validate([
            'branch_log_collector_id' => ['required', 'exists:branch_log_collectors,id'],
            'name'                    => ['required', 'string', 'max:100'],
            'host'                    => ['required', 'string', 'max:255',
                Rule::unique('snmp_devices', 'host')
                    ->ignore($existing?->id)
                    ->where(fn ($q) => $q->where('branch_log_collector_id', $request->branch_log_collector_id)),
            ],
            'snmp_version'            => ['required', Rule::in(['1', '2c', '3'])],
            'snmp_community'          => [$existing ? 'nullable' : 'required', 'string', 'max:128'],
            'snmp_port'               => ['required', 'integer', 'between:1,65535'],
            'device_type'             => ['required', 'string', Rule::in(array_keys(SnmpDevice::TYPES))],
            'polling_interval_s'      => ['required', 'integer', 'between:10,3600'],
            'enabled'                 => ['nullable', 'boolean'],
            'notes'                   => ['nullable', 'string', 'max:1000'],
        ]) + ['enabled' => $request->boolean('enabled')];
    }
}
