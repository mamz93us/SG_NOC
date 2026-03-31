<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\DeviceMac;
use Illuminate\Http\Request;

class MacAddressController extends Controller
{
    /**
     * Unified MAC address registry page.
     *
     * Shows two sources:
     *   1. device_macs table   — Windows PCs via Intune (multi-adapter per device)
     *   2. devices.mac_address — Phones, printers, switches etc. (single MAC on the device row)
     *
     * Used by the RADIUS team to verify which MACs are registered in the system
     * before configuring 802.1X / MAB policies.
     */
    public function index(Request $request)
    {
        // ── 1. device_macs table (Intune-synced) ─────────────────────
        $macsQuery = DeviceMac::with(['azureDevice', 'device'])
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->search;
                $q->where(function ($inner) use ($s) {
                    $inner->where('mac_address', 'like', "%{$s}%")
                          ->orWhereHas('azureDevice', fn($aq) => $aq->where('display_name', 'like', "%{$s}%"))
                          ->orWhereHas('device',      fn($dq) => $dq->where('name',          'like', "%{$s}%"));
                });
            })
            ->when($request->filled('type'), fn($q) => $q->where('adapter_type', $request->type))
            ->when($request->filled('source'), fn($q) => $q->where('source', $request->source))
            ->orderBy('adapter_type')
            ->orderBy('mac_address');

        $deviceMacs = $macsQuery->paginate(50, ['*'], 'mac_page')->withQueryString();

        // ── 2. devices table — single mac_address column ──────────────
        // Phones, printers, network devices — exclude those already in device_macs
        $registeredMacs = DeviceMac::pluck('mac_address')->map(fn($m) => strtoupper($m))->toArray();

        $deviceRows = Device::whereNotNull('mac_address')
            ->where('mac_address', '!=', '')
            ->with(['branch'])
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->search;
                $q->where(function ($inner) use ($s) {
                    $inner->where('mac_address', 'like', "%{$s}%")
                          ->orWhere('name', 'like', "%{$s}%");
                });
            })
            ->get()
            ->filter(function ($device) use ($registeredMacs) {
                // Only show if MAC not already in device_macs registry
                $normalized = strtoupper(preg_replace('/[^a-fA-F0-9]/', '', $device->mac_address ?? ''));
                if (strlen($normalized) !== 12) return true; // show malformed MACs too
                $formatted = implode(':', str_split($normalized, 2));
                return ! in_array($formatted, $registeredMacs);
            });

        // ── Stats ──────────────────────────────────────────────────────
        $stats = [
            'total_registered' => DeviceMac::count(),
            'from_intune'      => DeviceMac::where('source', 'intune')->count(),
            'ethernet'         => DeviceMac::where('adapter_type', 'ethernet')->count(),
            'wifi'             => DeviceMac::where('adapter_type', 'wifi')->count(),
            'usb_ethernet'     => DeviceMac::where('adapter_type', 'usb_ethernet')->count(),
            'devices_with_mac' => Device::whereNotNull('mac_address')->where('mac_address', '!=', '')->count(),
        ];

        return view('admin.mac_addresses.index', compact(
            'deviceMacs', 'deviceRows', 'stats'
        ));
    }
}
