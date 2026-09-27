<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * removed_at: the punch is no longer in the source database.
 *
 * ZKTeco is the record of a scan and the NOC keeps a copy, so a punch deleted
 * there must stop counting here. It is stamped rather than deleted, the same
 * way an Oracle asset or a leave record the next export no longer lists is:
 * these rows are payroll evidence, the stamping is done unattended by
 * biotime:reconcile, and a punch the source has again is simply un-stamped.
 *
 * AttendancePunch soft-deletes on this column, so every Eloquent read — the
 * day processor, the monthly sheet, the Oracle feed — leaves removed punches
 * out without being told to.
 *
 * Added at the end of the row on purpose, not `after('synced_at')`: appending
 * a nullable column is INSTANT on MySQL 8, and attendance_punches is the
 * largest table in this database. Column order is cosmetic; a table rebuild
 * on it is not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_punches', function (Blueprint $table) {
            $table->timestamp('removed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('attendance_punches', function (Blueprint $table) {
            $table->dropColumn('removed_at');
        });
    }
};
