<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4: capture. Something scanned arrives, and becomes a document.
 *
 * An inbox item is a file that exists but is not filed yet — uploaded from a PC,
 * dropped in a scan folder, e-mailed by an MFP, or sent by the browser scan
 * agent. It is deliberately NOT an archive_documents row: a document has an
 * archive and index values, and an arriving scan has neither until somebody (or
 * AI, proposing) says what it is.
 *
 * The bytes go straight to the `azure_archive` disk under `inbox/`, the same
 * container the filed documents live in. Two consequences, both on purpose:
 *
 *  - filing is a MOVE within one container, not a copy between two;
 *  - nothing is written to a local disk, which is what avoids the trap the
 *    knowledge-PDF import hit — PHP-FPM writes as www-data, the scheduler runs
 *    as azureuser, and Flysystem makes private directories 0700, so the worker
 *    could not open what the upload had just stored.
 *
 * `ai_suggestions` holds what FieldExtractor proposed for the filing form. It is
 * a suggestion on a form, not an archive_ai_proposals row: nothing is being
 * proposed ABOUT an existing document, and the person filing it is looking at
 * the scan while they accept or correct it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('archive_inbox_items', function (Blueprint $table) {
            $table->id();

            // Whose inbox. A personal one (somebody uploaded or scanned it) or an
            // archive's shared one (a scan address or folder belonging to a
            // department). Exactly one is normally set; neither being set would
            // be an item nobody can see, so the sweep refuses to create that.
            $table->unsignedBigInteger('user_id')->nullable();
            $table->foreignId('archive_id')->nullable()->constrained('archives')->nullOnDelete();

            // upload | folder | email | agent
            $table->string('source', 20)->default('upload');
            // Where it came from in that source's own terms: the e-mail sender,
            // the scan account, the PC name. Kept because a misfiled scan is
            // usually traced by asking who sent it.
            $table->string('source_detail', 255)->nullable();

            // Always on the azure_archive disk, under inbox/. Stored as a column
            // rather than assumed so a later disk change does not orphan a row.
            $table->string('disk', 30)->default('azure_archive');
            $table->string('path', 500);
            $table->string('original_name')->nullable();
            $table->string('mime', 120)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->unsignedSmallInteger('pages')->nullable();
            // The same file twice — an MFP that retries, a folder swept before
            // the previous sweep finished — is one item, not two.
            $table->char('sha256', 64)->nullable();

            // waiting | filed | discarded
            $table->string('status', 20)->default('waiting');

            // none | queued | reading | done | failed
            //
            // Separate from `status` because they answer different questions:
            // status is whether a person has dealt with it, ai_status is whether
            // the machine has finished looking at it. An item can be filed before
            // AI ever gets to it, and often should be.
            $table->string('ai_status', 20)->default('none');
            $table->json('ai_suggestions')->nullable();
            // Which archive AI thinks this belongs in, with how sure it is, for
            // when the person filing it has several to choose from.
            $table->unsignedBigInteger('ai_archive_id')->nullable();
            $table->unsignedTinyInteger('ai_confidence')->nullable();
            $table->unsignedSmallInteger('ai_attempts')->default(0);

            $table->json('meta')->nullable();
            $table->text('error')->nullable();

            // What it became, kept after filing so the trail from an arriving
            // scan to its document survives.
            $table->unsignedBigInteger('archive_document_id')->nullable();
            $table->unsignedBigInteger('filed_by_user_id')->nullable();
            $table->timestamp('filed_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            // The two queries this table exists to answer: "my inbox" and "what
            // does the worker still have to read".
            $table->index(['user_id', 'status']);
            $table->index(['archive_id', 'status']);
            $table->index(['status', 'ai_status']);
            // An index, NOT a unique constraint. Recognising the same scan twice
            // is a decision the capture path makes by looking (and skipping with
            // a message), the way the knowledge-PDF import does. As a constraint
            // it would be wrong three ways: two discarded duplicates would
            // collide, MySQL counts NULLs as distinct so anything without a hash
            // would not collide at all, and a genuine duplicate would surface as
            // a database exception in front of whoever was uploading.
            $table->index('sha256');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('archive_inbox_items');
    }
};
