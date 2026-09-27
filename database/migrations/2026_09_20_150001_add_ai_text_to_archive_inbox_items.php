<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keep the text AI read off a scan, so it is not paid for twice.
 *
 * A scan in the inbox has no `archive_files` row to hang text on, so
 * PageReader::readUnfiledPage() handed its text back to the caller and stored
 * nothing. The caller used it to fill in the filing form, kept the SUGGESTIONS,
 * and threw the text away. The filed document then had `text_status = none`, so
 * the first question asked about it — or the first read batch covering it — read
 * and charged for exactly the same pages a second time.
 *
 * Held per page rather than as one blob, because that is how it has to be
 * written into `archive_file_texts` on the other side: one row per page, each
 * with the source it came from. A page read free off the PDF's own text layer
 * must not be recorded as one AI was paid to look at.
 *
 * Cleared the moment it is carried across, so the text lives in one place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('archive_inbox_items', function (Blueprint $table) {
            // {"1": {"text": "...", "source": "pdf_text"}, "2": {...}}
            $table->json('ai_text')->nullable()->after('ai_suggestions');
        });
    }

    public function down(): void
    {
        Schema::table('archive_inbox_items', function (Blueprint $table) {
            $table->dropColumn('ai_text');
        });
    }
};
