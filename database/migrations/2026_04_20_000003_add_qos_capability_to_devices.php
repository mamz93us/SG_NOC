<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->boolean('telnet_reachable')->nullable()->after('ssh_username');
            $table->boolean('mls_qos_supported')->nullable()->after('telnet_reachable');
            $table->timestamp('qos_probed_at')->nullable()->after('mls_qos_supported');
            $table->text('qos_probe_error')->nullable()->after('qos_probed_at');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['telnet_reachable', 'mls_qos_supported', 'qos_probed_at', 'qos_probe_error']);
        });
    }
};
