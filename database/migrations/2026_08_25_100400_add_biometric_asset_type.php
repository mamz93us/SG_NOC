<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Register the `biometric` asset type.
 *
 * Biometric attendance terminals are already pingable infrastructure — they get
 * a Device row and a MonitoredHost like any switch or printer, and the existing
 * every-minute ping monitor covers them. What was missing was a type to file
 * them under, so the branch health score had no way to ask "how many biometric
 * devices does this branch have, and are they up?".
 *
 * No collector is added. devices.type is already VARCHAR(30) (widened by
 * 2026_03_18_000001), so no schema change is needed either.
 *
 * Idempotent by slug, since asset_types is seeded from a migration rather than
 * a seeder and this may run against a database that already has the row.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('asset_types')) {
            return;
        }

        $now = now();

        DB::table('asset_types')->updateOrInsert(
            ['slug' => 'biometric'],
            [
                'label' => 'Biometric Device',
                'icon' => 'bi-fingerprint',
                'badge_class' => 'bg-dark',
                'category_code' => 'BIO',
                'is_user_equipment' => false,
                'group' => 'infrastructure',
                // Between printer (60) and server (70) — it is branch-floor kit.
                'sort_order' => 65,
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );

        // AssetType caches the whole table for an hour under `asset_types_all`,
        // and AssetCodeService::generate() resolves category codes through that
        // cache. Without this flush, biometric assets created in the next hour
        // would be stamped SG-OTH-nnnnnn instead of SG-BIO-nnnnnn.
        if (class_exists(\App\Models\AssetType::class)) {
            \App\Models\AssetType::clearCache();
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('asset_types')) {
            return;
        }

        // Only remove the type if nothing is filed under it — dropping it while
        // devices reference it would orphan them.
        $inUse = Schema::hasTable('devices')
            && DB::table('devices')->where('type', 'biometric')->exists();

        if (! $inUse) {
            DB::table('asset_types')->where('slug', 'biometric')->delete();

            if (class_exists(\App\Models\AssetType::class)) {
                \App\Models\AssetType::clearCache();
            }
        }
    }
};
