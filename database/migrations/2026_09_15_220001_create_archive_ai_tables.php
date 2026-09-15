<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3: AI over the archive.
 *
 * The fact that shapes all of this: the scans have no text in them. Of 93 files
 * probed across the live archives only two PDFs carried a text layer, and
 * ArcMate's own OCR column came back null. So every word search, every question
 * about a document and every proposed field value rests on pages being READ,
 * and reading a page costs money.
 *
 * Hence three ideas in this schema:
 *
 *  - a page is read ONCE and the text kept (archive_file_texts, already there
 *    from Phase 1), with `text_status` on the file recording how far it got;
 *  - anything that reads at scale is a BATCH with an estimate and a running
 *    cost, so somebody decides to spend before it is spent;
 *  - anything AI proposes about a document is a PROPOSAL until a person
 *    approves it — nothing AI says edits an invoice on its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('archive_files', function (Blueprint $table) {
            // none | pending | done | failed | unreadable
            $table->string('text_status', 20)->default('none')->after('page_count');
            $table->unsignedSmallInteger('pages_read')->default(0)->after('text_status');
            $table->timestamp('text_read_at')->nullable()->after('pages_read');

            $table->index(['archive_id', 'text_status']);
        });

        // One row. The budget is deliberately global rather than per archive:
        // "how much may AI spend this month" is a question with one answer, and
        // splitting it per archive is how a cap gets quietly exceeded.
        Schema::create('archive_ai_settings', function (Blueprint $table) {
            $table->id();
            $table->decimal('monthly_budget_usd', 10, 2)->default(0);
            $table->unsignedInteger('per_user_daily_pages')->default(200);
            // What a page costs, so the estimates shown before a batch are in
            // money rather than tokens. Keyed in, not fetched — the same reason
            // the exchange rates are: a figure that moves between two runs
            // cannot be reconciled.
            $table->decimal('page_read_cost_usd', 8, 5)->default(0.01);
            $table->timestamps();
        });

        // Reading history, or filling gaps in it. Both are "spend money across
        // many documents", so both are a batch with an estimate, a running
        // total and a pause.
        Schema::create('archive_ai_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('archive_id')->constrained('archives')->cascadeOnDelete();
            // read | fill
            $table->string('type', 20);
            $table->json('filters')->nullable();
            $table->json('field_ids')->nullable();
            $table->unsignedBigInteger('pages_total')->default(0);
            $table->unsignedBigInteger('pages_done')->default(0);
            $table->unsignedInteger('documents_total')->default(0);
            $table->unsignedInteger('documents_done')->default(0);
            $table->decimal('estimated_cost_usd', 10, 2)->default(0);
            $table->decimal('cost_so_far_usd', 10, 4)->default(0);
            // pending | running | paused | done | failed | over_budget
            $table->string('status', 20)->default('pending');
            $table->text('error')->nullable();
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'id']);
        });

        // What AI thinks a field should say. Never a value until a person says so.
        Schema::create('archive_ai_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('archive_ai_batch_id')->nullable()->constrained('archive_ai_batches')->nullOnDelete();
            $table->foreignId('archive_document_id')->constrained('archive_documents')->cascadeOnDelete();
            $table->foreignId('archive_field_id')->constrained('archive_fields')->cascadeOnDelete();
            $table->string('value', 250)->nullable();
            // 0–100. The page it was read off, so a reviewer can check rather
            // than take it on trust — the whole point of a review queue.
            $table->unsignedTinyInteger('confidence')->nullable();
            $table->unsignedSmallInteger('evidence_page')->nullable();
            // pending | approved | edited | rejected
            $table->string('status', 20)->default('pending');
            $table->string('approved_value', 250)->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['archive_document_id', 'archive_field_id'], 'archive_ai_proposals_document_field_unique');
            $table->index(['status', 'confidence']);
        });

        // Every call that cost something, so the budget is measured rather than
        // assumed and a runaway feature can be seen rather than discovered on a
        // bill.
        Schema::create('archive_ai_usage', function (Blueprint $table) {
            $table->id();
            $table->date('day');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('archive_id')->nullable();
            // read | extract | ask_document | ask_archive | fill
            $table->string('feature', 30);
            $table->unsignedInteger('pages')->default(0);
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->decimal('cost_usd', 10, 5)->default(0);
            $table->timestamps();

            $table->index(['day', 'feature']);
            $table->index(['user_id', 'day']);
        });

        // A conversation that used an archive tool has seen documents, so
        // AI ▸ Conversations must hide it from anyone without archive AI —
        // exactly the treatment candidate data already gets.
        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->boolean('contains_archive_data')->default(false)->after('contains_candidate_data');
        });
    }

    public function down(): void
    {
        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->dropColumn('contains_archive_data');
        });

        Schema::dropIfExists('archive_ai_usage');
        Schema::dropIfExists('archive_ai_proposals');
        Schema::dropIfExists('archive_ai_batches');
        Schema::dropIfExists('archive_ai_settings');

        Schema::table('archive_files', function (Blueprint $table) {
            $table->dropIndex(['archive_id', 'text_status']);
            $table->dropColumn(['text_status', 'pages_read', 'text_read_at']);
        });
    }
};
