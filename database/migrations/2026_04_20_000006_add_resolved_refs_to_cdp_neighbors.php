<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('switch_cdp_neighbors', function (Blueprint $table) {
            // Normalized MAC derived from neighbor_device_id or mac field — used for Meraki matching.
            $table->string('neighbor_mac', 17)->nullable()->after('neighbor_ip')->index();
            // Resolved references — populated at poll time when we find a matching record.
            $table->string('matched_meraki_serial', 20)->nullable()->after('version')->index();
            $table->unsignedBigInteger('matched_device_id')->nullable()->after('matched_meraki_serial')->index();
        });
    }

    public function down(): void
    {
        Schema::table('switch_cdp_neighbors', function (Blueprint $table) {
            $table->dropColumn(['neighbor_mac', 'matched_meraki_serial', 'matched_device_id']);
        });
    }
};
