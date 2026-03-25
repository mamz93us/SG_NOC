<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voice_quality_reports', function (Blueprint $table) {
            $table->string('call_id', 120)->nullable()->unique()->after('id');
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
