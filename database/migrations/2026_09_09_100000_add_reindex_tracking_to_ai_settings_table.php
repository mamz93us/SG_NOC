<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks the last "Reindex All" run so the AI Assistant Knowledge page can
 * answer "how do I know it finished?" — started_at with no finished_at (or an
 * older finished_at) means the queued job is still running; finished_at with
 * a result means it's done, and how many articles failed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_settings', function (Blueprint $table) {
            $table->timestamp('last_reindex_started_at')->nullable()->after('ticket_drafting_enabled');
            $table->timestamp('last_reindex_finished_at')->nullable()->after('last_reindex_started_at');
            $table->json('last_reindex_result')->nullable()->after('last_reindex_finished_at');
        });
    }

    public function down(): void
    {
        Schema::table('ai_settings', function (Blueprint $table) {
            $table->dropColumn(['last_reindex_started_at', 'last_reindex_finished_at', 'last_reindex_result']);
        });
    }
};
