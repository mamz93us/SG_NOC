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
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('home_portal_payday_day')->nullable()->after('home_portal_urls');
            $table->boolean('home_portal_payday_last_working_day')->nullable()->after('home_portal_payday_day');
            $table->string('home_portal_payroll_url', 500)->nullable()->after('home_portal_payday_last_working_day');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn([
                'home_portal_payday_day',
                'home_portal_payday_last_working_day',
                'home_portal_payroll_url',
            ]);
        });
    }
};
