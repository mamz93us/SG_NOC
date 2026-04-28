<?php

namespace App\Services;

use App\Models\DeviceMac;
use App\Models\RadiusBranchVlanPolicy;

/**
 * Resolve which VLAN a given MAC would be assigned by RADIUS.
 *
 * Mirrors the SQL precedence in deployment/freeradius/mods-available/sql
 * (authorize_reply_query). Used by the admin "Preview" panel and unit tests
 * to keep the PHP and SQL implementations in sync.
 *
 * Resolution order:
 *   1. radius_mac_overrides.vlan_override (per-MAC override)
 *   2. Most-specific radius_branch_vlan_policy row, with the device's branch
 *      resolved through device → branch_id, falling back to azure_device →
 *      device → branch_id. Specificity (lowest priority wins on ties):
 *        a. exact adapter_type + exact device_type
 *        b. exact adapter_type + NULL  device_type
 *        c. 'any'              + exact device_type
 *        d. 'any'              + NULL  device_type   (catch-all per branch)
 *   3. Otherwise: null (RADIUS returns no VLAN attrs → switch falls back).
 */
class RadiusVlanPolicyResolver
{
    /**
     * @return array{vlan: int|null, source: string, reason: string}
     */
    public function resolve(DeviceMac $mac): array
    {
        $mac->loadMissing(['radiusOverride', 'device.branch', 'azureDevice.device.branch']);

        // Step 1: per-MAC override.
        if ($mac->radiusOverride && $mac->radiusOverride->vlan_override !== null) {
            return [
                'vlan'   => (int) $mac->radiusOverride->vlan_override,
                'source' => 'override',
                'reason' => 'radius_mac_overrides.vlan_override',
            ];
        }

        // Step 2: branch policy.
        $branchId = $mac->device?->branch_id
            ?? $mac->azureDevice?->device?->branch_id;

        if ($branchId === null) {
            return [
                'vlan'   => null,
                'source' => 'none',
                'reason' => 'no branch resolved for this MAC',
            ];
        }

        $deviceType = $mac->device?->type;

        // Pull all candidate policy rows for this branch + adapter_type
        // (specific or 'any'), then rank in PHP with the same specificity
        // ordering used by the SQL.
        $candidates = RadiusBranchVlanPolicy::query()
            ->where('branch_id', $branchId)
            ->whereIn('adapter_type', [$mac->adapter_type, 'any'])
            ->where(function ($q) use ($deviceType) {
                $q->whereNull('device_type');
                if ($deviceType !== null) {
                    $q->orWhere('device_type', $deviceType);
                }
            })
            ->get();

        if ($candidates->isEmpty()) {
            return [
                'vlan'   => null,
                'source' => 'none',
                'reason' => "no branch policy matches (branch={$branchId}, adapter={$mac->adapter_type}, device_type=" . ($deviceType ?? 'null') . ')',
            ];
        }

        $ranked = $candidates->sortBy([
            // Prefer exact adapter_type over 'any'.
            fn($p) => $p->adapter_type === 'any' ? 1 : 0,
            // Prefer non-null device_type over null when device_type known.
            fn($p) => $deviceType !== null && $p->device_type === null ? 1 : 0,
            // Then by priority (lower wins).
            fn($p) => $p->priority,
        ])->values();

        $winner = $ranked->first();

        return [
            'vlan'   => (int) $winner->vlan_id,
            'source' => 'branch_policy',
            'reason' => sprintf(
                'radius_branch_vlan_policy id=%d (branch=%d, adapter=%s, device_type=%s, priority=%d)',
                $winner->id,
                $winner->branch_id,
                $winner->adapter_type,
                $winner->device_type ?? 'any',
                $winner->priority,
            ),
        ];
    }
}
