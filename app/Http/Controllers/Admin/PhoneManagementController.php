<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AssetHistory;
use App\Models\Device;
use App\Models\Employee;
use App\Models\EmployeeAsset;
use App\Models\GdmsTask;
use App\Models\Setting;
use App\Services\GdmsService;
use App\Services\PhoneInventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GDMS phone management: inventory, provisioning (claim a phone into GDMS by
 * MAC + serial), per-phone detail, and Reboot. Auto-links phones to ITAM assets
 * + employees through PhoneInventoryService.
 *
 * NOTE: assigning SIP accounts, pushing config/templates, and factory reset are
 * GDMS web-console operations — the GDMS OpenAPI doesn't expose them.
 */
class PhoneManagementController extends Controller
{
    public function __construct(private GdmsService $gdms) {}

    // ─────────────────────────────────────────────────────────────
    // Inventory
    // ─────────────────────────────────────────────────────────────

    public function index(Request $request, PhoneInventoryService $inventory)
    {
        $this->authorize('view-phones');

        ['results' => $results, 'gdmsError' => $gdmsError, 'allEmployees' => $allEmployees]
            = $inventory->build();

        // Status counts for the filter chips (computed before filtering).
        $counts = collect($results)->countBy('status')->all();
        $counts['all'] = count($results);

        $status = $request->query('status');
        $q = trim((string) $request->query('q', ''));

        if ($status && $status !== 'all') {
            $results = array_values(array_filter($results, fn ($r) => $r['status'] === $status));
        }

        if ($q !== '') {
            $needleRaw = strtolower($q);
            $needleMac = preg_replace('/[^a-f0-9]/', '', $needleRaw);
            $results = array_values(array_filter($results, function ($r) use ($needleRaw, $needleMac) {
                return ($needleMac !== '' && str_contains($r['mac'], $needleMac))
                    || str_contains(strtolower((string) ($r['model'] ?? '')), $needleRaw)
                    || str_contains(strtolower((string) ($r['sipUserId'] ?? '')), $needleRaw)
                    || str_contains(strtolower((string) ($r['employee']?->name ?? '')), $needleRaw)
                    || str_contains(strtolower((string) ($r['ip'] ?? '')), $needleRaw);
            }));
        }

        return view('admin.phones.index', compact('results', 'gdmsError', 'allEmployees', 'counts', 'status', 'q'));
    }

    // ─────────────────────────────────────────────────────────────
    // Provisioning — claim a phone into GDMS
    // ─────────────────────────────────────────────────────────────

    public function create()
    {
        $this->authorize('manage-phones');

        $sites = $this->safeSites();
        $employees = Employee::orderBy('name')->get(['id', 'name', 'extension_number']);

        return view('admin.phones.create', compact('sites', 'employees'));
    }

