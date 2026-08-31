<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Destination URLs for the home portal's Core systems tiles.
 *
 * They started in config/home_portal.php with env overrides, which meant every
 * URL change was a deploy. Everything else configurable in this app lives in
 * settings, so these do too: config keeps the tile list (key, name, icon,
 * description) and this column carries the addresses, merged over it.
 *
 * A tile with no URL is hidden rather than rendered as a dead link, so an empty
 * value here is a valid way to switch a tile off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->json('home_portal_urls')->nullable()->after('company_calendar_last_sync_at');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('home_portal_urls');
        });
    }
};
