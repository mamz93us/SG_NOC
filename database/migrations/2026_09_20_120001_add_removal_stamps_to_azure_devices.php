<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When Microsoft stops holding a device, the NOC keeps the row and stamps when
 * it went — the same "never delete, record the date" shape as
 * oracle_assets.removed_at. The row is the asset's record of what it was
 * enrolled as, and an asset that has been through offboarding should still show
 * it, with the date it left.
 *
 * - `intune_removed_at`: Intune no longer manages it. This is what an
 *   offboarding removal leaves behind, because deleting the managed device does
 *   not delete the Entra device object — so the row would otherwise go on
 *   reading as enrolled for good.
 * - `removed_at`: Microsoft does not list it at all any more, in Entra or in
 *   Intune.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('azure_devices', function (Blueprint $table) {
            $table->timestamp('intune_removed_at')->nullable()->after('intune_managed_device_id')->index();
            $table->timestamp('removed_at')->nullable()->after('last_sync_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('azure_devices', function (Blueprint $table) {
            $table->dropColumn(['intune_removed_at', 'removed_at']);
        });
    }
};
