<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dhcp_leases', function (Blueprint $table) {
            // Which network / SSID the client is sitting on (e.g. samirgroup, SG_Open).
            $table->string('network_label')->nullable()->after('source_device');
            // Firewall interface the lease was handed out on (e.g. internal, wifi).
            $table->string('interface')->nullable()->after('network_label');
            // DHCP reservation flag as reported by the server.
            $table->boolean('is_reserved')->default(false)->after('interface');

            $table->index('network_label');
        });

        Schema::table('sophos_firewalls', function (Blueprint $table) {
            $table->string('network_label')->nullable()->after('model');
        });
    }

    public function down(): void
    {
        Schema::table('dhcp_leases', function (Blueprint $table) {
            $table->dropIndex(['network_label']);
            $table->dropColumn(['network_label', 'interface', 'is_reserved']);
        });

        Schema::table('sophos_firewalls', function (Blueprint $table) {
            $table->dropColumn('network_label');
        });
    }
};
