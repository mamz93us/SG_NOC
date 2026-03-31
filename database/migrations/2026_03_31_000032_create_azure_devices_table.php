<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The azure_devices table was created by an earlier migration (outside version
 * control). This migration only adds the net-data columns that are defined in
 * the AzureDevice model but may not yet exist in the live database.
 *
 * Each column is guarded by a hasColumn() check so the migration is safe to
 * run on both fresh installs and existing databases.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Ensure the table exists at all (fresh installs)
        if (! Schema::hasTable('azure_devices')) {
            Schema::create('azure_devices', function (Blueprint $table) {
                $table->id();
                $table->string('azure_device_id')->unique()->comment('Intune managedDeviceId (GUID)');
                $table->string('intune_managed_device_id')->nullable();
                $table->foreignId('device_id')->nullable()->constrained('devices')->nullOnDelete();
                $table->string('link_status')->default('unlinked');
                $table->string('display_name')->nullable();
                $table->string('device_type')->nullable();
                $table->string('os')->nullable();
                $table->string('os_version')->nullable();
                $table->string('upn')->nullable();
                $table->string('serial_number')->nullable();
                $table->string('manufacturer')->nullable();
                $table->string('model')->nullable();
                $table->timestamp('enrolled_date')->nullable();
                $table->timestamp('last_sync_at')->nullable();
                $table->timestamp('last_activity_at')->nullable();
                $table->string('teamviewer_id')->nullable();
                $table->string('tv_version')->nullable();
                $table->string('cpu_name')->nullable();
                $table->string('wifi_mac')->nullable();
                $table->string('ethernet_mac')->nullable();
                $table->text('usb_eth_data')->nullable()->comment('JSON array: [{name,mac,desc}]');
                $table->timestamp('net_data_synced_at')->nullable();
                $table->json('raw_data')->nullable();
                $table->timestamps();
                $table->index('upn');
                $table->index('serial_number');
                $table->index('link_status');
            });
            return;
        }

        // Table already exists — add only the missing net-data columns
        Schema::table('azure_devices', function (Blueprint $table) {
            if (! Schema::hasColumn('azure_devices', 'teamviewer_id')) {
                $table->string('teamviewer_id')->nullable()->after('last_activity_at');
            }
            if (! Schema::hasColumn('azure_devices', 'tv_version')) {
                $table->string('tv_version')->nullable()->after('teamviewer_id');
            }
            if (! Schema::hasColumn('azure_devices', 'cpu_name')) {
                $table->string('cpu_name')->nullable()->after('tv_version');
            }
            if (! Schema::hasColumn('azure_devices', 'wifi_mac')) {
                $table->string('wifi_mac')->nullable()->after('cpu_name');
            }
            if (! Schema::hasColumn('azure_devices', 'ethernet_mac')) {
                $table->string('ethernet_mac')->nullable()->after('wifi_mac');
            }
            if (! Schema::hasColumn('azure_devices', 'usb_eth_data')) {
                $table->text('usb_eth_data')->nullable()
                    ->comment('JSON array: [{name,mac,desc}]')
                    ->after('ethernet_mac');
            }
            if (! Schema::hasColumn('azure_devices', 'net_data_synced_at')) {
                $table->timestamp('net_data_synced_at')->nullable()->after('usb_eth_data');
            }

            // Index teamviewer_id if not already present
            // (other indexes existed on the original table)
            if (! Schema::hasColumn('azure_devices', 'teamviewer_id')) {
                $table->index('teamviewer_id');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('azure_devices')) {
            return;
        }

        Schema::table('azure_devices', function (Blueprint $table) {
            foreach (['teamviewer_id', 'tv_version', 'cpu_name', 'wifi_mac', 'ethernet_mac', 'usb_eth_data', 'net_data_synced_at'] as $col) {
                if (Schema::hasColumn('azure_devices', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
