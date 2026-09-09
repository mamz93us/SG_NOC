<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Makes KnowledgeRetriever::RELEVANCE_FLOOR admin-tunable instead of a hard
 * PHP constant that needs a deploy to change.
 *
 * Why this needs to be tunable at all: measured directly against production
 * data (real published articles, real Azure OpenAI text-embedding-3-small
 * embeddings, real short employee-style queries like "security policy" and
 * "how to apply for vacation"), the correct chunk's cosine score landed
 * around 0.43-0.46 — below the 0.5 floor already in code — while genuinely
 * unrelated chunks scored under 0.19. There is a real, wide gap between
 * "matches" and "noise" here, but exactly where to draw the line depends on
 * the embedding model and the house style of how employees actually phrase
 * questions, which nobody can nail from first principles. 0.40 clears both
 * of the above real queries with margin to spare above the noise ceiling.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_settings', function (Blueprint $table) {
            $table->decimal('knowledge_match_threshold', 3, 2)->default(0.40)->after('embedding_deployment');
        });
    }

    public function down(): void
    {
        Schema::table('ai_settings', function (Blueprint $table) {
            $table->dropColumn('knowledge_match_threshold');
        });
    }
};
