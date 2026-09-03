<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Embedded training videos in the employee document library.
 *
 * A third source alongside an upload and a plain link: the URL is a normal
 * YouTube watch/share/shorts link, and PortalDocument::youtubeId() reduces it to
 * the id the embed player needs. Stored as the author pasted it so the original
 * stays checkable, rather than as a bare id nobody can verify by eye.
 *
 * Videos are not uploaded. A 200 MB MP4 on the NOC's disk, streamed by PHP to
 * the whole company at 9am, is the wrong trade when the content already sits on
 * a CDN built for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portal_documents', function (Blueprint $table) {
            $table->string('video_url', 500)->nullable()->after('link_url');
        });
    }

    public function down(): void
    {
        Schema::table('portal_documents', function (Blueprint $table) {
            $table->dropColumn('video_url');
        });
    }
};
