<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voice_quality_reports', function (Blueprint $table) {
            // SIP Call-ID — used as the unique deduplication key so that
            // multiple RTCP-XR packets for the same call (periodic + final)
            // all update ONE record instead of inserting duplicates.
            $table->string('call_id', 150)->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('voice_quality_reports', function (Blueprint $table) {
            $table->dropUnique(['call_id']);
            $table->dropColumn('call_id');
        });
    }
};
