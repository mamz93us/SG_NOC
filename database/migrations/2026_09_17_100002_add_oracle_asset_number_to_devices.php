<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An asset's Oracle fixed-asset number, next to the NOC's own asset code.
 *
 * Not unique: an Oracle asset number is a purchase line, so thirty laptops
 * bought together all carry it. The import fills it; the device form lets a
 * person type it for an asset created by hand.
 *
 * `oracle` joins devices.source for the assets the import creates for units
 * that match nothing the NOC already had.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('devices', 'oracle_asset_number')) {
            Schema::table('devices', function (Blueprint $table) {
                $table->string('oracle_asset_number', 40)->nullable()->after('asset_code')->index();
            });
        }

        if (DB::getDriverName() !== 'mysql') {
            return; // sqlite (local/test) has no ENUM constraint to widen
        }

        DB::statement("ALTER TABLE devices MODIFY COLUMN source ENUM(
            'manual', 'meraki', 'ucm', 'printer', 'azure', 'gdms', 'access_point', 'oracle'
        ) NULL");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::table('devices')->where('source', 'oracle')->update(['source' => 'manual']);

            DB::statement("ALTER TABLE devices MODIFY COLUMN source ENUM(
                'manual', 'meraki', 'ucm', 'printer', 'azure', 'gdms', 'access_point'
            ) NULL");
        }

        if (Schema::hasColumn('devices', 'oracle_asset_number')) {
            Schema::table('devices', function (Blueprint $table) {
                $table->dropIndex(['oracle_asset_number']);
                $table->dropColumn('oracle_asset_number');
            });
        }
    }
};
