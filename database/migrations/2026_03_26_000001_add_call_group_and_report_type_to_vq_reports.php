<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voice_quality_reports', function (Blueprint $table) {
            // Links both sides of the same call (Grandstream UCM B2BUA uses different
            // Call-IDs per leg, so we can't use call_id alone to join them).
            // Key = md5( sorted(ext,remote_ext) + ':' + call_start_unix )
            if (! Schema::hasColumn('voice_quality_reports', 'call_group_key')) {
                $table->string('call_group_key', 32)->nullable()->index()->after('call_id');
            }
            // VQIntervalReport (periodic) vs VQSessionReport (final summary at hangup)
            if (! Schema::hasColumn('voice_quality_reports', 'report_type')) {
                $table->string('report_type', 30)->nullable()->after('call_group_key');
            }
        });
    }

    public function down(): void
    {
        Schema::table('voice_quality_reports', function (Blueprint $table) {
            $table->dropColumn(array_filter(['call_group_key', 'report_type'], fn($c) =>
                Schema::hasColumn('voice_quality_reports', $c)
            ));
        });
    }
};
