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
        Schema::table('monitored_hosts', function (Blueprint $table) {
            $table->integer('ping_packet_count')->default(3)->after('ping_interval_seconds');
            $table->string('alert_email')->nullable()->after('ping_packet_count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('monitored_hosts', function (Blueprint $table) {
            $table->dropColumn(['ping_packet_count', 'alert_email']);
        });
    }
};
