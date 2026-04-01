<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill asset_code for printer devices that were created without one.
 * Uses the SG-PRN-XXXXXX sequence, picking up after the highest existing code.
 */
return new class extends Migration
{
    public function up(): void
    {
        $base = DB::table('settings')->value('itam_asset_prefix') ?? 'SG';
        $prefix = "{$base}-PRN-";

        // Find the current max sequence for this prefix
        $last = DB::table('devices')
            ->where('asset_code', 'like', $prefix . '%')
            ->orderByRaw('LENGTH(asset_code) DESC, asset_code DESC')
            ->value('asset_code');

        $seq = $last ? ((int) ltrim(substr($last, strlen($prefix)), '0') + 1) : 1;

        // Find printer devices with no asset_code
        $printers = DB::table('devices')
            ->where('type', 'printer')
            ->whereNull('asset_code')
            ->orderBy('id')
            ->get(['id']);

        foreach ($printers as $device) {
            $code = $prefix . str_pad($seq, 6, '0', STR_PAD_LEFT);

            // Safety: skip if somehow already taken
            while (DB::table('devices')->where('asset_code', $code)->exists()) {
                $seq++;
                $code = $prefix . str_pad($seq, 6, '0', STR_PAD_LEFT);
            }

            DB::table('devices')->where('id', $device->id)->update(['asset_code' => $code]);
            $seq++;
        }
    }

    public function down(): void
    {
        // Non-destructive — we cannot tell which codes were backfilled vs originally set
    }
};
