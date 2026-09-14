<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Turns ai_knowledge_gaps from a list into a loop: a question the assistant
 * could not answer is answered by a person, or found covered by an article
 * published since, and closes.
 *
 * Until now nothing ever set resolved_at. All 25 gaps on NOC2 were open,
 * "security policy" among them asked four ways and USB drives five, after
 * articles covering both had been published.
 *
 *  - source: how it was caught. no_results (the search found nothing),
 *    not_answered (results came back and none answered; the assistant says so
 *    through report_knowledge_gap), not_helpful (an employee's rating).
 *  - embedding: the question's, packed like ai_knowledge_chunks.embedding, so
 *    wordings of one question group together and a new article is compared
 *    without embedding the question again.
 *  - group_id: the id of the most-asked wording in its group; null alone.
 *  - resolution / article_id / resolved_by: answered (a person wrote the
 *    article), covered (an article published since answers it), dismissed.
 *  - best_score / best_article_id / checked_at: the closest article at the
 *    last check, offered as "possibly answered" below the auto-close bar.
 *
 * No foreign keys: an article deleted from under a gap is noticed by
 * ai:knowledge-gaps, which reopens the gap, instead of being nulled silently.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_knowledge_gaps', function (Blueprint $table) {
            $table->string('source', 20)->default('no_results')->after('locale');
            $table->binary('embedding')->nullable()->after('source');
            $table->unsignedBigInteger('last_conversation_id')->nullable()->after('embedding');
            $table->unsignedBigInteger('group_id')->nullable()->index()->after('last_conversation_id');
            $table->string('resolution', 20)->nullable()->after('resolved_at');
            $table->unsignedBigInteger('article_id')->nullable()->index()->after('resolution');
            $table->unsignedBigInteger('resolved_by')->nullable()->after('article_id');
            $table->decimal('best_score', 4, 3)->nullable()->after('resolved_by');
            $table->unsignedBigInteger('best_article_id')->nullable()->after('best_score');
            $table->timestamp('checked_at')->nullable()->after('best_article_id');
        });
    }

    public function down(): void
    {
        Schema::table('ai_knowledge_gaps', function (Blueprint $table) {
            $table->dropIndex(['group_id']);
            $table->dropIndex(['article_id']);
            $table->dropColumn([
                'source', 'embedding', 'last_conversation_id', 'group_id', 'resolution',
                'article_id', 'resolved_by', 'best_score', 'best_article_id', 'checked_at',
            ]);
        });
    }
};
