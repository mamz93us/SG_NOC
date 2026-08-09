<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A firewall serves both wired and wireless clients, so its network label
 * cannot always be applied to every lease it reports.
 *
 * `label_wifi_only` = the label is a Wi-Fi SSID, so only stamp it on leases
 * whose MAC is a known Wi-Fi adapter (per the Intune-fed MAC registry).
 *
 * Sophos boxes serve the `samirgroup` wifi alongside the wired LAN, so they
 * get the flag. FortiGates serve `SG_Open` to everything on them, so they
 * keep the default (label everything).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sophos_firewalls', function (Blueprint $table) {
            $table->boolean('label_wifi_only')->default(false)->after('network_label');
        });

        Schema::table('fortigate_firewalls', function (Blueprint $table) {
            $table->boolean('label_wifi_only')->default(false)->after('network_label');
        });

        // Every Sophos firewall fronts the samirgroup wifi — label the Wi-Fi
        // clients only. Don't clobber a label someone already set by hand.
        DB::table('sophos_firewalls')
            ->whereNull('network_label')
            ->update([
                'network_label'   => 'samirgroup',
                'label_wifi_only' => true,
                'updated_at'      => now(),
            ]);
    }

    public function down(): void
    {
        Schema::table('sophos_firewalls', function (Blueprint $table) {
            $table->dropColumn('label_wifi_only');
        });

        Schema::table('fortigate_firewalls', function (Blueprint $table) {
            $table->dropColumn('label_wifi_only');
        });
    }
};
