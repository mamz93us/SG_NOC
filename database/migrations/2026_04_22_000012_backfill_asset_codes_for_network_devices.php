<?php

use App\Models\Device;
use App\Services\AssetCodeService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * One-shot backfill — any switch/router/firewall Device without an
     * asset_code gets one, sequentially per type (SG-SW-000XXX, …).
     *
     * Existing asset codes are left alone. Safe to re-run.
     */
    public function up(): void
    {
        $service = new AssetCodeService();

        Device::whereIn('type', ['switch', 'router', 'firewall'])
            ->whereNull('asset_code')
            ->orderBy('id')
            ->chunkById(200, function ($devices) use ($service) {
                foreach ($devices as $device) {
                    try {
                        $device->asset_code = $service->generate($device->type);
                        $device->saveQuietly();
                    } catch (\Throwable $e) {
                        \Log::warning("Backfill asset_code failed for device #{$device->id}: " . $e->getMessage());
                    }
                }
            });
    }

    public function down(): void
    {
        // Not reversible — keeping the stamped codes on down migration so
        // subsequent re-runs don't renumber and create audit-trail churn.
    }
};
