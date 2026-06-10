<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Discovery scans that outlived the queue's retry_after got handed to a
     * second worker mid-scan, inserting every probed host twice. Remove the
     * duplicate rows — keeping the imported copy where one exists, else the
     * oldest — then make (discovery_scan_id, ip_address) unique so a scan
     * can never list the same host twice again.
     */
    public function up(): void
    {
        $dupes = DB::table('discovery_results')
            ->select('discovery_scan_id', 'ip_address', DB::raw('count(*) as c'))
            ->groupBy('discovery_scan_id', 'ip_address')
            ->having('c', '>', 1)
            ->get();

        foreach ($dupes as $group) {
            $ids = DB::table('discovery_results')
                ->where('discovery_scan_id', $group->discovery_scan_id)
                ->where('ip_address', $group->ip_address)
                ->orderByDesc('already_imported')
                ->orderBy('id')
                ->pluck('id');

            DB::table('discovery_results')->whereIn('id', $ids->skip(1)->all())->delete();
        }

        Schema::table('discovery_results', function (Blueprint $table) {
            // Add the unique first — MySQL refuses to drop the only index
            // backing the discovery_scan_id FK.
            $table->unique(['discovery_scan_id', 'ip_address']);
            $table->dropIndex(['discovery_scan_id', 'ip_address']);
        });

        // Backfill the one device the pre-source_id import created with the
        // empty-string default, so it matches the discovery-<ip> convention.
        DB::table('devices')
            ->where('source_id', '')
            ->whereNotNull('ip_address')
            ->where('ip_address', '<>', '')
            ->update(['source_id' => DB::raw("concat('discovery-', ip_address)")]);
    }

    public function down(): void
    {
        Schema::table('discovery_results', function (Blueprint $table) {
            $table->index(['discovery_scan_id', 'ip_address']);
            $table->dropUnique(['discovery_scan_id', 'ip_address']);
        });
    }
};
