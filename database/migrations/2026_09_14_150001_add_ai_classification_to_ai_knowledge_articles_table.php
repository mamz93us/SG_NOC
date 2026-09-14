<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Category and tags filled in by the AI (Services\Ai\ArticleClassifier).
 *
 * ai_classify is the queue `ai:classify-articles` works through: 'missing'
 * fills in only an empty category or empty tags, 'replace' chooses both
 * again. An article created without a category or tags is queued as
 * 'missing'; the Knowledge page queues many at once. A reply that could not be
 * used is kept in ai_classify_error, and the article leaves the queue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_knowledge_articles', function (Blueprint $table) {
            $table->string('ai_classify', 10)->nullable()->after('tags')->index();
            $table->timestamp('ai_classified_at')->nullable()->after('ai_classify');
            $table->string('ai_classify_error', 500)->nullable()->after('ai_classified_at');
        });
    }

    public function down(): void
    {
        Schema::table('ai_knowledge_articles', function (Blueprint $table) {
            $table->dropIndex(['ai_classify']);
            $table->dropColumn(['ai_classify', 'ai_classified_at', 'ai_classify_error']);
        });
    }
};
