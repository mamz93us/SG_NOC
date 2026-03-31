<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central MAC address registry — used by RADIUS server (802.1X / MAB) and
 * network visibility features.
 *
 * Every network interface of every managed asset lives here:
 *   • Windows PCs via Intune  (azure_device_id FK)
 *   • IP phones, switches, firewalls, APs (device_id FK)
 *   • USB Ethernet dongles attached to laptops (azure_device_id, type=usb_ethernet)
 *
 * RADIUS usage:
 *   SELECT d.*, dm.*
 *   FROM   device_macs dm
 *   LEFT JOIN azure_devices ad ON ad.id = dm.azure_device_id
 *   LEFT JOIN devices        d  ON d.id  = dm.device_id
 *   WHERE  dm.mac_address = '<calling-station-id>'
 *   LIMIT  1;
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_macs', function (Blueprint $table) {
            $table->id();

            // ── MAC address (normalised: AA:BB:CC:DD:EE:FF uppercase) ───────
            $table->string('mac_address', 17)->unique();

            // ── Adapter classification ────────────────────────────────────
            $table->enum('adapter_type', [
                'ethernet',         // built-in wired NIC
                'wifi',             // built-in wireless NIC
                'usb_ethernet',     // USB / Thunderbolt dock NIC
                'management',       // OOB / IPMI / iDRAC management port
                'virtual',          // VMware / Hyper-V virtual adapter
            ])->default('ethernet');

            // Friendly name from OS (e.g. "Ethernet", "Wi-Fi 2", "USB 10/100 LAN")
            $table->string('adapter_name', 120)->nullable();
            // Hardware description (e.g. "Intel I219-V", "Realtek USB GbE")
            $table->string('adapter_description', 255)->nullable();

            // ── Owner references (at most one should be non-null) ─────────
            // Windows managed device from Intune
            $table->foreignId('azure_device_id')
                  ->nullable()
                  ->constrained('azure_devices')
                  ->nullOnDelete();

            // Physical device in our ITAM (phone, switch, firewall, AP, etc.)
            $table->foreignId('device_id')
                  ->nullable()
                  ->constrained('devices')
                  ->nullOnDelete();

            // ── Flags ─────────────────────────────────────────────────────
            // Primary = the interface RADIUS should use to identify this device
            $table->boolean('is_primary')->default(false);
            // is_active = false means decommissioned / no longer in use
            $table->boolean('is_active')->default(true);

            // ── Source tracking ───────────────────────────────────────────
            $table->enum('source', [
                'intune',   // populated by intune:sync-net-data
                'snmp',     // discovered via SNMP ARP / neighbour table
                'dhcp',     // learnt from DHCP server snooping
                'arp',      // collected from router/firewall ARP tables
                'manual',   // entered by an admin
                'import',   // bulk CSV import
            ])->default('manual');

            $table->timestamp('last_seen_at')->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            // ── Indexes ───────────────────────────────────────────────────
            $table->index('azure_device_id');
            $table->index('device_id');
            $table->index('adapter_type');
            $table->index('source');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_macs');
    }
};
