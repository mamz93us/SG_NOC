<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\DeviceMac;
use App\Models\RadiusBranchVlanPolicy;
use App\Services\RadiusVlanPolicyResolver;
use Illuminate\Http\Request;

/**
 * Admin CRUD for radius_branch_vlan_policy plus a "Preview" endpoint that
 * resolves a sample MAC against the same precedence rules used by FreeRADIUS.
 */
class RadiusVlanPolicyController extends Controller
{
    public function index()
    {
        $policies = RadiusBranchVlanPolicy::with('branch')
            ->orderBy('branch_id')
            ->orderBy('priority')
            ->orderBy('adapter_type')
            ->paginate(50);

        return view('admin.radius.vlan.index', compact('policies'));
    }

    public function create()
    {
        $branches = Branch::orderBy('name')->get();

        return view('admin.radius.vlan.create', compact('branches'));
    }

    public function store(Request $request)
    {
        $data = $this->validateInput($request);

        RadiusBranchVlanPolicy::create($data);

        return redirect()
            ->route('admin.radius.vlan.index')
            ->with('success', 'VLAN policy added.');
    }

    public function edit(RadiusBranchVlanPolicy $policy)
    {
        $branches = Branch::orderBy('name')->get();

        return view('admin.radius.vlan.edit', compact('policy', 'branches'));
    }

    public function update(Request $request, RadiusBranchVlanPolicy $policy)
    {
        $data = $this->validateInput($request, $policy->id);

        $policy->update($data);

        return redirect()
            ->route('admin.radius.vlan.index')
            ->with('success', 'VLAN policy updated.');
    }

    public function destroy(RadiusBranchVlanPolicy $policy)
    {
        $policy->delete();

        return redirect()
            ->route('admin.radius.vlan.index')
            ->with('success', 'VLAN policy deleted.');
    }

    /**
     * AJAX endpoint: given a MAC, show what VLAN the policy resolver would
     * return. Used by the "Preview" panel on the policy page.
     */
    public function preview(Request $request, RadiusVlanPolicyResolver $resolver)
    {
        $request->validate([
            'mac' => 'required|string|max:32',
        ]);

        $normalized = DeviceMac::normalizeMac($request->mac);
        if ($normalized === null) {
            return response()->json([
                'ok'    => false,
                'error' => 'Invalid MAC format.',
            ], 422);
        }

        $mac = DeviceMac::where('mac_address', $normalized)->first();
        if (! $mac) {
            return response()->json([
                'ok'         => false,
                'normalized' => $normalized,
                'error'      => 'MAC not in registry — RADIUS would reject (mac-not-registered).',
            ]);
        }

        if (! $mac->is_active) {
            return response()->json([
                'ok'         => false,
                'normalized' => $normalized,
                'error'      => 'MAC is registered but is_active=0 — RADIUS would reject.',
            ]);
        }

        if ($mac->radiusOverride && ! $mac->radiusOverride->radius_enabled) {
            return response()->json([
                'ok'         => false,
                'normalized' => $normalized,
                'error'      => 'Per-MAC override sets radius_enabled=0 — RADIUS would reject.',
            ]);
        }

        $result = $resolver->resolve($mac);

        return response()->json([
            'ok'         => true,
            'normalized' => $normalized,
            'vlan'       => $result['vlan'],
            'source'     => $result['source'],
            'reason'     => $result['reason'],
        ]);
    }

    private function validateInput(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'branch_id'    => 'required|integer|exists:branches,id',
            'adapter_type' => 'required|in:ethernet,wifi,usb_ethernet,management,virtual,any',
            'device_type'  => 'nullable|string|max:32',
            'vlan_id'      => 'required|integer|min:1|max:4094',
            'priority'     => 'nullable|integer|min:1|max:65535',
            'description'  => 'nullable|string|max:255',
        ]);
    }
}
