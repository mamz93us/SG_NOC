<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payday, for the countdown on the portal's "My Payroll" card.
 *
 * It lived in config/home_portal.php behind env vars, which meant a deploy to
 * change — wrong for a date HR can move. These columns are nullable on purpose:
 * null means "use the config default", so an untouched install keeps behaving
 * exactly as it did.
 *
 * No salary figure is involved anywhere in this feature; it is a countdown.
 *
 * **The URL is TEXT, not a varchar.** `settings` is 163 columns wide and its
 * declared varchar bytes come to ~64.9KB of MySQL's hard 65,535-byte row limit,
 * so a `varchar(500)` (2,002 bytes in utf8mb4) does not fit — the first attempt
 * at this migration died with `SQLSTATE[42000] 1118 Row size too large`. TEXT
 * is stored off-row and costs the row only a pointer.
 *
 * Each column is guarded: that failed attempt added the first two before it
 * threw, and MySQL DDL is not transactional, so a plain re-run would trip over
 * a duplicate column instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (! Schema::hasColumn('settings', 'home_portal_payday_day')) {
                $table->unsignedTinyInteger('home_portal_payday_day')->nullable();
            }

            if (! Schema::hasColumn('settings', 'home_portal_payday_last_working_day')) {
                $table->boolean('home_portal_payday_last_working_day')->nullable();
            }

            if (! Schema::hasColumn('settings', 'home_portal_payroll_url')) {
                $table->text('home_portal_payroll_url')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            foreach ([
                'home_portal_payday_day',
                'home_portal_payday_last_working_day',
                'home_portal_payroll_url',
            ] as $column) {
                if (Schema::hasColumn('settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
