<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A PDF on its way into the AI Assistant's knowledge base.
 *
 * `ai:import-pdfs` has gpt-4o read an image of each page, in its own language
 * and in English, then writes one ordinary AiKnowledgeArticle from the result
 * — English body, plus the Arabic original when the document is Arabic — which
 * KnowledgeIndexer indexes like any hand-written article.
 *
 * `pages` keeps every finished page as it lands, so an import stopped by the
 * worker's time budget, a deploy or Azure throttling resumes at the next page
 * instead of paying to read the document again. It is the whole document, so
 * the model is kept out of automatic auditing (config/audit.php).
 *
 * `file_hash` stops the same PDF being uploaded, and paid for, twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_knowledge_imports', function (Blueprint $table) {
            $table->id();

            $table->string('file_path', 255); // private disk
            $table->string('file_name', 255); // as uploaded — display only
            $table->unsignedBigInteger('file_size')->default(0);
            $table->string('file_hash', 64)->index();

            $table->string('status', 20)->default('queued')->index(); // queued | processing | done | failed
            $table->unsignedInteger('page_count')->nullable();
            $table->unsignedInteger('pages_done')->default(0);
            $table->longText('pages')->nullable();
            $table->string('source_language', 8)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0); // failures in a row on the current page
            $table->text('error')->nullable();
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);

            // What the article is created with — the same shape as ai_knowledge_articles.
            $table->string('category', 50)->nullable();
            $table->string('audience', 20)->default('all');
            $table->unsignedInteger('audience_branch_id')->nullable();
            $table->unsignedBigInteger('audience_department_id')->nullable();
            $table->boolean('publish')->default(false);

            $table->foreignId('article_id')->nullable()
                ->constrained('ai_knowledge_articles')->nullOnDelete();

            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_knowledge_imports');
    }
};
