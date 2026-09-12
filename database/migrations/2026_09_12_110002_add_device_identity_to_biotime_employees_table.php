<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the device itself knows about a code: the person's name, and the
 * internal user id the punch rows are actually keyed by.
 *
 * Display only — neither ever influences which employee a code links to. The
 * name lets HR see "512 · Ahmed Hassan" while confirming a mapping, and the
 * user id makes a re-badge visible (a changed BADGENUMBER forks the row).
 * Only the legacy ZKTeco reader has a user table to fill them from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('biotime_employees', function (Blueprint $table) {
            $table->string('device_name', 150)->nullable()->after('emp_code');
            $table->string('device_user_id', 32)->nullable()->after('device_name');
        });
    }

    public function down(): void
    {
        Schema::table('biotime_employees', function (Blueprint $table) {
            $table->dropColumn(['device_name', 'device_user_id']);
        });
    }
};
