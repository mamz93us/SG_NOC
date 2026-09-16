<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stop a batch after this many documents.
 *
 * Until now a batch was bounded only by its date range, which is the wrong
 * control for the commonest thing anybody wants to do first: try it on a handful
 * of documents and see whether the values it proposes are any good. With 513,000
 * documents in SPS Invoices, "just try it" and "read the entire archive" were the
 * same button.
 *
 * Counted in DOCUMENTS rather than pages or money, because that is the unit the
 * person is thinking in — "the last hundred invoices" — and because the estimate
 * shown before starting is already in both of the others.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('archive_ai_batches', function (Blueprint $table) {
            // Null means no limit: the date range alone decides, as before.
            $table->unsignedInteger('max_documents')->nullable()->after('documents_total');

            // The document being worked on, so `documents_done` can count
            // DOCUMENTS rather than turns at one. A read batch reads ten pages
            // of a document at a time and then picks the same document again,
            // so without this a 30-page contract counts as three and a limit of
            // a hundred documents stops at thirty-odd.
            $table->unsignedBigInteger('current_document_id')->nullable()->after('documents_done');
        });
    }

    public function down(): void
    {
        Schema::table('archive_ai_batches', function (Blueprint $table) {
            $table->dropColumn(['max_documents', 'current_document_id']);
        });
    }
};
