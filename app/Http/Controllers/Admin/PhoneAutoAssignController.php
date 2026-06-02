<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AssetHistory;
use App\Models\Device;
use App\Models\Employee;
use App\Models\EmployeeAsset;
use App\Services\GdmsService;
use App\Services\PhoneInventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PhoneAutoAssignController extends Controller
{
    // ─────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────

    private function normMac(?string $raw): string
    {
        return strtolower(preg_replace('/[^a-fA-F0-9]/', '', $raw ?? ''));
    }

    // ─────────────────────────────────────────────────────────────
    // Index — GDMS-centric review
    // ─────────────────────────────────────────────────────────────

    /**
     * Build a full picture of all VoIP phones:
     *   source = GDMS device list  (authoritative for what hardware exists)
     *   +  phone_accounts table    (MAC → SIP user ID, populated by gdms:sync-device-accounts)
     *   +  contacts table          (SIP user ID → person)
     *   +  employees table         (person → ITAM employee)
     *   +  devices table           (MAC → ITAM asset record)
     *   +  employee_assets table   (current assignment)
     */
    public function index()
    {
        $this->authorize('manage-assets');

        // The GDMS ⨯ ITAM ⨯ SIP ⨯ employee join lives in PhoneInventoryService,
        // shared with the Phone Management screens.
        ['results' => $results, 'gdmsError' => $gdmsError, 'allEmployees' => $allEmployees]
            = app(PhoneInventoryService::class)->build();

        return view('admin.devices.phone-auto-assign', compact('results', 'gdmsError', 'allEmployees'));
    }

    // ─────────────────────────────────────────────────────────────
    // Create missing Device assets from GDMS
    // ─────────────────────────────────────────────────────────────

    public function createAssets(Request $request)
    {
        $this->authorize('manage-assets');

        $gdms       = app(GdmsService::class);
        $rawDevices = $gdms->listAllPhoneDevices();
        $created    = 0;
        $skipped    = 0;

        DB::transaction(function () use ($rawDevices, &$created, &$skipped) {
            foreach ($rawDevices as $d) {
                $mac = $this->normMac($d['mac'] ?? $d['macAddr'] ?? '');
                if (strlen($mac) !== 12) {
                    $skipped++;
                    continue;
                }

                // Skip if already in DB by MAC or serial
                $serial = $d['sn'] ?? null;
                if (Device::where('mac_address', $mac)->exists()) { $skipped++; continue; }
                if ($serial && Device::where('serial_number', $serial)->exists()) { $skipped++; continue; }

                $model    = $d['productName'] ?? 'GRP-Phone';
                $ip       = $d['deviceIp']    ?? null;
                $macUpper = strtoupper($mac);

                // Asset code: MODEL-LAST6OFMAC  e.g. GRP2601-B04474
                $assetCode = strtoupper($model) . '-' . substr($macUpper, 6);

                // Human name: model + colon-separated MAC
                $macFormatted = strtoupper(implode(':', str_split($mac, 2)));
                $name         = $model . ' ' . $macFormatted;

                Device::create([
                    'name'             => $name,
                    'type'             => 'phone',
                    'mac_address'      => $mac,
                    'serial_number'    => $serial,
                    'ip_address'       => $ip,
                    'model'            => $model,
                    'manufacturer'     => 'Grandstream',
                    'asset_code'       => $assetCode,
                    'firmware_version' => $d['firmwareVersion'] ?? null,
                    'status'           => 'available',
                    'source'           => 'gdms',
                    // (source, source_id) has a composite unique index — give
                    // every GDMS-imported phone its own source_id so the
                    // second insert doesn't collide on 'gdms-' with the first.
                    // MAC is the natural GDMS identity.
                    'source_id'        => $mac,
                ]);

                $created++;
            }
        });

        ActivityLog::log("Phone assets created from GDMS", [
            'created' => $created,
            'skipped' => $skipped,
        ]);

        return redirect()->route('admin.devices.phone-auto-assign')
            ->with('success', "Created {$created} new phone asset(s) from GDMS. {$skipped} already existed.");
    }

    // ─────────────────────────────────────────────────────────────
    // Bulk assign selected phones to employees
    // ─────────────────────────────────────────────────────────────

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

                if (! $device || ! $employee) {
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
                    'notes'         => 'Auto-assigned via GDMS phone extension matching',
                ]);

                $device->update(['status' => 'assigned']);

                $extNum = $employee->extension_number
                    ?: ($employee->contact?->phone ?? '');

                AssetHistory::record($device, 'assigned',
                    "Auto-assigned to {$employee->name} via extension {$extNum} (GDMS sync)");

                $count++;
            }
        });

        ActivityLog::log("Auto-assigned {$count} phone device(s) to employees via GDMS");

        $msg = "Successfully assigned {$count} device(s).";
        if (! empty($errors)) {
            $msg .= ' ' . implode(' ', $errors);
        }

        return redirect()->route('admin.devices.phone-auto-assign')
            ->with($count > 0 ? 'success' : 'error', $msg);
    }

    // ─────────────────────────────────────────────────────────────
    // Manual assign — for phones with no GDMS/SIP match (no_account /
    // no_employee), or to override an existing/auto-suggested assignment.
    // ─────────────────────────────────────────────────────────────

    public function manualAssign(Request $request)
    {
        $this->authorize('manage-assets');

        $validated = $request->validate([
            'device_id' => 'required|integer|exists:devices,id',
            'employee_id' => 'required|integer|exists:employees,id',
            'notes' => 'nullable|string|max:500',
        ]);

        $device = Device::find($validated['device_id']);
        $employee = Employee::find($validated['employee_id']);

        if ($device->type !== 'phone') {
            return back()->with('error', 'Selected asset is not a phone.');
        }

        DB::transaction(function () use ($device, $employee, $validated) {
            // Close any existing open assignment so the device has at most
            // one active assignment at a time.
            $existing = EmployeeAsset::where('asset_id', $device->id)
                ->whereNull('returned_date')
                ->get();

            foreach ($existing as $prev) {
                $prev->update([
                    'returned_date' => now()->toDateString(),
                    'notes' => trim(($prev->notes ?: '').' [closed on manual reassign]'),
                ]);
                AssetHistory::record($device, 'returned',
                    'Previous assignment closed during manual reassign.');
            }

            EmployeeAsset::create([
                'employee_id' => $employee->id,
                'asset_id' => $device->id,
                'assigned_date' => now()->toDateString(),
                'condition' => 'good',
                'notes' => $validated['notes']
                    ?: 'Manually assigned via Phone Auto-Assign page.',
            ]);

            $device->update(['status' => 'assigned']);

            AssetHistory::record($device, 'assigned',
                "Manually assigned to {$employee->name} via Phone Auto-Assign page.");
        });

        ActivityLog::log("Manually assigned phone '{$device->name}' to {$employee->name}");

        return redirect()->route('admin.devices.phone-auto-assign')
            ->with('success', "Phone '{$device->name}' assigned to {$employee->name}.");
    }
}
