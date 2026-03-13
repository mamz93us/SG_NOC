<?php

namespace App\Services;

use App\Models\Device;

class DepreciationService
{
    /**
     * Calculate current book value using straight-line depreciation.
     * Returns purchase_cost if method is 'none' or data is missing.
     */
    public function currentValue(Device $device): float
    {
        if (!$device->purchase_cost || $device->depreciation_method !== 'straight_line') {
            return (float) ($device->purchase_cost ?? 0);
        }

        $years = $device->depreciation_years;
        if (!$years || $years <= 0) {
            return (float) $device->purchase_cost;
        }

        $purchaseDate = $device->purchase_date ?? $device->created_at;
        if (!$purchaseDate) {
            return (float) $device->purchase_cost;
        }

        $yearsElapsed = max(0, $purchaseDate->diffInDays(now()) / 365);
        $annual       = (float) $device->purchase_cost / $years;
        $depreciated  = $annual * min($yearsElapsed, $years);

        return max(0, round((float) $device->purchase_cost - $depreciated, 2));
    }

    public function annualDepreciation(Device $device): float
    {
        if (!$device->purchase_cost || $device->depreciation_method !== 'straight_line') return 0;
        $years = $device->depreciation_years;
        if (!$years || $years <= 0) return 0;
        return round((float) $device->purchase_cost / $years, 2);
    }

    public function percentDepreciated(Device $device): float
    {
        if (!$device->purchase_cost || (float) $device->purchase_cost <= 0) return 0;
        $current = $this->currentValue($device);
        return round((1 - ($current / (float) $device->purchase_cost)) * 100, 1);
    }

    /**
     * Recalculate and persist current_value for all devices with depreciation enabled.
     */
    public function recalculateAll(): int
    {
        $count = 0;
        Device::where('depreciation_method', 'straight_line')
            ->whereNotNull('purchase_cost')
            ->each(function (Device $device) use (&$count) {
                $val = $this->currentValue($device);
                $device->update(['current_value' => $val]);
                $count++;
            });
        return $count;
    }
}
