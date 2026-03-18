<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AssetHistory;
use App\Models\Device;
use App\Models\Employee;
use App\Models\EmployeeAsset;
use App\Models\PhoneAccount;
use App\Models\PhonePortMap;
use App\Models\PhoneRequestLog;
use App\Models\UcmExtensionCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PhoneAutoAssignController extends Controller
{
    /**
     * Show review table of employees matched to phone devices via extension.
     * Uses batched queries instead of per-employee lookups for performance.
     */
    public function index()
    {
        $this->authorize('manage-assets');

        // 1. Load employees with extensions (from employee or linked contact)
        $employees = Employee::where('status', 'active')
            ->where(function ($q) {
                $q->where(function ($q2) {
                    $q2->whereNotNull('extension_number')->where('extension_number', '!=', '');
                })->orWhereHas('contact', function ($q2) {
                    $q2->whereNotNull('phone')->where('phone', '!=', '');
                });
            })
            ->with(['branch.ucmServer', 'ucmServer', 'contact'])
            ->orderBy('name')
            ->get();

        // Build extension list
        $extMap = []; // extension => employee
        foreach ($employees as $emp) {
            $ext = $emp->extension_number ?: ($emp->contact?->phone ?? null);
            if ($ext) {
                $extMap[$ext] = $emp;
            }
        }

        if (empty($extMap)) {
            return view('admin.devices.phone-auto-assign', ['results' => []]);
        }

        $extensions = array_keys($extMap);

        // 2. Batch: PhoneAccount lookup (extension → MAC)
        $phoneAccounts = PhoneAccount::whereIn('sip_user_id', $extensions)
            ->get()
            ->keyBy('sip_user_id');

        // 3. Batch: PhonePortMap lookup (extension → MAC)
        $portMaps = PhonePortMap::whereIn('extension', $extensions)
            ->get()
            ->keyBy('extension');

        // 4. Batch: UcmExtensionCache (extension → status/ip)
        $ucmCaches = UcmExtensionCache::whereIn('extension', $extensions)
            ->get()
            ->keyBy('extension');

        // Helper: normalize MAC to lowercase 12-char hex (strip colons, dashes, dots)
        $normalizeMac = fn($raw) => strtolower(preg_replace('/[^a-fA-F0-9]/', '', $raw ?? ''));

        // 5. Collect all MACs we found
        $allMacs = collect();
        foreach ($extensions as $ext) {
            $pa = $phoneAccounts[$ext] ?? null;
            $pm = $portMaps[$ext] ?? null;
            if ($pa && $pa->mac) $allMacs->push($normalizeMac($pa->mac));
            if ($pm && $pm->phone_mac) $allMacs->push($normalizeMac($pm->phone_mac));
        }
        $allMacs = $allMacs->filter(fn($m) => strlen($m) >= 12)->unique()->values();

        // 6. Batch: Load all devices by MAC (with current assignment)
        $devicesByMac = $allMacs->isNotEmpty()
            ? Device::whereIn('mac_address', $allMacs)
                ->with('currentAssignment')
                ->get()
                ->keyBy('mac_address')
            : collect();

        // 7. Batch: PhoneRequestLog for model/ip enrichment
        $phoneLogs = $allMacs->isNotEmpty()
            ? PhoneRequestLog::whereIn('mac', $allMacs)
                ->select('mac', 'model', 'ip', DB::raw('MAX(created_at) as last_at'))
                ->groupBy('mac', 'model', 'ip')
                ->get()
                ->keyBy('mac')
            : collect();

        // 8. Build results
        $results = [];
        foreach ($extMap as $ext => $emp) {
            $pa  = $phoneAccounts[$ext] ?? null;
            $pm  = $portMaps[$ext] ?? null;
            $ucm = $ucmCaches[$ext] ?? null;

            // Resolve MAC: PhoneAccount first, then PhonePortMap (normalize to plain hex)
            $mac = null;
            $source = null;
            if ($pa && $pa->mac) {
                $mac = $normalizeMac($pa->mac);
                $source = 'PhoneAccount';
            } elseif ($pm && $pm->phone_mac) {
                $mac = $normalizeMac($pm->phone_mac);
                $source = 'PhonePortMap';
            }
            // Ensure valid 12-char MAC
            if ($mac && strlen($mac) < 12) $mac = null;

            $device = $mac ? ($devicesByMac[$mac] ?? null) : null;
            $prl    = $mac ? ($phoneLogs[$mac] ?? null) : null;

            // Resolve IP — only keep private/local IPs (10.x, 172.16-31.x, 192.168.x)
            $ip = collect([
                $device?->ip_address,
                $pm?->phone_ip,
                $ucm?->ip_address,
                $prl?->ip,
            ])->first(fn ($v) => $v && filter_var($v, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE) && !filter_var($v, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE));

            // Resolve model
            $model = $device?->model ?? $prl?->model;

            // Resolve status
            $regStatus = $pa?->account_status ?? $ucm?->status;

            // Assignment status
            $assignStatus = 'not_found';
            if ($device) {
                $ca = $device->currentAssignment;
                if ($ca && $ca->employee_id === $emp->id) {
                    $assignStatus = 'already_assigned';
                } elseif ($ca) {
                    $assignStatus = 'assigned_elsewhere';
                } else {
                    $assignStatus = 'available';
                }
            }

            $results[] = [
                'employee' => $emp,
                'device'   => $device,
                'mac'      => $mac,
                'ip'       => $ip,
                'model'    => $model,
                'source'   => $source,
                'status'   => $assignStatus,
            ];
        }

        // Sort: available first
        $order = ['available' => 0, 'already_assigned' => 1, 'assigned_elsewhere' => 2, 'not_found' => 3];
        usort($results, fn ($a, $b) => ($order[$a['status']] ?? 9) <=> ($order[$b['status']] ?? 9));

        return view('admin.devices.phone-auto-assign', compact('results'));
    }

    /**
     * Process confirmed bulk phone-device assignments.
     */
    public function store(Request $request)
    {
        $this->authorize('manage-assets');

        $request->validate([
            'assignments'   => 'required|array|min:1',
            'assignments.*' => 'required|string',
        ]);

        $count  = 0;
        $errors = [];

        DB::transaction(function () use ($request, &$count, &$errors) {
            foreach ($request->assignments as $pair) {
                [$employeeId, $deviceId] = explode(':', $pair);

                $device   = Device::find($deviceId);
                $employee = Employee::find($employeeId);

                if (!$device || !$employee) {
                    $errors[] = "Invalid pair: {$pair}";
                    continue;
                }

                if (EmployeeAsset::where('asset_id', $deviceId)->whereNull('returned_date')->exists()) {
                    $errors[] = "\"{$device->name}\" is already assigned — skipped.";
                    continue;
                }

                EmployeeAsset::create([
                    'employee_id'   => $employeeId,
                    'asset_id'      => $deviceId,
                    'assigned_date' => now()->toDateString(),
                    'condition'     => 'good',
                    'notes'         => 'Auto-assigned via phone extension matching',
                ]);

                $device->update(['status' => 'assigned']);
                $extNum = $employee->extension_number ?: ($employee->contact?->phone ?? '');
                AssetHistory::record($device, 'assigned',
                    "Auto-assigned to {$employee->name} via extension {$extNum}");
                $count++;
            }
        });

        ActivityLog::log("Auto-assigned {$count} phone device(s) to employees");

        $msg = "Successfully assigned {$count} device(s).";
        if (!empty($errors)) {
            $msg .= ' ' . implode(' ', $errors);
        }

        return redirect()->route('admin.devices.phone-auto-assign')
            ->with($count > 0 ? 'success' : 'error', $msg);
    }
}
