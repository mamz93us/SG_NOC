<?php

use App\Models\Device;
use App\Services\AssetCodeService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Fix all devices imported via manual/import that are type 'other' but are actually phones
        // These were imported via the MAC/Serial import feature before the phone type was added
        $devices = Device::where('source', 'manual')
            ->where(function ($q) {
                $q->where('type', 'other')
                  ->orWhereNull('manufacturer')
                  ->orWhere('manufacturer', '')
                  ->orWhereNull('asset_code')
                  ->orWhere('asset_code', '');
            })
            ->get();

        if ($devices->isEmpty()) {
            return;
        }

        $assetCodeSvc = new AssetCodeService();

        DB::transaction(function () use ($devices, $assetCodeSvc) {
            foreach ($devices as $device) {
                $updates = [];

                if ($device->type === 'other') {
                    $updates['type'] = 'phone';
                }
                if (!$device->manufacturer) {
                    $updates['manufacturer'] = 'Grandstream';
                }
                if (!$device->asset_code) {
                    $updates['asset_code'] = $assetCodeSvc->generate($updates['type'] ?? $device->type);
                }

                if (!empty($updates)) {
                    $device->update($updates);
                }
            }
        });
    }

    public function down(): void
    {
        // Not reversible — these were data corrections
    }
};
