<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Forget the leave-record count the Oracle portal sync compares against.
 *
 * VacationSync refuses a response under half the last good one. The last good
 * one, on 2026-09-20, was 7,668 records, when Oracle's window reached ~120 days
 * back. Oracle's release of 2026-09-27 starts the window on the first of the
 * previous month, so a healthy pull is now ~730 and was refused every day from
 * 2026-09-28. With no baseline the next run is judged against the floor, and
 * sets the new baseline itself.
 *
 * Only the count. The API key, the switches and every other baseline stay as
 * they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('oracle_portal_settings')) {
            return;
        }

        DB::table('oracle_portal_settings')->update(['last_vacation_records_count' => null]);
    }

    public function down(): void
    {
        // Nothing to restore: the next successful sync writes the baseline.
    }
};
