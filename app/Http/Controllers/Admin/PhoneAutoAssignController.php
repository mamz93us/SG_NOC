<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AssetHistory;
use App\Models\Contact;
use App\Models\Device;
use App\Models\Employee;
use App\Models\EmployeeAsset;
use App\Models\PhoneAccount;
use App\Services\GdmsService;
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

        $gdmsError   = null;
        $gdmsDevices = [];   // normalized mac → raw GDMS array

        try {
            $gdms       = app(GdmsService::class);
            $rawDevices = $gdms->listAllPhoneDevices();
            foreach ($rawDevices as $d) {
                $mac = $this->normMac($d['mac'] ?? $d['macAddr'] ?? '');
                if (strlen($mac) === 12) {
                    $gdmsDevices[$mac] = $d;
                }
            }
        } catch (\Throwable $e) {
            $gdmsError = 'GDMS unavailable: ' . $e->getMessage();
        }

        // Include phones already in our DB that GDMS may have missed / been offline for
        $dbPhoneMacs = Device::where('type', 'phone')
            ->whereNotNull('mac_address')
            ->pluck('mac_address')
            ->all();

        $allMacs = collect(array_keys($gdmsDevices))
            ->merge($dbPhoneMacs)
            ->filter(fn ($m) => strlen($m) === 12)
            ->unique()
            ->values()
            ->all();

        if (empty($allMacs)) {
            return view('admin.devices.phone-auto-assign', [
                'results'   => [],
                'gdmsError' => $gdmsError,
            ]);
        }

        // ── Batch DB lookups ─────────────────────────────────────────────

        $devicesByMac = Device::whereIn('mac_address', $allMacs)
            ->with(['currentAssignment.employee'])
            ->get()
            ->keyBy('mac_address');

        // Also index by serial for GDMS devices not yet in DB by MAC
        $gdmsSerials = array_filter(array_column(array_values($gdmsDevices), 'sn'));
        $devicesBySerial = ! empty($gdmsSerials)
            ? Device::whereIn('serial_number', $gdmsSerials)
                ->with(['currentAssignment.employee'])
                ->get()->keyBy('serial_number')
            : collect();

        $phoneAccountsByMac = PhoneAccount::whereIn('mac', $allMacs)
            ->get()
            ->groupBy('mac');

        // All SIP user IDs we know about for these MACs
        $allSipIds = PhoneAccount::whereIn('mac', $allMacs)
            ->whereNotNull('sip_user_id')
            ->where('sip_user_id', '!=', '')
            ->pluck('sip_user_id')
            ->unique()
            ->filter()
            ->values()
            ->all();

        $contactsByPhone    = collect();
        $employeesByContact = collect();
        $employeesByExt     = collect();

        if (! empty($allSipIds)) {
            $contactsByPhone = Contact::whereIn('phone', $allSipIds)
                ->get()
                ->keyBy('phone');

            $contactIds = $contactsByPhone->pluck('id')->filter()->all();
            if (! empty($contactIds)) {
                $employeesByContact = Employee::whereIn('contact_id', $contactIds)
                    ->get()
                    ->keyBy('contact_id');
            }

            // Also match via employee.extension_number (some may not have a contact link)
            $employeesByExt = Employee::whereIn('extension_number', $allSipIds)
                ->get()
                ->keyBy('extension_number');
        }

        // ── Build result rows ────────────────────────────────────────────

        $results = [];

        foreach ($allMacs as $mac) {
            $gdmsData  = $gdmsDevices[$mac] ?? null;

            // Try MAC first, then fall back to serial number lookup
            $device = $devicesByMac[$mac] ?? null;
            if (! $device && $gdmsData && filled($gdmsData['sn'] ?? '')) {
                $device = $devicesBySerial[$gdmsData['sn']] ?? null;
            }

            $accounts  = $phoneAccountsByMac[$mac] ?? collect();

            // Primary SIP account — first account slot that has a user ID
            $primaryAcc = $accounts->first(fn ($a) => filled($a->sip_user_id));
            $sipUserId  = $primaryAcc?->sip_user_id;

            // Resolve employee (contact link takes priority over extension match)
            $contact  = $sipUserId ? ($contactsByPhone[$sipUserId] ?? null) : null;
            $employee = null;
            if ($contact) {
                $employee = $employeesByContact[$contact->id] ?? null;
            }
            if (! $employee && $sipUserId) {
                $employee = $employeesByExt[$sipUserId] ?? null;
            }

            // Current ITAM assignment
            $currentAssignment = $device?->currentAssignment;
            $assignedEmployee  = $currentAssignment?->employee;

            // Resolve display info — GDMS normalised fields win over DB
            $model    = $gdmsData['productName']     ?? $device?->model;
            $ip       = $gdmsData['deviceIp']        ?? $device?->ip_address;
            $online   = $gdmsData !== null ? ($gdmsData['deviceStatus'] === 1) : null;
            $firmware = $gdmsData['firmwareVersion'] ?? $device?->firmware_version;
            $serial   = $gdmsData['sn']              ?? $device?->serial_number;

            // ── Status ──────────────────────────────────────────────────
            if (! $device) {
                // Phone seen in GDMS but no ITAM asset record created yet
                $status = 'no_asset';
            } elseif (! $sipUserId) {
                // We have the asset but no SIP account data yet (needs gdms:sync-device-accounts)
                $status = 'no_account';
            } elseif (! $employee) {
                // SIP user ID known but no employee matches this extension
                $status = 'no_employee';
            } elseif ($currentAssignment && $currentAssignment->employee_id === $employee->id) {
                // Correctly assigned to the right person
                $status = 'assigned';
            } elseif ($currentAssignment) {
                // Device is assigned, but to a different employee than the SIP user
                $status = 'wrong_employee';
            } else {
                // Device + employee found, not yet linked — ready to assign
                $status = 'ready';
            }

            $results[] = [
                'mac'              => $mac,
                'gdms'             => $gdmsData,
                'device'           => $device,
                'sipUserId'        => $sipUserId,
                'contact'          => $contact,
                'employee'         => $employee,
                'accounts'         => $accounts,
                'status'           => $status,
                'assignedEmployee' => $assignedEmployee,
                'model'            => $model,
                'ip'               => $ip,
                'online'           => $online,
                'firmware'         => $firmware,
                'serial'           => $serial,
            ];
        }

        // Sort: action-needed first, correctly-assigned last
        $order = [
            'ready'         => 0,
            'no_asset'      => 1,
            'wrong_employee'=> 2,
            'no_account'    => 3,
            'no_employee'   => 4,
            'assigned'      => 5,
        ];
        usort($results, fn ($a, $b) => ($order[$a['status']] ?? 9) <=> ($order[$b['status']] ?? 9));

        return view('admin.devices.phone-auto-assign', compact('results', 'gdmsError'));
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
}
