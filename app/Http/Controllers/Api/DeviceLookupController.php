<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AzureDevice;
use App\Models\Employee;
use App\Models\IdentityUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/hr/device-lookup?upn=user@domain.com
 *
 * Returns hardware info (TeamViewer ID, CPU, MACs) for the device(s)
 * currently assigned to the given user.
 *
 * Protected by the same hr.api_key middleware as other HR API endpoints.
 */
class DeviceLookupController extends Controller
{
    public function lookup(Request $request): JsonResponse
    {
        $upn = trim($request->query('upn', ''));

        if (empty($upn)) {
            return response()->json([
                'ok'    => false,
                'error' => 'Missing required query parameter: upn',
            ], 422);
        }

        // ── 1. Resolve Employee ────────────────────────────────────
        // Try direct email match first, then chain through IdentityUser
        $employee = Employee::where('email', $upn)->first();

        if (! $employee) {
            $identity = IdentityUser::where('user_principal_name', $upn)
                ->orWhere('mail', $upn)
                ->first();

            if ($identity) {
                $employee = $identity->azure_id
                    ? Employee::where('azure_id', $identity->azure_id)->first()
                    : null;
                $employee = $employee
                    ?? ($identity->mail ? Employee::where('email', $identity->mail)->first() : null);
            }
        }

        if (! $employee) {
            return response()->json([
                'ok'      => false,
                'error'   => 'No employee found for the given UPN.',
                'upn'     => $upn,
                'devices' => [],
            ], 404);
        }

        // ── 2. Find currently assigned devices ────────────────────
        $assignments = $employee->assetAssignments()
            ->whereNull('returned_date')
            ->with([
                'device.azureDevice',
                'device.branch',
            ])
            ->get();

        if ($assignments->isEmpty()) {
            return response()->json([
                'ok'          => true,
                'upn'         => $upn,
                'employee'    => $employee->name,
                'devices'     => [],
                'message'     => 'Employee found but has no currently assigned devices.',
            ]);
        }

        // ── 3. Build response payload ─────────────────────────────
        $normMac = fn(?string $m) => $m
            ? strtoupper(implode(':', str_split(strtoupper(preg_replace('/[^a-fA-F0-9]/', '', $m)), 2)))
            : null;

        $devices = $assignments->map(function ($assignment) use ($normMac) {
            $device = $assignment->device;
            $az     = $device?->azureDevice;

            $entry = [
                'asset_code'    => $device?->asset_code,
                'device_name'   => $device?->name,
                'type'          => $device?->type,
                'model'         => $device?->model,
                'serial'        => $device?->serial_number,
                'branch'        => $device?->branch?->name,
                'assigned_since'=> $assignment->assigned_date?->toDateString(),

                // Network
                'ip_address'    => $device?->ip_address,
                'mac_address'   => $normMac($device?->mac_address),
                'wifi_mac'      => $normMac($device?->wifi_mac),

                // Azure / Intune identity
                'azure_device'  => $az ? [
                    'display_name' => $az->display_name,
                    'upn'          => $az->upn,
                    'os'           => trim(($az->os ?? '') . ' ' . ($az->os_version ?? '')),
                    'last_sync'    => $az->last_sync_at?->toIso8601String(),
                ] : null,

                // Hardware / TeamViewer (from Intune script sync)
                'hw_synced_at'  => $az?->net_data_synced_at?->toIso8601String(),
                'cpu'           => $az?->cpu_name,
                'teamviewer_id' => $az?->teamviewer_id,
                'tv_version'    => $az?->tv_version,
                'ethernet_mac'  => $normMac($az?->ethernet_mac),
                'wifi_mac_intune'=> $normMac($az?->wifi_mac),
                'usb_adapters'  => $az ? $az->usb_eth_decoded() : [],
            ];

            return $entry;
        })->values();

        // ── 4. Flat TeamViewer shortcut (most common use-case) ────
        // If only one device, expose teamviewer_id at the top level for easy access
        $primaryTv = $devices->whereNotNull('teamviewer_id')->first();

        return response()->json([
            'ok'          => true,
            'upn'         => $upn,
            'employee'    => $employee->name,
            'teamviewer_id' => $primaryTv['teamviewer_id'] ?? null,
            'tv_version'    => $primaryTv['tv_version']    ?? null,
            'devices'     => $devices,
        ]);
    }
}
