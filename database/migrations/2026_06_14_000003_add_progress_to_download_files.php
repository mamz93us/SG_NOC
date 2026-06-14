<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live progress for URL fetches. The downloads:fetch-remote worker writes these
 * as Guzzle streams the file in, so the index page can show a real-time bar
 * (downloaded / total, %, speed, ETA) instead of a bare "Fetching…" badge.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('download_files', function (Blueprint $table) {
            $table->unsignedBigInteger('download_total_bytes')->nullable()->after('size');
            $table->unsignedBigInteger('download_received_bytes')->default(0)->after('download_total_bytes');
        });
    }

    public function down(): void
    {
        Schema::table('download_files', function (Blueprint $table) {
            $table->dropColumn(['download_total_bytes', 'download_received_bytes']);
        });
    }
};
