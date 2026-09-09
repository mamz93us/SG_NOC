<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tags each chunk with the language it was actually authored in.
 *
 * KnowledgeIndexer used to pick ONE locale per article (Arabic if body_ar was
 * filled in, else English) and index only that — so a bilingual article's
 * English text was never embedded at all. Confirmed live: the "Security
 * Policy" article's English body explicitly covers USB/flash drives, but an
 * English employee question about USB drives scored well under the relevance
 * floor against the Arabic-only chunks, because embedding similarity drops
 * noticeably across languages even on the same topic. Indexing both bodies
 * separately means an English question can match an English chunk directly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_knowledge_chunks', function (Blueprint $table) {
            $table->string('locale', 2)->nullable()->after('content_hash');
        });
    }

    public function down(): void
    {
        Schema::table('ai_knowledge_chunks', function (Blueprint $table) {
            $table->dropColumn('locale');
        });
    }
};
