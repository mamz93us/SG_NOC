<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\DeviceMac;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

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

        // ── Export CSV ────────────────────────────────────────────────
        if ($request->boolean('export')) {
            return $this->exportCsv($macsQuery->get(), $deviceRows);
        }

        return view('admin.mac_addresses.index', compact(
            'deviceMacs', 'deviceRows', 'stats'
        ));
    }

    private function exportCsv($registryMacs, $deviceRows): Response
    {
        $normMac = fn(?string $m) => $m
            ? strtoupper(implode(':', str_split(strtoupper(preg_replace('/[^a-fA-F0-9]/', '', $m)), 2)))
            : '';

        $rows   = [];
        $rows[] = ['MAC Address', 'Type', 'Adapter Name', 'Owner Device', 'Asset Code', 'IP Address', 'Branch', 'Source', 'Last Seen'];

        // Section 1: device_macs registry
        foreach ($registryMacs as $mac) {
            $owner     = $mac->azureDevice?->display_name ?? $mac->device?->name ?? '';
            $assetCode = $mac->device?->asset_code ?? '';
            $ip        = $mac->device?->ip_address ?? '';
            $branch    = $mac->device?->branch?->name ?? $mac->azureDevice?->device?->branch?->name ?? '';
            $rows[]    = [
                $mac->mac_address,
                $mac->adapterTypeLabel(),
                $mac->adapter_name ?? '',
                $owner,
                $assetCode,
                $ip,
                $branch,
                ucfirst($mac->source),
                $mac->last_seen_at?->format('Y-m-d H:i') ?? '',
            ];
        }

        // Section 2: devices with single mac_address
        foreach ($deviceRows as $device) {
            $rows[] = [
                $normMac($device->mac_address),
                ucfirst($device->type),
                'LAN',
                $device->name,
                $device->asset_code ?? '',
                $device->ip_address ?? '',
                $device->branch?->name ?? '',
                'Manual',
                '',
            ];
            // Also export wifi_mac if present
            if ($device->wifi_mac) {
                $rows[] = [
                    $normMac($device->wifi_mac),
                    ucfirst($device->type),
                    'Wi-Fi',
                    $device->name,
                    $device->asset_code ?? '',
                    $device->ip_address ?? '',
                    $device->branch?->name ?? '',
                    'Manual',
                    '',
                ];
            }
        }

        $csv = collect($rows)->map(fn($r) => implode(',', array_map(fn($v) => '"' . str_replace('"', '""', $v) . '"', $r)))->implode("\n");

        return response($csv, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="mac-registry-' . now()->format('Y-m-d') . '.csv"',
        ]);
    }
}
