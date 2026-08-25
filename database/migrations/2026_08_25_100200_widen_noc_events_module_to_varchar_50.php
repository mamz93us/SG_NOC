<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Give `module` room for the values the branch health work adds.
 *
 * The column began life as ENUM('network','identity','voip','assets') and was
 * widened to VARCHAR(32) by 2026_04_27_000001 after MySQL silently truncated
 * out-of-list values to '' and failed the insert (SQLSTATE 1265). VARCHAR(50)
 * leaves headroom without another ALTER the next time a module is added.
 *
 * Guarded on the driver, unlike its predecessor: SQLite has no MODIFY COLUMN,
 * so the unguarded version aborts the test migration run. SQLite already stores
 * this as TEXT with no length ceiling, so there is genuinely nothing to do there.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `noc_events` MODIFY COLUMN `module` VARCHAR(50) NOT NULL');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `noc_events` MODIFY COLUMN `module` VARCHAR(32) NOT NULL');
        }
    }
};
