<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retrieval unit for the assistant: one heading-sized slice of an article (or,
 * later, an ingested PDF) plus its embedding.
 *
 * No vector column — MySQL 8 has none and a corpus of a few thousand chunks
 * does not justify new infrastructure. `embedding` is 1536 float32s packed
 * with pack('f*', …) into a blob (~6 KB/row); KnowledgeRetriever unpacks and
 * scores cosine similarity in PHP. Revisit only past ~10,000 chunks.
 *
 * Audience columns are a denormalised copy of the parent article's (or
 * document's), so retrieval filters in one query instead of a join per
 * candidate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_knowledge_chunks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('article_id')->nullable()
                ->constrained('ai_knowledge_articles')->cascadeOnDelete();
            $table->foreignId('portal_document_id')->nullable()
                ->constrained('portal_documents')->cascadeOnDelete();

            $table->string('heading', 255)->nullable();
            $table->mediumText('content');
            $table->string('content_hash', 64)->index(); // skip re-embedding unchanged text
            $table->binary('embedding')->nullable();
            $table->unsignedInteger('token_count')->default(0);

            $table->string('audience', 20)->default('all');
            $table->unsignedInteger('audience_branch_id')->nullable()->index();
            $table->unsignedBigInteger('audience_department_id')->nullable()->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_knowledge_chunks');
    }
};
