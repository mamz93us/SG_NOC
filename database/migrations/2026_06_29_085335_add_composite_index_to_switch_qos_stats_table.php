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
        Schema::table('switch_qos_stats', function (Blueprint $table) {
            // Covers the latestIds subquery (GROUP BY device_ip, interface_name WHERE polled_at BETWEEN ...)
            // and the ROW_NUMBER() delta window (PARTITION BY device_ip, interface_name ORDER BY polled_at DESC).
            $table->index(['device_ip', 'interface_name', 'polled_at'], 'qos_ip_iface_polled_idx');
        });
    }

    public function down(): void
    {
        Schema::table('switch_qos_stats', function (Blueprint $table) {
            $table->dropIndex('qos_ip_iface_polled_idx');
        });
    }
};
