<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->boolean('cups_enabled')->default(false)->after('snmp_alert_email');
            $table->string('cups_ipp_domain')->nullable()->after('cups_enabled');
            $table->unsignedSmallInteger('cups_refresh_interval')->default(5)->after('cups_ipp_domain');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['cups_enabled', 'cups_ipp_domain', 'cups_refresh_interval']);
        });
    }
};
