<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('azure_devices', function (Blueprint $table) {
            $table->string('teamviewer_id')->nullable()->after('raw_data');
            $table->string('tv_version')->nullable()->after('teamviewer_id');
            $table->string('cpu_name')->nullable()->after('tv_version');
            $table->string('wifi_mac', 17)->nullable()->after('cpu_name');
            $table->string('ethernet_mac', 17)->nullable()->after('wifi_mac');
            $table->text('usb_eth_data')->nullable()->after('ethernet_mac');
            $table->timestamp('net_data_synced_at')->nullable()->after('usb_eth_data');
        });
    }

    public function down(): void
    {
        Schema::table('azure_devices', function (Blueprint $table) {
            $table->dropColumn([
                'teamviewer_id', 'tv_version', 'cpu_name',
                'wifi_mac', 'ethernet_mac', 'usb_eth_data', 'net_data_synced_at',
            ]);
        });
    }
};
