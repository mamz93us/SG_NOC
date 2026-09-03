<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The full attachment list for a submitted ticket.
 *
 * `attachment_name` / `attachment_size` predate multi-file support and hold
 * only the first file. They stay as-is so the existing submission log renders
 * unchanged; this column carries every file's name, size and type.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('noc_tickets', function (Blueprint $table) {
            $table->json('attachments')->nullable()->after('attachment_size');
        });
    }

    public function down(): void
    {
        Schema::table('noc_tickets', function (Blueprint $table) {
            $table->dropColumn('attachments');
        });
    }
};
