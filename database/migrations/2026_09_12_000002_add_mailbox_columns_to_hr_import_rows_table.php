<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the Oracle row's email address is that person's own mailbox.
 *
 * An address in the EMAIL column is not proof of one: mail-less staff are
 * listed under their MANAGER's address, and some rows carry a personal gmail.
 * Decided while the file is parsed — "shared" can only be seen across the whole
 * file — and kept so the review page can explain itself and the bulk action can
 * pick its rows without re-reading the spreadsheet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_import_rows', function (Blueprint $table) {
            $table->boolean('own_mailbox')->default(true)->after('email');
            $table->string('mailbox_reason', 20)->nullable()->after('own_mailbox');
        });
    }

    public function down(): void
    {
        Schema::table('hr_import_rows', function (Blueprint $table) {
            $table->dropColumn(['own_mailbox', 'mailbox_reason']);
        });
    }
};
