<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Device;
use App\Models\DeviceMac;
use App\Services\RadiusVlanPolicyResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

/**
 * RADIUS-focused MAC registry — every MAC FreeRADIUS would consider, with
 * its resolved RADIUS status (allowed/denied) and VLAN assignment.
 *
 * Distinct from /admin/itam/mac-address (which is the ITAM view): this page
 * is the operator-facing surface for the RADIUS subsystem — filter by
 * status, see exactly which VLAN each MAC would land in, add a manual
 * registration, and trigger the inventory sync.
 */
class RadiusMacRegistryController extends Controller
{
    public function index(Request $request, RadiusVlanPolicyResolver $resolver)
    {
        $query = DeviceMac::query()
            ->with(['azureDevice', 'device.branch', 'azureDevice.device.branch', 'radiusOverride'])
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->search;
                $q->where(function ($inner) use ($s) {
                    $inner->where('mac_address', 'like', "%{$s}%")
                          ->orWhereHas('azureDevice', fn($aq) => $aq->where('display_name', 'like', "%{$s}%")
                                                                    ->orWhere('upn', 'like', "%{$s}%"))
                          ->orWhereHas('device',      fn($dq) => $dq->where('name', 'like', "%{$s}%"));
                });
            })
            ->when($request->filled('adapter'), fn($q) => $q->where('adapter_type', $request->adapter))
            ->when($request->filled('source'),  fn($q) => $q->where('source',       $request->source))
            ->when($request->filled('status'), function ($q) use ($request) {
                match ($request->status) {
                    'denied'   => $q->where(function ($qq) {
                        $qq->where('is_active', false)
                           ->orWhereHas('radiusOverride', fn($oq) => $oq->where('radius_enabled', false));
                    }),
                    'allowed'  => $q->where('is_active', true)
                                    ->where(function ($qq) {
                                        $qq->doesntHave('radiusOverride')
                                           ->orWhereHas('radiusOverride', fn($oq) => $oq->where('radius_enabled', true));
                                    }),
                    'override' => $q->has('radiusOverride'),
                    default    => null,
                };
            })
            ->when($request->filled('branch'), function ($q) use ($request) {
                $b = (int) $request->branch;
                $q->where(function ($qq) use ($b) {
                    $qq->whereHas('device',                 fn($dq) => $dq->where('branch_id', $b))
                       ->orWhereHas('azureDevice.device',   fn($dq) => $dq->where('branch_id', $b));
                });
            })
            ->orderBy('mac_address');

        $macs = $query->paginate(50)->withQueryString();

        // Resolve VLAN for each row in-memory (cheap; resolver hits cached relations).
        $resolved = $macs->getCollection()->mapWithKeys(function (DeviceMac $mac) use ($resolver) {
            return [$mac->id => $resolver->resolve($mac)];
        });

        $stats = [
            'total'    => DeviceMac::count(),
            'allowed'  => DeviceMac::where('is_active', true)
                                    ->where(function ($q) {
                                        $q->doesntHave('radiusOverride')
                                          ->orWhereHas('radiusOverride', fn($oq) => $oq->where('radius_enabled', true));
                                    })->count(),
            'denied'   => DeviceMac::where(function ($q) {
                                $q->where('is_active', false)
                                  ->orWhereHas('radiusOverride', fn($oq) => $oq->where('radius_enabled', false));
                            })->count(),
            'override' => DeviceMac::has('radiusOverride')->count(),
        ];

        $branches = Branch::orderBy('name')->get(['id', 'name']);

        return view('admin.radius.macs.index', compact('macs', 'resolved', 'stats', 'branches'));
    }

    public function create()
    {
        $branches = Branch::orderBy('name')->get(['id', 'name']);
        $devices  = Device::orderBy('name')->limit(500)->get(['id', 'name', 'type', 'branch_id']);

        return view('admin.radius.macs.create', compact('branches', 'devices'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'mac_address'  => 'required|string|max:32',
            'adapter_type' => 'required|in:ethernet,wifi,usb_ethernet,management,virtual',
            'device_id'    => 'nullable|integer|exists:devices,id',
            'notes'        => 'nullable|string|max:500',
        ]);

        $normalized = DeviceMac::normalizeMac($data['mac_address']);
        if ($normalized === null) {
            return back()
                ->withInput()
                ->withErrors(['mac_address' => 'Invalid MAC address format. Accepted: AA:BB:CC:DD:EE:FF, AA-BB-CC-DD-EE-FF, aabbccddeeff, or aabb.ccdd.eeff.']);
        }

        if (DeviceMac::where('mac_address', $normalized)->exists()) {
            return back()
                ->withInput()
                ->withErrors(['mac_address' => "MAC {$normalized} is already registered."]);
        }

        DeviceMac::create([
            'mac_address'  => $normalized,
            'adapter_type' => $data['adapter_type'],
            'device_id'    => $data['device_id'] ?? null,
            'is_active'    => true,
            'source'       => 'manual',
            'notes'        => $data['notes'] ?? null,
            'last_seen_at' => now(),
        ]);

        return redirect()
            ->route('admin.radius.macs.index')
            ->with('success', "MAC {$normalized} added to RADIUS registry.");
    }

    public function destroy(DeviceMac $mac)
    {
        // Only allow deleting manual / import entries — Intune/SNMP rows are
        // owned by their sync sources and would just come back on next run.
        if (!in_array($mac->source, ['manual', 'import'], true)) {
            return back()->with('error', "Cannot delete MACs synced from {$mac->source}. Disable via the RADIUS override toggle instead.");
        }

        $addr = $mac->mac_address;
        $mac->delete();

        return back()->with('success', "MAC {$addr} removed from registry.");
    }

    /**
     * Manually trigger the inventory sync (devices → device_macs).
     * Schedule already runs this hourly; this endpoint is for impatient admins.
     */
    public function sync()
    {
        try {
            Artisan::call('radius:sync-macs');
            $output = trim(Artisan::output());
        } catch (\Throwable $e) {
            return back()->with('error', 'Sync failed: ' . $e->getMessage());
        }

        // Pull the last informational line for the flash message.
        $lastLine = collect(explode("\n", $output))->last(fn($l) => str_contains($l, 'Done.')) ?: 'Sync complete.';

        return back()->with('success', $lastLine);
    }
}
