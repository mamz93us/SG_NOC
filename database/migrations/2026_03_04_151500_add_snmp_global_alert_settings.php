<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->string('snmp_alert_email')->nullable()->after('smtp_from_name');
        });

        Schema::table('monitored_hosts', function (Blueprint $table) {
            $table->boolean('alert_enabled')->default(false)->after('alert_email');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('snmp_alert_email');
        });

        Schema::table('monitored_hosts', function (Blueprint $table) {
            $table->dropColumn('alert_enabled');
        });
    }
};
