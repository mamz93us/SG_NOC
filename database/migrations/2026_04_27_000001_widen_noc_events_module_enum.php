<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The `module` column was created as ENUM('network','identity','voip','assets')
 * but the codebase emits a wider set of values across observers, jobs, and the
 * NocEvent model's own moduleIcon()/moduleLabel() maps:
 *   snmp, ping, vpn, meraki, ucm, microsoft
 *
 * MySQL silently truncates ENUM inserts to '' when the value isn't in the list,
 * which then fails the row entirely (SQLSTATE 1265 — "Data truncated for column
 * 'module'"). Drop the ENUM constraint by switching to a plain VARCHAR so all
 * existing values keep working and future modules can be added without a
 * schema change.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE `noc_events` MODIFY COLUMN `module` VARCHAR(32) NOT NULL");
    }

    public function down(): void
    {
        // Restore original ENUM. Any rows with values outside the original set
        // would block the ALTER, so we coerce them to 'network' first.
        DB::statement("UPDATE `noc_events` SET `module` = 'network' WHERE `module` NOT IN ('network','identity','voip','assets')");
        DB::statement("ALTER TABLE `noc_events` MODIFY COLUMN `module` ENUM('network','identity','voip','assets') NOT NULL");
    }
};
