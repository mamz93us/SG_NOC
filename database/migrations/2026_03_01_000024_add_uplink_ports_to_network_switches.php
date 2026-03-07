<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('network_switches', function (Blueprint $table) {
            // JSON array of port IDs that the user has manually marked as uplink (WAN/uplink ports).
            // Stored as ["1","2"] etc. Replaces reliance on Meraki's isUplink flag.
            $table->json('uplink_port_ids')->nullable()->after('rack_id');
        });
    }

    public function down(): void
    {
        Schema::table('network_switches', function (Blueprint $table) {
            $table->dropColumn('uplink_port_ids');
        });
    }
};
