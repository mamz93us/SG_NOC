<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which documents' files the transfer may move, by capture date.
 *
 * 372 GB is a lot to move in one decision. Being able to say "this year first,
 * then work backwards" turns it into several small ones: the years people
 * actually open go to Azure first, and the 2013 history follows when it suits.
 *
 * Stored as plain Y-m-d strings, like transfer_window_start and _end next to
 * them, rather than dates with a cast — the worker compares them against
 * archive_documents.captured_at, which is itself wall clock exactly as ArcMate
 * recorded it, and a timezone conversion on either side would quietly shift the
 * boundary by hours.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('archive_sources', function (Blueprint $table) {
            $table->string('transfer_from', 10)->nullable()->after('transfer_speed_mbps');
            $table->string('transfer_to', 10)->nullable()->after('transfer_from');
        });
    }

    public function down(): void
    {
        Schema::table('archive_sources', function (Blueprint $table) {
            $table->dropColumn(['transfer_from', 'transfer_to']);
        });
    }
};
