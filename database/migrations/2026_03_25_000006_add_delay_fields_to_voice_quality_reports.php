<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voice_quality_reports', function (Blueprint $table) {
            // Symmetric One-Way Delay (SOWD) and End-System Delay (ESD) from Delay: line
            $table->integer('sowd')->nullable()->comment('Symmetric One-Way Delay ms')->after('rtt');
            $table->integer('esd')->nullable()->comment('End System Delay ms')->after('sowd');
            // Raw packet lost count (separate from NLR rate)
            $table->integer('packets_lost')->nullable()->after('burst_loss');
        });
    }

    public function down(): void
    {
        Schema::table('voice_quality_reports', function (Blueprint $table) {
            $table->dropColumn(['sowd', 'esd', 'packets_lost']);
        });
    }
};
