<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('azure_devices', function (Blueprint $table) {
            $table->id();

            // ── Intune / Azure AD identifiers ──────────────────────────
            $table->string('azure_device_id')->unique()->comment('Intune managedDeviceId (GUID)');
            $table->string('intune_managed_device_id')->nullable()->comment('Duplicate key kept for legacy lookup');

            // ── Asset link ─────────────────────────────────────────────
            $table->foreignId('device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->string('link_status')->default('unlinked')->comment('unlinked | linked | pending | rejected');

            // ── Basic identity ─────────────────────────────────────────
            $table->string('display_name')->nullable();
            $table->string('device_type')->nullable();
            $table->string('os')->nullable();
            $table->string('os_version')->nullable();
            $table->string('upn')->nullable()->comment('Assigned user UPN');

            // ── Hardware ───────────────────────────────────────────────
            $table->string('serial_number')->nullable();
            $table->string('manufacturer')->nullable();
            $table->string('model')->nullable();

            // ── Timestamps from Intune ─────────────────────────────────
            $table->timestamp('enrolled_date')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();

            // ── Net data — populated by intune:sync-net-data ───────────
            // Script: NOC-DeviceInfo.ps1  (stored in settings: intune_net_data_script_id)
            // resultMessage JSON keys: teamviewer_id, tv_version, cpu_name,
            //                          wifi_mac, ethernet_mac, usb_eth_adapters[]
            $table->string('teamviewer_id')->nullable();
            $table->string('tv_version')->nullable();
            $table->string('cpu_name')->nullable();
            $table->string('wifi_mac')->nullable();
            $table->string('ethernet_mac')->nullable();
            $table->text('usb_eth_data')->nullable()->comment('JSON array: [{name,mac,desc}]');
            $table->timestamp('net_data_synced_at')->nullable();

            // ── Raw payload ────────────────────────────────────────────
            $table->json('raw_data')->nullable();

            $table->timestamps();

            // ── Indexes ────────────────────────────────────────────────
            $table->index('upn');
            $table->index('serial_number');
            $table->index('link_status');
            $table->index('teamviewer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('azure_devices');
    }
};
