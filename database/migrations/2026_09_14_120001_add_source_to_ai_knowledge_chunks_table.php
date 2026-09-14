<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where in the original PDF a chunk of an imported article comes from.
 *
 * Asked where an answer came from, the assistant cited "Article 198" of the
 * imported labor law — correctly translated, and nowhere in the PDF, which
 * spells its article numbers out in Arabic words ("المادة الثامنة والتسعون بعد
 * المائة"). A citation has to be something the employee can find: the page
 * the chunk starts on, and the heading as the original writes it when the
 * chunk's own heading is a translation.
 *
 * Both null for hand-written articles. KnowledgeIndexer fills them from the
 * import's pages (PdfSourceLocator), for unchanged chunks too, without
 * re-embedding.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_knowledge_chunks', function (Blueprint $table) {
            $table->unsignedSmallInteger('source_page')->nullable()->after('locale');
            $table->string('source_heading', 255)->nullable()->after('source_page');
        });
    }

    public function down(): void
    {
        Schema::table('ai_knowledge_chunks', function (Blueprint $table) {
            $table->dropColumn(['source_page', 'source_heading']);
        });
    }
};
