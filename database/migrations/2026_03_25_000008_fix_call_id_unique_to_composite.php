<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Drop the simple unique index on call_id alone (if it exists).
        // Both phones in a call send the same SIP Call-ID, so we need to
        // allow (call_id, extension) as the composite dedup key instead.
        try {
            Schema::table('voice_quality_reports', function (Blueprint $table) {
                $table->dropUnique(['call_id']);
            });
        } catch (\Throwable) {
            // Index may not exist — safe to ignore
        }

        // Add composite unique index: same call + same extension = one record.
        // This means 1610→1213 and 1213→1610 both get stored for the same call.
        // Only apply when call_id is not null (nulls are always allowed through).
        if (! $this->indexExists('voice_quality_reports', 'vq_call_ext_unique')) {
            Schema::table('voice_quality_reports', function (Blueprint $table) {
                $table->unique(['call_id', 'extension'], 'vq_call_ext_unique');
            });
        }
    }

    public function down(): void
    {
        try {
            Schema::table('voice_quality_reports', function (Blueprint $table) {
                $table->dropUnique('vq_call_ext_unique');
            });
        } catch (\Throwable) {}

        Schema::table('voice_quality_reports', function (Blueprint $table) {
            $table->unique('call_id');
        });
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $indexes = DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName]);
        return count($indexes) > 0;
    }
};
