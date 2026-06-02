<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Device;
use App\Models\Employee;
use App\Models\PhoneAccount;

/**
 * Builds the unified phone inventory by joining GDMS (authoritative for what
 * hardware exists) with the local ITAM / SIP / employee data:
 *
 *   GDMS device list  → what phones exist (MAC, model, IP, firmware, online)
 *   phone_accounts    → MAC → SIP user ID (populated by gdms:sync-device-accounts)
 *   contacts          → SIP user ID → person
 *   employees         → person → ITAM employee
 *   devices           → MAC/serial → ITAM asset record
 *   employee_assets   → current assignment
 *
 * Extracted from PhoneAutoAssignController so both the auto-assign screen and
 * the phone-management screens share one source of truth.
 */
class PhoneInventoryService
{
    public function __construct(private GdmsService $gdms) {}

    /**
     * Normalize a MAC to bare lowercase hex (ec74d7800474).
     */
    public function normMac(?string $raw): string
    {
        return strtolower(preg_replace('/[^a-fA-F0-9]/', '', $raw ?? ''));
    }

    /**
     * Build the full inventory.
     *
     * @return array{results: array<int, array<string, mixed>>, gdmsError: ?string, allEmployees: \Illuminate\Support\Collection}
     */
    public function build(): array
    {
        $gdmsError   = null;
        $gdmsDevices = [];   // normalized mac → raw GDMS array

        try {
            $rawDevices = $this->gdms->listAllPhoneDevices();
            foreach ($rawDevices as $d) {
                $mac = $this->normMac($d['mac'] ?? $d['macAddr'] ?? '');
                if (strlen($mac) === 12) {
                    $gdmsDevices[$mac] = $d;
                }
            }
        } catch (\Throwable $e) {
            $gdmsError = 'GDMS unavailable: '.$e->getMessage();
        }

        // Include phones already in our DB that GDMS may have missed / been offline for.
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

        $allEmployees = Employee::orderBy('name')
            ->get(['id', 'name', 'email', 'extension_number', 'branch_id']);

        if (empty($allMacs)) {
            return ['results' => [], 'gdmsError' => $gdmsError, 'allEmployees' => $allEmployees];
        }

        // ── Batch DB lookups ─────────────────────────────────────────────
        $devicesByMac = Device::whereIn('mac_address', $allMacs)
            ->with(['currentAssignment.employee'])
            ->get()
            ->keyBy('mac_address');

        // Also index by serial for GDMS devices not yet in DB by MAC.
        $gdmsSerials = array_filter(array_column(array_values($gdmsDevices), 'sn'));
        $devicesBySerial = ! empty($gdmsSerials)
            ? Device::whereIn('serial_number', $gdmsSerials)
                ->with(['currentAssignment.employee'])
                ->get()->keyBy('serial_number')
            : collect();

        $phoneAccountsByMac = PhoneAccount::whereIn('mac', $allMacs)
            ->get()
            ->groupBy('mac');

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

            $employeesByExt = Employee::whereIn('extension_number', $allSipIds)
                ->get()
                ->keyBy('extension_number');
        }

        // ── Build result rows ────────────────────────────────────────────
        $results = [];

        foreach ($allMacs as $mac) {
            $gdmsData = $gdmsDevices[$mac] ?? null;

            $device = $devicesByMac[$mac] ?? null;
            if (! $device && $gdmsData && filled($gdmsData['sn'] ?? '')) {
                $device = $devicesBySerial[$gdmsData['sn']] ?? null;
            }

            $accounts = $phoneAccountsByMac[$mac] ?? collect();

            $primaryAcc = $accounts->first(fn ($a) => filled($a->sip_user_id));
            $sipUserId  = $primaryAcc?->sip_user_id;

            $contact  = $sipUserId ? ($contactsByPhone[$sipUserId] ?? null) : null;
            $employee = null;
            if ($contact) {
                $employee = $employeesByContact[$contact->id] ?? null;
            }
            if (! $employee && $sipUserId) {
                $employee = $employeesByExt[$sipUserId] ?? null;
            }

            $currentAssignment = $device?->currentAssignment;
            $assignedEmployee  = $currentAssignment?->employee;

            $model    = $gdmsData['productName']     ?? $device?->model;
            $ip       = $gdmsData['deviceIp']        ?? $device?->ip_address;
            $online   = $gdmsData !== null ? ($gdmsData['deviceStatus'] === 1) : null;
            $firmware = $gdmsData['firmwareVersion'] ?? $device?->firmware_version;
            $serial   = $gdmsData['sn']              ?? $device?->serial_number;

            if (! $device) {
                $status = 'no_asset';
            } elseif (! $sipUserId) {
                $status = 'no_account';
            } elseif (! $employee) {
                $status = 'no_employee';
            } elseif ($currentAssignment && $currentAssignment->employee_id === $employee->id) {
                $status = 'assigned';
            } elseif ($currentAssignment) {
                $status = 'wrong_employee';
            } else {
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

        // Sort: action-needed first, correctly-assigned last.
        $order = [
            'ready'          => 0,
            'no_asset'       => 1,
            'wrong_employee' => 2,
            'no_account'     => 3,
            'no_employee'    => 4,
            'assigned'       => 5,
        ];
        usort($results, fn ($a, $b) => ($order[$a['status']] ?? 9) <=> ($order[$b['status']] ?? 9));

        return ['results' => $results, 'gdmsError' => $gdmsError, 'allEmployees' => $allEmployees];
    }
}
