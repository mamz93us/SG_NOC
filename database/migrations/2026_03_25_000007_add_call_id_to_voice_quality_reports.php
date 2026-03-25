<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voice_quality_reports', function (Blueprint $table) {
            // Add call_id column if it doesn't exist yet
            if (! Schema::hasColumn('voice_quality_reports', 'call_id')) {
                $table->string('call_id', 150)->nullable()->after('id');
            }
        });

        // Drop any solo unique on call_id (wrong — both phones share the same Call-ID)
        // then add the correct composite unique on (call_id, extension) so each
        // side of a call is stored as a separate row but periodic reports are deduped.
        Schema::table('voice_quality_reports', function (Blueprint $table) {
            try { $table->dropUnique('voice_quality_reports_call_id_unique'); } catch (\Throwable) {}
            try { $table->dropIndex('voice_quality_reports_call_id_extension_unique');  } catch (\Throwable) {}

            $table->unique(['call_id', 'extension'], 'vq_call_ext_unique');
        });
    }

    public function down(): void
    {
        Schema::table('voice_quality_reports', function (Blueprint $table) {
            try { $table->dropUnique('vq_call_ext_unique'); } catch (\Throwable) {}
            if (Schema::hasColumn('voice_quality_reports', 'call_id')) {
                $table->dropColumn('call_id');
            }
        });
    }
};
