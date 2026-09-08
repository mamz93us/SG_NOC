<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-authored knowledge for the AI IT Assistant to search and cite.
 *
 * Day one has no PDF text extracted yet (`portal_documents` stores files, not
 * content — see AI_ASSISTANT plan Phase 5), so these hand-written articles are
 * the whole corpus at launch. `source_document_id` lets a later PDF-ingestion
 * pass attribute chunks back to the library document they came from without a
 * second content table.
 *
 * Audience mirrors `portal_documents` exactly (same authors, same targeting
 * model) — see that migration for why audience_branch_id is unsignedInteger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_knowledge_articles', function (Blueprint $table) {
            $table->id();

            $table->string('title', 200);
            $table->string('title_ar', 200)->nullable();
            $table->longText('body'); // markdown
            $table->longText('body_ar')->nullable();

            $table->string('category', 50)->nullable();
            $table->json('tags')->nullable();

            $table->string('audience', 20)->default('all'); // all | branch | department
            $table->unsignedInteger('audience_branch_id')->nullable()->index();
            $table->unsignedBigInteger('audience_department_id')->nullable()->index();

            $table->boolean('is_published')->default(false);

            $table->foreignId('source_document_id')->nullable()
                ->constrained('portal_documents')->nullOnDelete();

            $table->unsignedBigInteger('created_by')->nullable()->index();

            $table->timestamps();

            $table->index(['is_published', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_knowledge_articles');
    }
};
