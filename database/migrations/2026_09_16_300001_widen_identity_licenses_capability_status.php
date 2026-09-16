<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Let identity_licenses hold every capabilityStatus Graph reports for a SKU.
 *
 * The ENUM lists Enabled, Suspended, Deleted and LockedOut. Graph also sends
 * Warning: a subscription past its end date and still inside the grace period.
 * MySQL in strict mode rejects that value, and syncLicenses() writes every SKU
 * in one transaction, so one lapsed subscription would stop the whole SKU list
 * from updating. A licence bought after that would then never get its NOC
 * licence row, and nothing assigned from it could reach the NOC. Every Microsoft
 * subscription in the NOC renews on 2026-10-26, so the first lapse is close.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `identity_licenses` MODIFY COLUMN `capability_status` VARCHAR(20) NOT NULL DEFAULT 'Enabled'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::table('identity_licenses')
                ->whereNotIn('capability_status', ['Enabled', 'Suspended', 'Deleted', 'LockedOut'])
                ->update(['capability_status' => 'Suspended']);
            DB::statement("ALTER TABLE `identity_licenses` MODIFY COLUMN `capability_status` ENUM('Enabled', 'Suspended', 'Deleted', 'LockedOut') NOT NULL DEFAULT 'Enabled'");
        }
    }
};
