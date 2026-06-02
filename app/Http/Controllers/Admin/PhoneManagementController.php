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
use App\Models\UcmServer;
use App\Services\GdmsService;
use App\Services\IppbxApiService;
use App\Services\PhoneInventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GDMS phone management: inventory, provisioning (claim a phone into GDMS by
 * MAC + serial), per-phone detail, and device actions (reboot, factory reset,
 * assign SIP account, push config). Auto-links phones to ITAM assets + employees
 * through PhoneInventoryService.
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
        $q      = trim((string) $request->query('q', ''));

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

        $sites     = $this->safeSites();
        $employees = Employee::orderBy('name')->get(['id', 'name', 'extension_number']);

        return view('admin.phones.create', compact('sites', 'employees'));
    }

    public function store(Request $request)
    {
        $this->authorize('manage-phones');

        $validated = $request->validate([
            'mac'         => ['required', 'string', 'regex:/^[0-9a-fA-F:\-\.]{12,17}$/'],
            'sn'          => ['required', 'string', 'max:64'],
            'name'        => ['nullable', 'string', 'max:120'],
            'site_id'     => ['nullable', 'integer'],
            'model'       => ['nullable', 'string', 'max:60'],
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

        $accounts    = $detail['sipAccountList'] ?? $detail['fxsPortList'] ?? [];
        $employees   = Employee::orderBy('name')->get(['id', 'name', 'extension_number']);
        $ucmServers  = UcmServer::active()->orderBy('name')->get();
        $recentTasks = GdmsTask::where('mac', $mac)->latest()->limit(10)->get();

        return view('admin.phones.show', compact(
            'mac', 'device', 'detail', 'detailError', 'accounts', 'employees', 'ucmServers', 'recentTasks'
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

    public function factoryReset(Request $request, string $mac)
    {
        // reset-phones is super_admin-only (destructive).
        $this->authorize('reset-phones');
        $mac = $this->normMac($mac);

        return $this->runTask($mac, GdmsTask::TYPE_FACTORY_RESET, fn () => $this->gdms->factoryResetDevices([$mac]), 'Factory reset');
    }

    /**
     * Assign / change the SIP account on a phone account slot.
     * Reads the extension's credentials from the UCM, then binds it on the phone
     * via GDMS (native account bind, falling back to a per-device config push).
     */
    public function assignAccount(Request $request, string $mac)
    {
        $this->authorize('manage-phones');
        $mac = $this->normMac($mac);

        $validated = $request->validate([
            'ucm_server_id' => ['required', 'integer', 'exists:ucm_servers,id'],
            'extension'     => ['required', 'string', 'max:20'],
            'account_index' => ['required', 'integer', 'min:1', 'max:16'],
        ]);

        $ucm = UcmServer::findOrFail($validated['ucm_server_id']);

        try {
            $wave = (new IppbxApiService($ucm))->getExtensionWave($validated['extension']);
        } catch (\Throwable $e) {
            return back()->with('error', 'Could not read extension from UCM: '.$e->getMessage());
        }

        $sipAccount = [
            'userId'      => $wave['extension'],
            'authId'      => $wave['extension'],
            'password'    => $wave['secret'],
            'sipServer'   => $wave['server'],
            'displayName' => $wave['fullname'] ?: $wave['extension'],
        ];

        $task = GdmsTask::create([
            'mac'                  => $mac,
            'device_id'            => Device::where('mac_address', $mac)->value('id'),
            'task_type'            => GdmsTask::TYPE_ASSIGN_ACCOUNT,
            'status'               => 'queued',
            // Never persist the SIP secret.
            'payload'              => ['extension' => $validated['extension'], 'account_index' => $validated['account_index'], 'ucm_server_id' => $ucm->id],
            'requested_by_user_id' => auth()->id(),
        ]);

        try {
            try {
                $result = $this->gdms->assignSipAccountToDevice($mac, (int) $validated['account_index'], $sipAccount);
            } catch (\Throwable $bindError) {
                // Fallback: push the account-slot P-values directly to the device.
                $result = $this->gdms->pushConfig($mac, $this->accountPValues((int) $validated['account_index'], $sipAccount));
                $result['_fallback'] = 'pushConfig: '.$bindError->getMessage();
            }

            $task->update(['status' => 'sent', 'result' => $this->scrub($result)]);
            ActivityLog::log('GDMS account assigned to phone', [
                'mac' => $mac, 'extension' => $validated['extension'], 'slot' => $validated['account_index'],
            ]);

            return back()->with('success', "Account {$validated['extension']} assigned to {$mac} (slot {$validated['account_index']}). It may take a moment to register.");
        } catch (\Throwable $e) {
            $task->update(['status' => 'failed', 'result' => ['error' => $e->getMessage()]]);

            return back()->with('error', 'Assign failed: '.$e->getMessage());
        }
    }

    /**
     * Push one-off custom configuration parameters (P-values) to a single phone.
     */
    public function pushConfig(Request $request, string $mac)
    {
        $this->authorize('manage-phones');
        $mac = $this->normMac($mac);

        $validated = $request->validate([
            'params' => ['required', 'string'],
        ]);

        $params = $this->parseParams($validated['params']);
        if (empty($params)) {
            return back()->with('error', 'No valid KEY=VALUE parameters were provided.');
        }

        $task = GdmsTask::create([
            'mac'                  => $mac,
            'device_id'            => Device::where('mac_address', $mac)->value('id'),
            'task_type'            => GdmsTask::TYPE_CONFIG_PUSH,
            'status'               => 'queued',
            'payload'              => ['params' => array_keys($params)],
            'requested_by_user_id' => auth()->id(),
        ]);

        try {
            $result = $this->gdms->pushConfig($mac, $params);
            $task->update(['status' => 'sent', 'result' => $this->scrub($result)]);
            ActivityLog::log('GDMS config pushed to phone', ['mac' => $mac, 'keys' => array_keys($params)]);

            return back()->with('success', 'Configuration pushed to '.$mac.'.');
        } catch (\Throwable $e) {
            $task->update(['status' => 'failed', 'result' => ['error' => $e->getMessage()]]);

            return back()->with('error', 'Config push failed: '.$e->getMessage());
        }
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
            'mac'                  => $mac,
            'device_id'            => Device::where('mac_address', $mac)->value('id'),
            'task_type'            => $type,
            'status'               => 'queued',
            'requested_by_user_id' => auth()->id(),
        ]);

        try {
            $result = $fn();
            $task->update([
                'status'       => 'sent',
                'result'       => $this->scrub($result),
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

        $model        = $model ?: 'GRP-Phone';
        $macUpper     = strtoupper($mac);
        $assetCode    = strtoupper($model).'-'.substr($macUpper, 6);
        $macFormatted = strtoupper(implode(':', str_split($mac, 2)));

        return Device::create([
            'name'          => $name ?: ($model.' '.$macFormatted),
            'type'          => 'phone',
            'mac_address'   => $mac,
            'serial_number' => $serial,
            'model'         => $model,
            'manufacturer'  => 'Grandstream',
            'asset_code'    => $assetCode,
            'status'        => 'available',
            'source'        => 'gdms',
            'source_id'     => $mac,
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
                        'notes'         => trim(($prev->notes ?: '').' [closed on reassign]'),
                    ]);
                    AssetHistory::record($device, 'returned', 'Previous assignment closed during phone (re)assign.');
                });

            EmployeeAsset::create([
                'employee_id'   => $employeeId,
                'asset_id'      => $device->id,
                'assigned_date' => now()->toDateString(),
                'condition'     => 'good',
                'notes'         => $note,
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
     * Parse a textarea of newline-separated KEY=VALUE pairs into a param map.
     */
    private function parseParams(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            if ($k !== '') {
                $out[$k] = trim($v);
            }
        }

        return $out;
    }

    /**
     * Fallback only: map a SIP account to Grandstream account-slot P-values for
     * a per-device config push. P-value keys differ across models/slots; the
     * set below is for GRP-series account 1 and is PROBE-PENDING for other slots
     * (the native assignSipAccountToDevice() path is preferred).
     */
    private function accountPValues(int $index, array $sip): array
    {
        if ($index === 1) {
            return [
                'P271' => '1',                       // account 1 active
                'P47'  => $sip['sipServer'] ?? '',   // SIP server
                'P35'  => $sip['userId'] ?? '',      // SIP user ID
                'P36'  => $sip['authId'] ?? '',      // authenticate ID
                'P34'  => $sip['password'] ?? '',    // authenticate password
                'P3'   => $sip['displayName'] ?? '', // account name
            ];
        }

        throw new \RuntimeException(
            "Per-device P-value fallback currently supports account slot 1 only; "
            ."confirm slot {$index} P-value offsets via `gdms:probe` first."
        );
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