    public function store(Request $request)
    {
        $this->authorize('manage-phones');

        $validated = $request->validate([
            'mac' => ['required', 'string', 'regex:/^[0-9a-fA-F:\-\.]{12,17}$/'],
            'sn' => ['required', 'string', 'max:64'],
            'name' => ['nullable', 'string', 'max:120'],
            'site_id' => ['nullable', 'integer'],
            'model' => ['nullable', 'string', 'max:60'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
        ]);

        $mac = $this->normMac($validated['mac']);
        if (strlen($mac) !== 12) {
            return back()->withInput()->with('error', 'MAC address must be 12 hex digits.');
        }

        $siteId = $validated['site_id'] ?? $this->defaultSiteId();

        // 1. Claim the device in GDMS (MAC + serial proves ownership).
        try {
            $this->gdms->addDevice($mac, $validated['sn'], $validated['name'] ?? null, $siteId ? (int) $siteId : null);
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', 'GDMS add failed: '.$e->getMessage());
        }

        // 2. Create / reuse the ITAM asset record, optionally assign to an employee.
        $device = $this->ensureDeviceAsset($mac, $validated['sn'], $validated['model'] ?? null, $validated['name'] ?? null);

        if (! empty($validated['employee_id'])) {
            $this->assignEmployee($device, (int) $validated['employee_id'], 'Assigned when claimed into GDMS.');
        }

        ActivityLog::log('GDMS phone claimed', ['mac' => $mac, 'sn' => $validated['sn']]);

        return redirect()->route('admin.phones.show', $mac)
            ->with('success', "Phone {$mac} added to GDMS.");
    }

    // ─────────────────────────────────────────────────────────────
    // Detail
    // ─────────────────────────────────────────────────────────────

    public function show(string $mac)
    {
        $this->authorize('view-phones');

        $mac = $this->normMac($mac);
        abort_unless(strlen($mac) === 12, 404);

        $device = Device::where('mac_address', $mac)
            ->with('currentAssignment.employee')
            ->first();

        // Live device detail (blocks up to ~60s while the phone reports back).
        $detail = null;
        $detailError = null;
        try {
            $detail = $this->gdms->getDeviceDetailRaw($mac);
        } catch (\Throwable $e) {
            $detailError = $e->getMessage();
        }

        $accounts = $detail['sipAccountList'] ?? $detail['fxsPortList'] ?? [];
        $recentTasks = GdmsTask::where('mac', $mac)->latest()->limit(10)->get();

        return view('admin.phones.show', compact(
            'mac', 'device', 'detail', 'detailError', 'accounts', 'recentTasks'
        ));
    }

    // ─────────────────────────────────────────────────────────────
    // Device actions
    // ─────────────────────────────────────────────────────────────

    public function reboot(Request $request, string $mac)
    {
        $this->authorize('manage-phones');
        $mac = $this->normMac($mac);

        return $this->runTask($mac, GdmsTask::TYPE_REBOOT, fn () => $this->gdms->rebootDevices([$mac]), 'Reboot');
    }

    // ─────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────

    private function normMac(?string $raw): string
    {
        return strtolower(preg_replace('/[^a-fA-F0-9]/', '', $raw ?? ''));
    }

    /**
     * Run a GDMS device task closure, logging it to gdms_tasks either way.
     */
    private function runTask(string $mac, string $type, \Closure $fn, string $label)
    {
        $task = GdmsTask::create([
            'mac' => $mac,
            'device_id' => Device::where('mac_address', $mac)->value('id'),
            'task_type' => $type,
            'status' => 'queued',
            'requested_by_user_id' => auth()->id(),
        ]);

        try {
            $result = $fn();
            $task->update([
                'status' => 'sent',
                'result' => $this->scrub($result),
                'gdms_task_id' => $result['data']['taskId'] ?? $result['data']['id'] ?? null,
            ]);
            ActivityLog::log("GDMS {$label} requested", ['mac' => $mac]);

            return back()->with('success', "{$label} sent to {$mac}.");
        } catch (\Throwable $e) {
            $task->update(['status' => 'failed', 'result' => ['error' => $e->getMessage()]]);

            return back()->with('error', "{$label} failed: ".$e->getMessage());
        }
    }

    private function ensureDeviceAsset(string $mac, ?string $serial, ?string $model, ?string $name): Device
    {
        $device = Device::where('mac_address', $mac)->first();
        if (! $device && $serial) {
            $device = Device::where('serial_number', $serial)->first();
        }
        if ($device) {
            return $device;
        }

        $model = $model ?: 'GRP-Phone';
        $macUpper = strtoupper($mac);
        $assetCode = strtoupper($model).'-'.substr($macUpper, 6);
        $macFormatted = strtoupper(implode(':', str_split($mac, 2)));

        return Device::create([
            'name' => $name ?: ($model.' '.$macFormatted),
            'type' => 'phone',
            'mac_address' => $mac,
            'serial_number' => $serial,
            'model' => $model,
            'manufacturer' => 'Grandstream',
            'asset_code' => $assetCode,
            'status' => 'available',
            'source' => 'gdms',
            'source_id' => $mac,
        ]);
    }

    private function assignEmployee(Device $device, int $employeeId, string $note): void
    {
        DB::transaction(function () use ($device, $employeeId, $note) {
            EmployeeAsset::where('asset_id', $device->id)
                ->whereNull('returned_date')
                ->get()
                ->each(function ($prev) use ($device) {
                    $prev->update([
                        'returned_date' => now()->toDateString(),
                        'notes' => trim(($prev->notes ?: '').' [closed on reassign]'),
                    ]);
                    AssetHistory::record($device, 'returned', 'Previous assignment closed during phone (re)assign.');
                });

            EmployeeAsset::create([
                'employee_id' => $employeeId,
                'asset_id' => $device->id,
                'assigned_date' => now()->toDateString(),
                'condition' => 'good',
                'notes' => $note,
            ]);

            $device->update(['status' => 'assigned']);

            $emp = Employee::find($employeeId);
            AssetHistory::record($device, 'assigned', "Assigned to {$emp?->name} via Phone Management.");
        });
    }

    private function defaultSiteId(): ?int
    {
        $v = Setting::first()?->gdms_site_id ?: config('services.gdms.site_id');

        return $v ? (int) $v : null;
    }

    private function safeSites(): array
    {
        try {
            return $this->gdms->listSites();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Strip anything secret-looking from a GDMS result before persisting it.
     */
    private function scrub(array $result): array
    {
        array_walk_recursive($result, function (&$v, $k) {
            if (is_string($k) && preg_match('/(password|secret|authId|token)/i', $k)) {
                $v = '***';
            }
        });

        return $result;
    }
}
