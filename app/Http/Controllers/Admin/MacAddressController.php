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
                          ->orWhere('wifi_mac',   'like', "%{$s}%")
                          ->orWhere('name',        'like', "%{$s}%");
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

        // ── 3. Bulk DHCP IP lookup for all MACs without a stored IP ──
        $normMacFn = fn(?string $m) => $m
            ? strtoupper(implode(':', str_split(strtoupper(preg_replace('/[^a-fA-F0-9]/', '', $m)), 2)))
            : null;

        // Collect all raw MACs (Section 1 registry + Section 2 device rows)
        $allMacsForLookup = [];
        foreach ($macsQuery->get() as $dm) {
            $n = strtoupper(preg_replace('/[^a-fA-F0-9]/', '', $dm->mac_address ?? ''));
            if (strlen($n) === 12) $allMacsForLookup[] = $n;
        }
        foreach ($deviceRows as $device) {
            foreach (array_filter([$device->mac_address, $device->wifi_mac]) as $raw) {
                $n = strtoupper(preg_replace('/[^a-fA-F0-9]/', '', $raw));
                if (strlen($n) === 12) $allMacsForLookup[] = $n;
            }
        }
        $allMacsForLookup = array_unique($allMacsForLookup);

        // One query — most-recent DHCP lease IP keyed by normalised MAC
        $dhcpByMac = [];
        if (! empty($allMacsForLookup)) {
            $placeholders = implode(',', array_fill(0, count($allMacsForLookup), '?'));
            \App\Models\DhcpLease::whereRaw(
                "UPPER(REPLACE(REPLACE(mac_address,':',''),'-','')) IN ({$placeholders})",
                $allMacsForLookup
            )
            ->orderByDesc('last_seen')
            ->get(['mac_address', 'ip_address'])
            ->each(function ($lease) use (&$dhcpByMac) {
                $norm = strtoupper(preg_replace('/[^a-fA-F0-9]/', '', $lease->mac_address));
                if (! isset($dhcpByMac[$norm])) {
                    $dhcpByMac[$norm] = $lease->ip_address;
                }
            });
        }

        // ── Stats ──────────────────────────────────────────────────────
        $stats = [
            'total_registered' => DeviceMac::count(),
            'from_intune'      => DeviceMac::where('source', 'intune')->count(),
            'ethernet'         => DeviceMac::where('adapter_type', 'ethernet')->count(),
            'wifi'             => DeviceMac::where('adapter_type', 'wifi')->count(),
            'usb_ethernet'     => DeviceMac::where('adapter_type', 'usb_ethernet')->count(),
            'devices_with_mac' => Device::whereNotNull('mac_address')->where('mac_address', '!=', '')->count(),
        ];

        // ── Export CSV ─────────────────────────────────────────────────
        // Always export ALL records — ignore search/type/source filters in export
        if ($request->boolean('export')) {
            $allMacs = DeviceMac::with(['azureDevice', 'device'])->orderBy('adapter_type')->orderBy('mac_address')->get();
            $allDeviceRows = Device::whereNotNull('mac_address')->where('mac_address', '!=', '')->with(['branch'])->get()
                ->filter(function ($device) use ($registeredMacs) {
                    $normalized = strtoupper(preg_replace('/[^a-fA-F0-9]/', '', $device->mac_address ?? ''));
                    if (strlen($normalized) !== 12) return true;
                    $formatted = implode(':', str_split($normalized, 2));
                    return ! in_array($formatted, $registeredMacs);
                });
            return $this->exportCsv($allMacs, $allDeviceRows, $dhcpByMac, $normMacFn);
        }

        return view('admin.mac_addresses.index', compact(
            'deviceMacs', 'deviceRows', 'stats', 'dhcpByMac', 'normMacFn'
        ));
    }

    private function exportCsv($registryMacs, $deviceRows, array $dhcpByMac = [], ?callable $normMacFn = null): Response
    {
        $normMac = fn(?string $m) => $m
            ? strtoupper(implode(':', str_split(strtoupper(preg_replace('/[^a-fA-F0-9]/', '', $m)), 2)))
            : '';
        $getIp = function(?string $mac) use ($dhcpByMac): string {
            if (!$mac) return '';
            $n = strtoupper(preg_replace('/[^a-fA-F0-9]/', '', $mac));
            return $dhcpByMac[$n] ?? '';
        };

        $rows   = [];
        $rows[] = [
            'MAC Address', 'Type', 'Adapter Name', 'Owner Device', 'Asset Code',
            'UPN', 'IP Address', 'Branch', 'Source', 'Last Seen',
            'TeamViewer ID', 'TV Version', 'CPU',
        ];

        // Section 1: device_macs registry (Intune-synced + manual)
        foreach ($registryMacs as $mac) {
            $az        = $mac->azureDevice;
            $owner     = $az?->display_name ?? $mac->device?->name ?? '';
            $assetCode = $mac->device?->asset_code ?? $az?->device?->asset_code ?? '';
            $upn       = $az?->upn ?? '';
            $ip        = $mac->device?->ip_address ?: $getIp($mac->mac_address);
            $branch    = $mac->device?->branch?->name ?? $az?->device?->branch?->name ?? '';
            $tvId      = $az?->teamviewer_id ?? '';
            $tvVer     = $az?->tv_version ?? '';
            $cpu       = $az?->cpu_name ?? '';
            $rows[]    = [
                $mac->mac_address,
                $mac->adapterTypeLabel(),
                $mac->adapter_name ?? '',
                $owner,
                $assetCode,
                $upn,
                $ip,
                $branch,
                ucfirst($mac->source),
                $mac->last_seen_at?->format('Y-m-d H:i') ?? '',
                $tvId,
                $tvVer,
                $cpu,
            ];
        }

        // Section 2: devices table MACs (phones, switches, etc.)
        foreach ($deviceRows as $device) {
            $lanNorm = strtoupper(preg_replace('/[^a-fA-F0-9]/', '', $device->mac_address ?? ''));
            $ip      = $device->ip_address ?: ($dhcpByMac[$lanNorm] ?? '');
            $rows[]  = [
                $normMac($device->mac_address),
                ucfirst($device->type),
                'LAN',
                $device->name,
                $device->asset_code ?? '',
                '',
                $ip,
                $device->branch?->name ?? '',
                'Manual',
                '',
                '', '', '',
            ];
            if ($device->wifi_mac) {
                $wifiNorm = strtoupper(preg_replace('/[^a-fA-F0-9]/', '', $device->wifi_mac));
                $wifiIp   = $device->ip_address ?: ($dhcpByMac[$wifiNorm] ?? $ip);
                $rows[]   = [
                    $normMac($device->wifi_mac),
                    ucfirst($device->type),
                    'Wi-Fi',
                    $device->name,
                    $device->asset_code ?? '',
                    '',
                    $wifiIp,
                    $device->branch?->name ?? '',
                    'Manual',
                    '',
                    '', '', '',
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
