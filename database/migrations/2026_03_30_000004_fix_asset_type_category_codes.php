<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fix asset type category codes so each device type gets the right prefix.
 * Also renames any existing device asset_codes that used the old wrong prefix.
 *
 * Before  →  After
 * ───────────────────────────────
 * switch   NET → SW   (SG-NET-xxx → SG-SW-xxx)
 * firewall FWL → FW   (SG-FWL-xxx → SG-FW-xxx)
 * router   RTR → RT   (SG-RTR-xxx → SG-RT-xxx)
 * ap       WAP → AP   (SG-WAP-xxx → SG-AP-xxx)
 */
return new class extends Migration
{
    private array $changes = [
        // [slug, old_code, new_code, base_prefix (SG by default)]
        ['switch',   'NET', 'SW'],
        ['switch',   'LAP', 'SW'],   // catch codes incorrectly generated as LAP
        ['firewall', 'FWL', 'FW'],
        ['router',   'RTR', 'RT'],
        ['ap',       'WAP', 'AP'],
    ];

    public function up(): void
    {
        // 1. Update the category_code in asset_types
        DB::table('asset_types')->where('slug', 'switch')  ->update(['category_code' => 'SW']);
        DB::table('asset_types')->where('slug', 'firewall')->update(['category_code' => 'FW']);
        DB::table('asset_types')->where('slug', 'router')  ->update(['category_code' => 'RT']);
        DB::table('asset_types')->where('slug', 'ap')      ->update(['category_code' => 'AP']);

        // 2. Rename existing device asset_codes that used the old prefix
        // Fetch base prefix from the single-row settings record (default 'SG')
        $base = DB::table('settings')->value('itam_asset_prefix') ?? 'SG';

        foreach ($this->changes as [$slug, $old, $new]) {

            $oldPrefix = "{$base}-{$old}-";
            $newPrefix = "{$base}-{$new}-";

            // Find switch/ap/etc devices whose asset_code starts with the old prefix
            $devices = DB::table('devices')
                ->where('type', $slug)
                ->where('asset_code', 'like', $oldPrefix . '%')
                ->get(['id', 'asset_code']);

            foreach ($devices as $device) {
                $newCode = $newPrefix . substr($device->asset_code, strlen($oldPrefix));
                // Only update if the new code doesn't already exist
                $exists = DB::table('devices')
                    ->where('asset_code', $newCode)
                    ->where('id', '!=', $device->id)
                    ->exists();

                if (!$exists) {
                    DB::table('devices')->where('id', $device->id)->update(['asset_code' => $newCode]);
                }
            }
        }
    }

    public function down(): void
    {
        DB::table('asset_types')->where('slug', 'switch')  ->update(['category_code' => 'NET']);
        DB::table('asset_types')->where('slug', 'firewall')->update(['category_code' => 'FWL']);
        DB::table('asset_types')->where('slug', 'router')  ->update(['category_code' => 'RTR']);
        DB::table('asset_types')->where('slug', 'ap')      ->update(['category_code' => 'WAP']);
    }
};
