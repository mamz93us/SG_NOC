<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voice_quality_reports', function (Blueprint $table) {
            // Guard: column may already exist from a prior partial migration
            if (! Schema::hasColumn('voice_quality_reports', 'call_id')) {
                $table->string('call_id', 150)->nullable()->unique()->after('id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('voice_quality_reports', function (Blueprint $table) {
            if (Schema::hasColumn('voice_quality_reports', 'call_id')) {
                $table->dropUnique(['call_id']);
                $table->dropColumn('call_id');
            }
        });
    }
};
