<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Add wifi_mac to devices table for IP phones.
 * IP phones use LAN MAC for the wired port and WiFi MAC = LAN MAC last-byte + 1.
 * Also backfill existing phone records by auto-calculating the WiFi MAC.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('wifi_mac', 17)->nullable()->after('mac_address')
                  ->comment('WiFi MAC address — for IP phones: LAN MAC last byte + 1');
        });

        // Backfill existing phone records that already have a mac_address
        $phones = DB::table('devices')
            ->where('type', 'phone')
            ->whereNotNull('mac_address')
            ->whereNull('wifi_mac')
            ->get(['id', 'mac_address']);

        foreach ($phones as $phone) {
            $wifiMac = self::calcWifiMac($phone->mac_address);
            if ($wifiMac) {
                DB::table('devices')->where('id', $phone->id)->update(['wifi_mac' => $wifiMac]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn('wifi_mac');
        });
    }

    private static function calcWifiMac(?string $lanMac): ?string
    {
        if (empty($lanMac)) return null;
        $clean = strtoupper(preg_replace('/[^a-fA-F0-9]/', '', $lanMac));
        if (strlen($clean) !== 12) return null;
        $lastByte = hexdec(substr($clean, 10, 2));
        $nextByte = ($lastByte + 1) & 0xFF;
        $full = substr($clean, 0, 10) . str_pad(dechex($nextByte), 2, '0', STR_PAD_LEFT);
        return implode(':', str_split(strtoupper($full), 2));
    }
};
